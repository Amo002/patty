<?php

use App\Enums\MovementReason;
use App\Enums\Unit;
use App\Exceptions\Domain\IdempotencyConflict;
use App\Http\Requests\V1\StoreSaleRequest;
use App\Models\DeliveryLine;
use App\Models\DocumentSequence;
use App\Models\Ingredient;
use App\Models\MenuItem;
use App\Models\RecipeLine;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\StockMovement;
use App\Services\DocumentNumber;
use App\Services\SaleService;
use App\Services\StockLedger;
use Carbon\CarbonInterface;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Spatie\Activitylog\Models\Activity;

/*
| E25 and E26 (PTY-9). Classic Burger = beef 150 g, bun 1, cheese 20 g.
| Every JSON response is checked with assertNoIntegerIds (D-031).
*/

beforeEach(function () {
    // Capture the pos and stock channels in memory instead of writing storage/logs.
    config([
        'logging.channels.pos' => ['driver' => 'monolog', 'handler' => TestHandler::class],
        'logging.channels.stock' => ['driver' => 'monolog', 'handler' => TestHandler::class],
    ]);
});

function saleLog(string $channel): array
{
    return Log::channel($channel)->getLogger()->getHandlers()[0]->getRecords();
}

/**
 * Classic Burger (beef 150, bun 1, cheese 20), Double Patty (beef 300, bun 1, cheese 40, sauce 20)
 * and Fries (potato 200, sauce 10). Menu items are created in that order, so sorting by menu item id
 * puts Classic first, then Double, then Fries. Fries and Double stock is generous: only beef, bun
 * and cheese take the three arguments.
 *
 * @return array{beef: Ingredient, bun: Ingredient, cheese: Ingredient, sauce: Ingredient, potato: Ingredient, burger: MenuItem, double: MenuItem, fries: MenuItem}
 */
function saleSetup(int $beef = 1000, int $bun = 10, int $cheese = 30): array
{
    $i = [
        'beef' => Ingredient::factory()->create(['name' => 'Beef', 'unit' => Unit::Gram]),
        'bun' => Ingredient::factory()->create(['name' => 'Bun', 'unit' => Unit::Piece]),
        'cheese' => Ingredient::factory()->create(['name' => 'Cheese', 'unit' => Unit::Gram]),
        'sauce' => Ingredient::factory()->create(['name' => 'Burger sauce', 'unit' => Unit::Gram]),
        'potato' => Ingredient::factory()->create(['name' => 'Potatoes', 'unit' => Unit::Gram]),
    ];

    $i['burger'] = MenuItem::factory()->create(['name' => 'Classic Burger']);
    $i['double'] = MenuItem::factory()->create(['name' => 'Double Patty']);
    $i['fries'] = MenuItem::factory()->create(['name' => 'Fries']);

    $recipes = [
        'burger' => [['beef', 150], ['bun', 1], ['cheese', 20]],
        'double' => [['beef', 300], ['bun', 1], ['cheese', 40], ['sauce', 20]],
        'fries' => [['potato', 200], ['sauce', 10]],
    ];
    foreach ($recipes as $item => $lines) {
        foreach ($lines as [$key, $qty]) {
            RecipeLine::factory()->create(['menu_item_id' => $i[$item]->id, 'ingredient_id' => $i[$key]->id, 'quantity' => $qty]);
        }
    }

    // Stock goes in through the ledger, the only writer, as a delivery would.
    $ledger = app(StockLedger::class);
    foreach ([['beef', $beef], ['bun', $bun], ['cheese', $cheese], ['sauce', 100000], ['potato', 100000]] as [$key, $qty]) {
        $ledger->record($i[$key], $qty, MovementReason::Delivery, DeliveryLine::factory()->create());
    }

    return $i;
}

function onHandOf(Ingredient $ingredient): int
{
    return app(StockLedger::class)->onHand($ingredient);
}

/** A request line for a menu item. */
function ticketLine(MenuItem $item, int $quantity): array
{
    return ['menu_item_id' => $item->ulid, 'quantity' => $quantity];
}

/**
 * Datasets are built before the database exists, so they name items by key ('burger') and are
 * resolved here. A key not in $i is sent as given, which is how bad ids and types are tested;
 * a null quantity or item is left out of the line.
 *
 * @param  list<array{0: string|int|null, 1?: mixed}>  $spec  [item key, quantity] per line
 */
function specLines(array $i, array $spec): array
{
    return array_map(function (array $row) use ($i) {
        $line = [];
        if ($row[0] !== null) {
            $line['menu_item_id'] = isset($i[$row[0]]) ? $i[$row[0]]->ulid : $row[0];
        }
        if (($row[1] ?? null) !== null) {
            $line['quantity'] = $row[1];
        }

        return $line;
    }, $spec);
}

/** The service's form of a ticket line. */
function serviceLine(MenuItem $item, int $quantity): array
{
    return ['item' => $item, 'quantity' => $quantity];
}

/** Posts a ticket; with no `lines` override it is 2 x Classic Burger. */
function postSale(array $i, array $overrides = [])
{
    return test()->postJson('/api/v1/sales', array_merge([
        'lines' => [ticketLine($i['burger'], 2)],
    ], $overrides));
}

/** Sale movements in the order they were written, as [ingredient name, delta]. */
function saleMovements(): array
{
    return StockMovement::with('ingredient')->where('reason', 'sale')->orderBy('id')->get()
        ->map(fn ($m) => [$m->ingredient->name, $m->quantity_delta])->all();
}

it('deducts the recipe times the quantity as three sale movements (T1)', function () {
    $i = saleSetup(1000, 1000, 1000);

    $response = postSale($i)->assertCreated()
        ->assertJsonPath('data.replayed', false)
        ->assertJsonPath('data.lines.0.quantity', 2)
        ->assertJsonPath('data.lines.0.menu_item.name', 'Classic Burger')
        ->assertJsonPath('data.item_count', 1)
        ->assertJsonPath('data.units', 2);
    expect($response->json())->assertNoIntegerIds()
        ->and($response->json('data'))->not->toHaveKeys(['menu_item', 'quantity']);

    $sale = Sale::firstOrFail();
    expect($response->json('data.id'))->toBe($sale->ulid)
        ->and($response->json('data.number'))->toBe('SALE-'.now('UTC')->year.'-000001');

    // D-048: the movements reference the sale LINE, not the sale.
    $line = SaleLine::firstOrFail();
    $movements = StockMovement::where('reference_type', 'sale_line')->where('reference_id', $line->id)->get();
    expect($movements)->toHaveCount(3)
        ->and($movements->every(fn ($m) => $m->reason === MovementReason::Sale))->toBeTrue()
        ->and($movements->pluck('quantity_delta', 'ingredient_id')->all())->toBe([
            $i['beef']->id => -300,
            $i['bun']->id => -2,
            $i['cheese']->id => -40,
        ]);

    expect(collect($response->json('data.deductions'))->pluck('quantity', 'ingredient.name')->all())
        ->toBe(['Beef' => 300, 'Bun' => 2, 'Cheese' => 40]);
});

it('records the worked ticket: Classic x2 + Double Patty x1 as seven movements in line then recipe order (D-048)', function () {
    $i = saleSetup(3450, 100, 1000);

    $response = postSale($i, ['lines' => [ticketLine($i['burger'], 2), ticketLine($i['double'], 1)]])->assertCreated();
    expect($response->json())->assertNoIntegerIds();

    // One movement per (sale line, recipe line), never summed in the ledger.
    expect(saleMovements())->toBe([
        ['Beef', -300], ['Bun', -2], ['Cheese', -40],
        ['Beef', -300], ['Bun', -1], ['Cheese', -40], ['Burger sauce', -20],
    ]);

    // The first three belong to the Classic line, the last four to the Double Patty line.
    $owners = StockMovement::where('reason', 'sale')->orderBy('id')->get()->map(fn ($m) => [$m->reference_type, $m->reference->menuItem->name, $m->reference->quantity])->all();
    expect($owners)->toBe([
        ...array_fill(0, 3, ['sale_line', 'Classic Burger', 2]),
        ...array_fill(0, 4, ['sale_line', 'Double Patty', 1]),
    ]);

    // On hand, computed by the ledger itself: 3450 - 600 and so on.
    expect(onHandOf($i['beef']))->toBe(2850)
        ->and(onHandOf($i['bun']))->toBe(97)
        ->and(onHandOf($i['cheese']))->toBe(920)
        ->and(onHandOf($i['sauce']))->toBe(100000 - 20);

    expect($response->json('data.item_count'))->toBe(2)
        ->and($response->json('data.units'))->toBe(3)
        ->and(collect($response->json('data.lines'))->map(fn ($l) => [$l['menu_item']['name'], $l['quantity']])->all())
        ->toBe([['Classic Burger', 2], ['Double Patty', 1]]);

    // Deductions are one row per ingredient, in the order each first moved.
    expect(collect($response->json('data.deductions'))->map(fn ($d) => [$d['ingredient']['name'], $d['quantity'], $d['on_hand_after']])->all())
        ->toBe([['Beef', 600, 2850], ['Bun', 3, 97], ['Cheese', 80, 920], ['Burger sauce', 20, 99980]]);
});

it('deducts the union of the recipes of a mixed ticket, and an ingredient only in Fries only by Fries', function () {
    $i = saleSetup(1000, 10, 100);

    postSale($i, ['lines' => [ticketLine($i['burger'], 1), ticketLine($i['fries'], 3)]])->assertCreated();

    expect(onHandOf($i['beef']))->toBe(850)
        ->and(onHandOf($i['potato']))->toBe(100000 - 600)
        ->and(onHandOf($i['sauce']))->toBe(100000 - 30);

    $potato = StockMovement::where('ingredient_id', $i['potato']->id)->where('reason', 'sale')->get();
    expect($potato)->toHaveCount(1)
        ->and($potato->first()->reference->menuItem->is($i['fries']))->toBeTrue();
    // The Classic line did not touch potatoes, and Fries did not touch beef.
    expect(StockMovement::where('ingredient_id', $i['beef']->id)->where('reason', 'sale')->get()
        ->every(fn ($m) => $m->reference->menuItem->is($i['burger'])))->toBeTrue();
});

it('refuses the whole ticket when one item has no recipe, and does not burn a SALE number', function () {
    $i = saleSetup();
    $plain = MenuItem::factory()->create(['name' => 'Plain Bun']);
    $movementsBefore = StockMovement::count();

    $response = postSale($i, ['lines' => [ticketLine($i['burger'], 1), ticketLine($plain, 1)]])
        ->assertStatus(422)
        ->assertJsonPath('code', 'menu_item_not_sellable');
    expect($response->json())->assertNoIntegerIds()
        ->and($response->json('message'))->toContain('Plain Bun');

    // The valid Classic line did not slip through.
    expect(Sale::count())->toBe(0)
        ->and(SaleLine::count())->toBe(0)
        ->and(StockMovement::count())->toBe($movementsBefore)
        ->and(onHandOf($i['beef']))->toBe(1000)
        ->and(Activity::where('event', 'sale.recorded')->count())->toBe(0);

    // The next real sale still gets number 1.
    postSale($i)->assertCreated()->assertJsonPath('data.number', 'SALE-'.now('UTC')->year.'-000001');
});

it('merges a menu item sent twice into one line with the added quantity', function () {
    $i = saleSetup();

    $response = postSale($i, ['lines' => [ticketLine($i['burger'], 1), ticketLine($i['burger'], 1)]])->assertCreated();

    expect(SaleLine::count())->toBe(1)
        ->and(SaleLine::firstOrFail()->quantity)->toBe(2)
        ->and($response->json('data.item_count'))->toBe(1)
        ->and($response->json('data.lines.0.quantity'))->toBe(2)
        // The same movements as one line of 2: three, not six.
        ->and(saleMovements())->toBe([['Beef', -300], ['Bun', -2], ['Cheese', -40]]);
});

it('accepts a sale that takes cheese below zero and says so (T7, D-010)', function () {
    $i = saleSetup(1000, 10, 30);

    $response = postSale($i)->assertCreated();
    expect($response->json())->assertNoIntegerIds();

    expect(onHandOf($i['beef']))->toBe(700)
        ->and(onHandOf($i['bun']))->toBe(8)
        ->and(onHandOf($i['cheese']))->toBe(-10);

    $byName = collect($response->json('data.deductions'))->keyBy('ingredient.name');
    expect($byName['Beef']['on_hand_after'])->toBe(700)
        ->and($byName['Beef']['is_negative'])->toBeFalse()
        ->and($byName['Cheese']['on_hand_after'])->toBe(-10)
        ->and($byName['Cheese']['is_negative'])->toBeTrue();

    $warnings = array_filter(saleLog('stock'), fn ($r) => $r->message === 'Stock is below zero');
    expect($warnings)->toHaveCount(1)
        ->and(array_values($warnings)[0]->context['on_hand'])->toBe(-10);
});

it('answers the same pos_reference twice with one sale, one set of movements and a replay (T10)', function () {
    $i = saleSetup();

    $first = postSale($i, ['pos_reference' => 'till-1:0001'])->assertCreated();
    $second = postSale($i, ['pos_reference' => 'till-1:0001'])
        ->assertOk()
        ->assertJsonPath('data.replayed', true);
    expect($second->json())->assertNoIntegerIds();

    expect($second->json('data.id'))->toBe($first->json('data.id'))
        ->and($second->json('data.number'))->toBe($first->json('data.number'))
        ->and(Sale::count())->toBe(1)
        ->and(StockMovement::where('reason', 'sale')->count())->toBe(3)
        ->and(onHandOf($i['beef']))->toBe(700);

    // The replay is audited and noticed, not silent.
    expect(Activity::where('event', 'sale.replayed')->count())->toBe(1)
        ->and(Activity::where('event', 'sale.recorded')->count())->toBe(1);
    expect(array_filter(saleLog('pos'), fn ($r) => $r->level === Level::Notice))->toHaveCount(1);
});

it('refuses a reused pos_reference with a different quantity and changes nothing (T23)', function () {
    $i = saleSetup();
    postSale($i, ['pos_reference' => 'till-1:0001'])->assertCreated();

    $response = postSale($i, ['pos_reference' => 'till-1:0001', 'lines' => [ticketLine($i['burger'], 3)]])
        ->assertStatus(409)
        ->assertJsonPath('code', 'idempotency_conflict');
    expect($response->json())->assertNoIntegerIds();

    expect(Sale::count())->toBe(1)
        ->and(StockMovement::where('reason', 'sale')->count())->toBe(3)
        ->and(onHandOf($i['beef']))->toBe(700)
        ->and(array_filter(saleLog('pos'), fn ($r) => $r->level === Level::Warning))->toHaveCount(1);
});

it('refuses a reused pos_reference with a different menu item', function () {
    $i = saleSetup();
    postSale($i, ['pos_reference' => 'till-1:0001'])->assertCreated();

    // Same quantity, different item.
    postSale($i, ['pos_reference' => 'till-1:0001', 'lines' => [ticketLine($i['double'], 2)]])
        ->assertStatus(409)
        ->assertJsonPath('code', 'idempotency_conflict');

    expect(Sale::count())->toBe(1)->and(onHandOf($i['beef']))->toBe(700);
});

it('answers a reordered ticket under the same reference as a replay and moves nothing', function () {
    $i = saleSetup(10000, 100, 1000);
    $lines = [ticketLine($i['burger'], 2), ticketLine($i['fries'], 1), ticketLine($i['double'], 1)];

    $first = postSale($i, ['pos_reference' => 'till-1:0001', 'lines' => $lines])->assertCreated();
    $movements = StockMovement::count();
    $beef = onHandOf($i['beef']);

    $replay = postSale($i, ['pos_reference' => 'till-1:0001', 'lines' => array_reverse($lines)])
        ->assertOk()
        ->assertJsonPath('data.replayed', true);

    expect($replay->json('data.id'))->toBe($first->json('data.id'))
        ->and($replay->json('data.lines'))->toBe($first->json('data.lines'))
        ->and(StockMovement::count())->toBe($movements)
        ->and(onHandOf($i['beef']))->toBe($beef)
        ->and(Sale::count())->toBe(1);
});

it('treats a split duplicate under the same reference as the same ticket', function () {
    $i = saleSetup();
    postSale($i, ['pos_reference' => 'till-1:0001', 'lines' => [ticketLine($i['burger'], 2)]])->assertCreated();

    // Classic 1 + Classic 1 merges to Classic 2: the same sale, so a replay.
    postSale($i, ['pos_reference' => 'till-1:0001', 'lines' => [ticketLine($i['burger'], 1), ticketLine($i['burger'], 1)]])
        ->assertOk()
        ->assertJsonPath('data.replayed', true);

    expect(Sale::count())->toBe(1)->and(onHandOf($i['beef']))->toBe(700);
});

it('refuses a reused reference when a line is added or a quantity changes, and moves nothing', function (array $lines) {
    $i = saleSetup(10000, 100, 1000);
    postSale($i, ['pos_reference' => 'till-1:0001', 'lines' => [ticketLine($i['burger'], 2), ticketLine($i['fries'], 1)]])->assertCreated();
    $movements = StockMovement::count();

    postSale($i, ['pos_reference' => 'till-1:0001', 'lines' => specLines($i, $lines)])
        ->assertStatus(409)
        ->assertJsonPath('code', 'idempotency_conflict');

    expect(Sale::count())->toBe(1)
        ->and(SaleLine::count())->toBe(2)
        ->and(StockMovement::count())->toBe($movements);
})->with([
    'a quantity changed' => [[['burger', 3], ['fries', 1]]],
    'a line added' => [[['burger', 2], ['fries', 1], ['double', 1]]],
    'a line removed' => [[['burger', 2]]],
]);

/*
| R4: two identical retries pass the lookup together and the unique index stops the loser.
| A subclass makes the first lookup miss, which is exactly what the loser saw.
*/
function racingService(): SaleService
{
    return new class(app(StockLedger::class), app(DocumentNumber::class)) extends SaleService
    {
        private bool $missed = false;

        protected function findByReference(string $posReference): ?Sale
        {
            if (! $this->missed) {
                $this->missed = true;

                return null;
            }

            return parent::findByReference($posReference);
        }
    };
}

it('answers the loser of a pos_reference race as a replay (R4)', function () {
    $i = saleSetup();
    $winner = app(SaleService::class)->record([serviceLine($i['burger'], 2)], 'till-1:0001');

    $loser = racingService()->record([serviceLine($i['burger'], 2)], 'till-1:0001');

    expect($loser->replayed)->toBeTrue()
        ->and($loser->sale->is($winner->sale))->toBeTrue()
        ->and(Sale::count())->toBe(1)
        ->and(StockMovement::where('reason', 'sale')->count())->toBe(3)
        ->and(onHandOf($i['beef']))->toBe(700);
});

it('answers the loser of a race with a different payload as idempotency_conflict (R4, D-029)', function () {
    $i = saleSetup();
    app(SaleService::class)->record([serviceLine($i['burger'], 2)], 'till-1:0001');

    expect(fn () => racingService()->record([serviceLine($i['burger'], 3)], 'till-1:0001'))
        ->toThrow(IdempotencyConflict::class);

    expect(Sale::count())->toBe(1)->and(onHandOf($i['beef']))->toBe(700);
});

it('rejects a menu item without a recipe with menu_item_not_sellable', function () {
    $item = MenuItem::factory()->create(['name' => 'Plain Bun']);

    $response = $this->postJson('/api/v1/sales', ['lines' => [ticketLine($item, 1)]])
        ->assertStatus(422)
        ->assertJsonPath('code', 'menu_item_not_sellable');
    expect($response->json())->assertNoIntegerIds();
    expect(Sale::count())->toBe(0);
});

it('rejects invalid input with 422', function (array $overrides) {
    $i = saleSetup();

    // `spec` is a ticket named by item key; see specLines().
    if (isset($overrides['spec'])) {
        $overrides['lines'] = specLines($i, $overrides['spec']);
        unset($overrides['spec']);
    }

    $response = postSale($i, $overrides)->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    expect($response->json())->assertNoIntegerIds();
    expect(Sale::count())->toBe(0)->and(onHandOf($i['beef']))->toBe(1000);
})->with([
    'quantity 0' => [['spec' => [['burger', 0]]]],
    'quantity above 1000' => [['spec' => [['burger', 1001]]]],
    'quantity true' => [['spec' => [['burger', true]]]],
    'quantity as string' => [['spec' => [['burger', '2']]]],
    'quantity missing' => [['spec' => [['burger', null]]]],
    'unknown menu item' => [['spec' => [['01jzzzzzzzzzzzzzzzzzzzzzzz', 1]]]],
    'numeric menu item id' => [['spec' => [[1, 1]]]],
    'menu item missing' => [['spec' => [[null, 1]]]],
    'a line that is not an object' => [['lines' => [5]]],
    'lines is an object, not a list' => [['lines' => ['a' => ['menu_item_id' => '01jzzzzzzzzzzzzzzzzzzzzzzz', 'quantity' => 1]]]],
    'reference with spaces' => [['pos_reference' => 'till 1']],
    'reference too long' => [['pos_reference' => str_repeat('a', 65)]],
    'sold_at an hour ahead' => [fn () => ['sold_at' => now()->addHour()->toIso8601String()]],
    'sold_at relative +1 hour' => [['sold_at' => '+1 hour']],
    'sold_at relative yesterday' => [['sold_at' => 'yesterday']],
    'sold_at date only' => [['sold_at' => '2026-10-01']],
]);

it('keys each ticket validation error on the field and numbers lines from 1 in the message', function (array $body, string $key, string $message) {
    $i = saleSetup();

    // `spec` is a ticket named by item key (see specLines()); `old` is the pre-ticket body shape.
    if (isset($body['spec'])) {
        $body['lines'] = specLines($i, $body['spec']);
        unset($body['spec']);
    }
    if (isset($body['old'])) {
        $body = ['menu_item_id' => $i['burger']->ulid, 'quantity' => 2];
    }

    $response = test()->postJson('/api/v1/sales', $body)->assertStatus(422)->assertJsonPath('code', 'validation_failed');

    expect($response->json())->assertNoIntegerIds();
    // The envelope puts field errors under `errors`, keyed by the dotted field name (a flat key, not nested).
    expect($response->json('errors')[$key][0] ?? null)->toBe($message)
        ->and(Sale::count())->toBe(0);
})->with([
    'missing lines' => [[], 'lines', 'Add at least one menu item to the sale.'],
    'empty lines' => [['lines' => []], 'lines', 'Add at least one menu item to the sale.'],
    'the old single-item body' => [['old' => true], 'lines', 'Add at least one menu item to the sale.'],
    '51 lines' => [['spec' => array_fill(0, 51, ['burger', 1])], 'lines', 'A sale can hold at most 50 different items.'],
    'bad quantity on the second line' => [
        ['spec' => [['burger', 1], ['fries', 0]]],
        'lines.1.quantity', 'Line 2: the quantity must be a whole number from 1 to 1,000.',
    ],
    'unknown menu item on the first line' => [
        ['spec' => [['01jzzzzzzzzzzzzzzzzzzzzzzz', 1]]],
        'lines.0.menu_item_id', 'Line 1: that menu item does not exist.',
    ],
    'menu item missing on the third line' => [
        ['spec' => [['burger', 1], ['fries', 1], [null, 1]]],
        'lines.2.menu_item_id', 'Line 3: choose the menu item that was sold.',
    ],
    'duplicates that together pass 1000' => [
        ['spec' => [['burger', 600], ['fries', 1], ['burger', 600]]],
        'lines.0.quantity', 'Line 1: the quantity must be a whole number from 1 to 1,000.',
    ],
]);

it('accepts a merged quantity of exactly 1000 and exactly 50 lines', function () {
    $i = saleSetup(1000000, 1000000, 1000000);

    postSale($i, ['lines' => [ticketLine($i['burger'], 400), ticketLine($i['burger'], 600)]])->assertCreated();
    expect(SaleLine::firstOrFail()->quantity)->toBe(1000);

    $items = MenuItem::factory()->count(50)->create();
    foreach ($items as $item) {
        RecipeLine::factory()->create(['menu_item_id' => $item->id, 'ingredient_id' => $i['beef']->id, 'quantity' => 1]);
    }

    postSale($i, ['lines' => $items->map(fn ($item) => ticketLine($item, 1))->all()])->assertCreated();
    expect(SaleLine::count())->toBe(51);
});

it('accepts sold_at in the Z form with and without fractional seconds', function (string $suffix) {
    $i = saleSetup(100000, 100000, 100000);
    $when = now()->subMinutes(10)->startOfSecond();
    $value = $when->format('Y-m-d\TH:i:s').$suffix;

    $this->postJson('/api/v1/sales', ['lines' => [ticketLine($i['burger'], 1)], 'sold_at' => $value])
        ->assertCreated();

    expect(Sale::firstOrFail()->sold_at->equalTo($when))->toBeTrue();
})->with(['Z', '.250Z']);

it('anchors the pos_reference pattern at the true end of the string', function () {
    // TrimStrings removes a trailing newline before validation over HTTP, so the rule is tested
    // directly: with a plain `$` anchor "till-1:0001\n" would pass, with `\z` it must not.
    $rules = (new StoreSaleRequest)->rules()['pos_reference'];
    $passes = fn (string $value) => validator(['pos_reference' => $value], ['pos_reference' => $rules])->passes();

    expect($passes("till-1:0001\n"))->toBeFalse()
        ->and($passes("till-1:0001\r\n"))->toBeFalse()
        ->and($passes('till-1:0001'))->toBeTrue();
});

it('accepts a sold_at in the past and stamps the movements with it', function () {
    $i = saleSetup();
    $when = now()->subHour()->startOfSecond();

    $this->postJson('/api/v1/sales', [
        'lines' => [ticketLine($i['burger'], 1)], 'sold_at' => $when->toIso8601String(),
    ])->assertCreated();

    expect(Sale::firstOrFail()->sold_at->equalTo($when))->toBeTrue()
        ->and(StockMovement::where('reason', 'sale')->first()->occurred_at->equalTo($when))->toBeTrue();
});

it('reports on_hand_after as the balance at that sale, so a replay and the list match the first answer', function () {
    $i = saleSetup(1000, 10, 100);

    $a = postSale($i, ['pos_reference' => 'till-1:A'])->assertCreated();
    postSale($i, ['pos_reference' => 'till-1:B', 'lines' => [ticketLine($i['burger'], 3)]])->assertCreated();
    $replayA = postSale($i, ['pos_reference' => 'till-1:A'])->assertOk()->assertJsonPath('data.replayed', true);

    $figures = fn (array $deductions) => collect($deductions)
        ->mapWithKeys(fn ($d) => [$d['ingredient']['name'] => [$d['on_hand_after'], $d['is_negative']]])->all();
    $first = $figures($a->json('data.deductions'));

    // After A alone: beef 700, bun 8, cheese 60. Sale B has since taken them lower (beef 250, bun 5, cheese 0).
    expect($first)->toBe(['Beef' => [700, false], 'Bun' => [8, false], 'Cheese' => [60, false]])
        ->and($figures($replayA->json('data.deductions')))->toBe($first)
        ->and(onHandOf($i['beef']))->toBe(250);

    $list = $this->getJson('/api/v1/sales')->assertOk();
    expect($list->json())->assertNoIntegerIds();
    $rowA = collect($list->json('data'))->firstWhere('number', $a->json('data.number'));
    expect($figures($rowA['deductions']))->toBe($first);
    // B is the newest sale and shows the later balances.
    expect($list->json('data.0.deductions.0.on_hand_after'))->toBe(250);
});

it('uses the same number of queries to list one three-line ticket as twenty-five', function () {
    $i = saleSetup(1000000, 1000000, 1000000);
    $queries = function () {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $rows = test()->getJson('/api/v1/sales?per_page=100')->assertOk()->json('data');
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$count, $rows];
    };
    $ticket = [ticketLine($i['burger'], 1), ticketLine($i['double'], 1), ticketLine($i['fries'], 1)];

    postSale($i, ['lines' => $ticket])->assertCreated();
    [$one] = $queries();
    foreach (range(1, 24) as $n) {
        postSale($i, ['lines' => $ticket])->assertCreated();
    }
    [$many, $rows] = $queries();

    // The guard only means something if the 25 rows really carry their lines and deductions.
    expect(Sale::count())->toBe(25)
        ->and($rows)->toHaveCount(25)
        ->and($rows[24]['lines'])->toHaveCount(3)
        ->and($rows[24]['deductions'])->toHaveCount(5)
        ->and($many)->toBe($one);
});

it('stores a sold_at sent with a UTC offset as the same instant in UTC', function () {
    $i = saleSetup();
    // An Amman till (+03:00) sending "now": inside the 5-minute window, but 3 hours off if stored unconverted.
    $when = now()->subMinute()->startOfSecond();
    $local = $when->copy()->setTimezone('Asia/Amman')->toIso8601String();
    expect($local)->toEndWith('+03:00');

    $response = $this->postJson('/api/v1/sales', [
        'lines' => [ticketLine($i['burger'], 1)], 'sold_at' => $local,
    ])->assertCreated();

    expect($response->json('data.sold_at'))->toBe($when->toIso8601ZuluString())
        ->and(Sale::firstOrFail()->sold_at->equalTo($when))->toBeTrue()
        ->and(StockMovement::where('reason', 'sale')->get()->every(fn ($m) => $m->occurred_at->equalTo($when)))->toBeTrue();
});

it('follows the X-POS-Key rule (T22, D-028)', function () {
    $i = saleSetup();

    // No key configured: open.
    postSale($i)->assertCreated();

    config(['services.pos.api_key' => 'secret']);
    postSale($i)->assertStatus(401)->assertJsonPath('code', 'unauthorized');
    $this->withHeader('X-POS-Key', 'wrong')->postJson('/api/v1/sales', ['lines' => [ticketLine($i['burger'], 2)]])->assertStatus(401);
    $this->withHeader('X-POS-Key', 'secret')->postJson('/api/v1/sales', ['lines' => [ticketLine($i['burger'], 2)]])->assertCreated();

    expect(Sale::count())->toBe(2);
});

it('answers 429 beyond the pos rate limit (T20)', function () {
    $i = saleSetup(100000, 100000, 100000);
    RateLimiter::for('pos', fn (Request $request) => Limit::perMinute(2)->by($request->ip()));

    postSale($i)->assertCreated();
    postSale($i)->assertCreated();
    postSale($i)->assertStatus(429)->assertJsonPath('code', 'too_many_requests');

    expect(Sale::count())->toBe(2);
});

it('does not change a past sale when the recipe changes afterwards', function () {
    $i = saleSetup();
    $response = postSale($i, ['pos_reference' => 'till-1:0001'])->assertCreated();

    $this->putJson("/api/v1/menu-items/{$i['burger']->ulid}/recipe", ['lines' => [
        ['ingredient_id' => $i['beef']->ulid, 'quantity' => 999],
    ]])->assertOk();

    expect(StockMovement::where('reason', 'sale')->pluck('quantity_delta', 'ingredient_id')->all())->toBe([
        $i['beef']->id => -300,
        $i['bun']->id => -2,
        $i['cheese']->id => -40,
    ]);

    // Replaying the old sale still shows the old deductions (300/2/40), not the new recipe (999 beef only).
    $replay = postSale($i, ['pos_reference' => 'till-1:0001'])->assertOk()->assertJsonPath('data.replayed', true);
    $quantities = fn (array $deductions) => collect($deductions)->pluck('quantity', 'ingredient.name')->all();

    expect($quantities($replay->json('data.deductions')))->toBe(['Beef' => 300, 'Bun' => 2, 'Cheese' => 40])
        ->and($quantities($response->json('data.deductions')))->toBe(['Beef' => 300, 'Bun' => 2, 'Cheese' => 40]);

    $list = $this->getJson('/api/v1/sales')->assertOk()->assertJsonPath('data.0.id', Sale::firstOrFail()->ulid);
    expect($quantities($list->json('data.0.deductions')))->toBe(['Beef' => 300, 'Bun' => 2, 'Cheese' => 40]);
});

it('accepts an uppercase menu item ULID (G4)', function () {
    $i = saleSetup();

    $this->postJson('/api/v1/sales', ['lines' => [['menu_item_id' => strtoupper($i['burger']->ulid), 'quantity' => 1]]])
        ->assertCreated()
        ->assertJsonPath('data.lines.0.menu_item.id', $i['burger']->ulid);
});

it('keeps GET /sales open while a POS key is configured (D-028 guards the write only)', function () {
    config(['services.pos.api_key' => 'secret']);

    $this->getJson('/api/v1/sales')->assertOk();
});

it('audits sale.recorded with a human description and no integer ids', function () {
    $i = saleSetup(10000, 100, 1000);
    postSale($i, ['pos_reference' => 'till-1:0001'])->assertCreated();
    postSale($i, ['pos_reference' => 'till-1:0002', 'lines' => [ticketLine($i['burger'], 1), ticketLine($i['fries'], 2), ticketLine($i['double'], 1)]])->assertCreated();

    [$one, $three] = Sale::orderBy('id')->get()->all();
    $audit = fn (Sale $sale) => Activity::where('event', 'sale.recorded')->where('subject_id', $sale->id)->firstOrFail();

    expect($audit($one)->description)->toBe("{$one->number} Classic Burger x2")
        ->and($audit($one)->subject_type)->toBe('sale')
        ->and($audit($one)->properties->only(['lines', 'pos_reference'])->all())->toBe([
            'lines' => [['menu_item' => $i['burger']->ulid, 'quantity' => 2]],
            'pos_reference' => 'till-1:0001',
        ])
        ->and($audit($three)->description)->toBe("{$three->number} 3 items")
        ->and($audit($three)->properties['lines'])->toBe([
            ['menu_item' => $i['burger']->ulid, 'quantity' => 1],
            ['menu_item' => $i['double']->ulid, 'quantity' => 1],
            ['menu_item' => $i['fries']->ulid, 'quantity' => 2],
        ]);
    expect($audit($three)->properties->toArray())->assertNoIntegerIds();
});

it('logs the lines of a recorded sale and both tickets of a conflicting reuse', function () {
    $i = saleSetup();
    postSale($i, ['pos_reference' => 'till-1:0001'])->assertCreated();
    postSale($i, ['pos_reference' => 'till-1:0001', 'lines' => [ticketLine($i['fries'], 1)]])->assertStatus(409);

    $records = collect(saleLog('pos'));
    $recorded = $records->firstWhere('message', 'Sale recorded');
    $warning = $records->firstWhere('message', 'POS reference reused with a different payload');

    expect($recorded->context['lines'])->toBe([['menu_item' => $i['burger']->ulid, 'quantity' => 2]])
        ->and($recorded->context)->not->toHaveKeys(['menu_item', 'quantity'])
        ->and($warning->context['original_lines'])->toBe([['menu_item' => $i['burger']->ulid, 'quantity' => 2]])
        ->and($warning->context['attempted_lines'])->toBe([['menu_item' => $i['fries']->ulid, 'quantity' => 1]]);
});

it('leaves no sale, no movements and no burned number when a ledger write fails mid-sale', function () {
    $i = saleSetup();
    $movementsBefore = StockMovement::count();

    // The second recipe line fails, after the sale row and the first movement were written.
    $this->app->bind(StockLedger::class, fn () => new class extends StockLedger
    {
        private int $calls = 0;

        public function record(Ingredient $ingredient, int $delta, MovementReason $reason, Model $reference, ?CarbonInterface $occurredAt = null): StockMovement
        {
            if ($reason === MovementReason::Sale && ++$this->calls === 2) {
                throw new RuntimeException('disk full');
            }

            return parent::record($ingredient, $delta, $reason, $reference, $occurredAt);
        }
    });

    $this->postJson('/api/v1/sales', ['lines' => [ticketLine($i['burger'], 2)]])->assertStatus(500);

    expect(Sale::count())->toBe(0)
        ->and(SaleLine::count())->toBe(0)
        ->and(StockMovement::count())->toBe($movementsBefore)
        ->and(Activity::where('event', 'sale.recorded')->count())->toBe(0)
        // The SALE counter rolled back too, so the next sale is still number 1.
        ->and(DocumentSequence::where('type', 'SALE')->value('last_value'))->toBeNull();
});

it('lists sales newest first, paginated, with deductions (E26)', function () {
    $i = saleSetup(100000, 100000, 100000);
    foreach ([3, 2, 1] as $hoursAgo) {
        $this->postJson('/api/v1/sales', [
            'lines' => [ticketLine($i['burger'], $hoursAgo)],
            'sold_at' => now()->subHours($hoursAgo)->toIso8601String(),
        ])->assertCreated();
    }

    $response = $this->getJson('/api/v1/sales?per_page=2')->assertOk()
        ->assertJsonPath('meta.pagination.total', 3)
        ->assertJsonPath('meta.pagination.per_page', 2)
        ->assertJsonPath('meta.pagination.has_more', true)
        ->assertJsonCount(2, 'data');
    expect($response->json())->assertNoIntegerIds();

    // Newest sale first: the one sold 1 hour ago has quantity 1.
    expect(collect($response->json('data'))->pluck('units')->all())->toBe([1, 2])
        ->and($response->json('data.0.deductions'))->toHaveCount(3);

    $page2 = $this->getJson('/api/v1/sales?per_page=2&page=2')->assertOk();
    expect(collect($page2->json('data'))->pluck('units')->all())->toBe([3]);

    $this->getJson('/api/v1/sales?per_page=101')->assertStatus(422);
});

it('records a ticket that takes cheese below zero and flags it in the grouped deduction (D-010)', function () {
    $i = saleSetup(1000, 10, 50);

    $response = postSale($i, ['lines' => [ticketLine($i['burger'], 1), ticketLine($i['double'], 1)]])->assertCreated();

    // Classic takes 20 (50 -> 30), Double takes 40 (30 -> -10): recorded, not refused.
    $cheese = collect($response->json('data.deductions'))->firstWhere('ingredient.name', 'Cheese');
    expect($cheese['quantity'])->toBe(60)
        ->and($cheese['on_hand_after'])->toBe(-10)
        ->and($cheese['is_negative'])->toBeTrue()
        ->and(onHandOf($i['cheese']))->toBe(-10)
        ->and(Sale::count())->toBe(1);
});

it('groups deductions per ingredient across lines, with the balance after the ingredient\'s last movement', function () {
    $i = saleSetup(1000, 10, 100);

    $response = postSale($i, ['lines' => [ticketLine($i['burger'], 1), ticketLine($i['double'], 1)]])->assertCreated();
    $deductions = collect($response->json('data.deductions'));

    // Beef is taken by both lines (150 then 300) but appears once; the burger sauce only by the Double.
    expect($deductions->pluck('ingredient.name')->all())->toBe(['Beef', 'Bun', 'Cheese', 'Burger sauce'])
        ->and($deductions->firstWhere('ingredient.name', 'Beef')['quantity'])->toBe(450)
        // 1000 - 150 = 850 after the first line, 1000 - 450 = 550 after the last: the last one is reported.
        ->and($deductions->firstWhere('ingredient.name', 'Beef')['on_hand_after'])->toBe(550)
        ->and($deductions->firstWhere('ingredient.name', 'Bun')['quantity'])->toBe(2)
        ->and($deductions->firstWhere('ingredient.name', 'Bun')['on_hand_after'])->toBe(8)
        ->and($deductions->firstWhere('ingredient.name', 'Burger sauce')['quantity'])->toBe(20);

    // The same figures come back from the list, computed from the ledger.
    $row = collect($this->getJson('/api/v1/sales')->assertOk()->json('data'))->firstWhere('id', $response->json('data.id'));
    expect($row['deductions'])->toBe($response->json('data.deductions'));
});

it('labels movements in the history with the item for a one-line sale and the count otherwise', function () {
    $i = saleSetup(10000, 100, 1000);
    $year = now('UTC')->year;

    $one = postSale($i)->assertCreated();
    $many = postSale($i, ['lines' => [ticketLine($i['burger'], 1), ticketLine($i['double'], 1)]])->assertCreated();

    $rows = $this->getJson("/api/v1/ingredients/{$i['beef']->ulid}/movements")->assertOk()->json('data');
    $sales = collect($rows)->where('reason', 'sale')->values();

    // Newest first: the two beef movements of the ticket (one per line), then the one-line sale.
    expect($sales)->toHaveCount(3)
        ->and($sales[0]['reference']['label'])->toBe("SALE-{$year}-000002 2 items")
        ->and($sales[1]['reference']['label'])->toBe("SALE-{$year}-000002 2 items")
        ->and($sales[2]['reference']['label'])->toBe("SALE-{$year}-000001 Classic Burger x2")
        ->and($sales[0]['reference']['type'])->toBe('sale')
        ->and($sales[0]['reference']['number'])->toBe("SALE-{$year}-000002")
        ->and($sales[0]['reference']['sale_id'])->toBe($many->json('data.id'))
        ->and($sales[2]['reference']['sale_id'])->toBe($one->json('data.id'));
});

it('still describes and lists a sale recorded before D-048, whose movement points at the sale itself', function () {
    $i = saleSetup();
    $sale = Sale::factory()->create(['number' => 'SALE-2026-000900']);
    SaleLine::factory()->create(['sale_id' => $sale->id, 'menu_item_id' => $i['burger']->id, 'quantity' => 2]);
    app(StockLedger::class)->record($i['beef'], -300, MovementReason::Sale, $sale);

    $row = collect($this->getJson('/api/v1/sales')->assertOk()->json('data'))->firstWhere('number', 'SALE-2026-000900');
    $history = $this->getJson("/api/v1/ingredients/{$i['beef']->ulid}/movements")->assertOk()->json('data.0.reference');

    expect($row['deductions'])->toHaveCount(1)
        ->and($row['deductions'][0]['quantity'])->toBe(300)
        ->and($row['deductions'][0]['on_hand_after'])->toBe(700)
        ->and($history['label'])->toBe('SALE-2026-000900 Classic Burger x2')
        ->and($history['sale_id'])->toBe($sale->ulid);
});

/*
| Migration 2026_10_11_000001: sales used to carry menu_item_id and quantity. The migration is run
| backwards and forwards by hand here, because the test database is already on the new shape.
*/
it('migrates an old-shape sale into one sale line with the same item and quantity', function () {
    $migration = require database_path('migrations/2026_10_11_000001_create_sale_lines_table.php');
    $burger = MenuItem::factory()->create();
    $fries = MenuItem::factory()->create();

    $migration->down();
    expect(Schema::hasTable('sale_lines'))->toBeFalse()
        ->and(Schema::hasColumns('sales', ['menu_item_id', 'quantity']))->toBeTrue();

    foreach ([['SALE-OLD-1', $burger, 3], ['SALE-OLD-2', $fries, 7]] as $n => [$number, $item, $quantity]) {
        DB::table('sales')->insert([
            'ulid' => '01jaaaaaaaaaaaaaaaaaaaaaa'.$n.'0', 'number' => $number, 'menu_item_id' => $item->id,
            'quantity' => $quantity, 'sold_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    $migration->up();

    expect(Schema::hasColumn('sales', 'menu_item_id'))->toBeFalse()
        ->and(Schema::hasColumn('sales', 'quantity'))->toBeFalse();

    $lines = Sale::orderBy('id')->get()->map(fn (Sale $sale) => [$sale->number, $sale->lines->count(), $sale->lines->first()->menu_item_id, $sale->lines->first()->quantity])->all();
    expect($lines)->toBe([['SALE-OLD-1', 1, $burger->id, 3], ['SALE-OLD-2', 1, $fries->id, 7]]);
});

it('rolls the sale lines migration back to one item per sale, keeping the first line', function () {
    $migration = require database_path('migrations/2026_10_11_000001_create_sale_lines_table.php');
    $burger = MenuItem::factory()->create();
    $fries = MenuItem::factory()->create();
    $sale = Sale::factory()->create();
    SaleLine::factory()->create(['sale_id' => $sale->id, 'menu_item_id' => $burger->id, 'quantity' => 2]);
    SaleLine::factory()->create(['sale_id' => $sale->id, 'menu_item_id' => $fries->id, 'quantity' => 5]);

    $migration->down();

    $row = DB::table('sales')->where('id', $sale->id)->first();
    expect(Schema::hasTable('sale_lines'))->toBeFalse()
        ->and([$row->menu_item_id, $row->quantity])->toBe([$burger->id, 2]);

    $migration->up();
});
