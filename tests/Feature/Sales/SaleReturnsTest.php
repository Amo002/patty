<?php

use App\Enums\MovementReason;
use App\Enums\Unit;
use App\Exceptions\Domain\IdempotencyConflict;
use App\Models\DeliveryLine;
use App\Models\DocumentSequence;
use App\Models\Ingredient;
use App\Models\MenuItem;
use App\Models\RecipeLine;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\SaleReturn;
use App\Models\StockMovement;
use App\Services\DocumentNumber;
use App\Services\SaleReturnService;
use App\Services\SaleService;
use App\Services\StockLedger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Spatie\Activitylog\Models\Activity;

/*
| E27 (PTY-30, D-049). Fries = potatoes 200 g, oil 25 ml, ketchup 20 ml. Classic Burger = beef 150 g, bun 1,
| cheese 20 g. Double Patty = beef 300 g, bun 1, cheese 40 g. Menu items are created in that order, so
| sorting by menu item id puts Classic first, then Double, then Fries.
*/

/**
 * @return array<string, Ingredient|MenuItem>
 */
function returnSetup(int $cheese = 100000): array
{
    $i = [
        'potato' => Ingredient::factory()->create(['name' => 'Potatoes', 'unit' => Unit::Gram]),
        'oil' => Ingredient::factory()->create(['name' => 'Frying oil', 'unit' => Unit::Millilitre]),
        'ketchup' => Ingredient::factory()->create(['name' => 'Ketchup', 'unit' => Unit::Millilitre]),
        'beef' => Ingredient::factory()->create(['name' => 'Beef', 'unit' => Unit::Gram]),
        'bun' => Ingredient::factory()->create(['name' => 'Bun', 'unit' => Unit::Piece]),
        'cheese' => Ingredient::factory()->create(['name' => 'Cheese', 'unit' => Unit::Gram]),
    ];

    $i['burger'] = MenuItem::factory()->create(['name' => 'Classic Burger']);
    $i['double'] = MenuItem::factory()->create(['name' => 'Double Patty']);
    $i['fries'] = MenuItem::factory()->create(['name' => 'Fries']);

    $recipes = [
        'burger' => [['beef', 150], ['bun', 1], ['cheese', 20]],
        'double' => [['beef', 300], ['bun', 1], ['cheese', 40]],
        'fries' => [['potato', 200], ['oil', 25], ['ketchup', 20]],
    ];
    foreach ($recipes as $item => $lines) {
        foreach ($lines as [$key, $qty]) {
            RecipeLine::factory()->create(['menu_item_id' => $i[$item]->id, 'ingredient_id' => $i[$key]->id, 'quantity' => $qty]);
        }
    }

    $ledger = app(StockLedger::class);
    foreach ([['potato', 10000], ['oil', 10000], ['ketchup', 10000], ['beef', 10000], ['bun', 1000], ['cheese', $cheese]] as [$key, $qty]) {
        $ledger->record($i[$key], $qty, MovementReason::Delivery, DeliveryLine::factory()->create());
    }

    return $i;
}

/** @param  list<array{0: MenuItem, 1: int}>  $lines */
function recordedSale(array $lines, ?string $reference = null): Sale
{
    return app(SaleService::class)->record(
        array_map(fn (array $l) => ['item' => $l[0], 'quantity' => $l[1]], $lines),
        $reference,
    )->sale;
}

/** @param  list<array{0: MenuItem, 1: int}>  $lines */
function refundBody(array $lines, bool $restock = false, array $overrides = []): array
{
    return array_merge([
        'lines' => array_map(fn (array $l) => ['menu_item_id' => $l[0]->ulid, 'quantity' => $l[1]], $lines),
        'restock' => $restock,
        'reason' => 'Customer complaint',
    ], $overrides);
}

function postRefund(Sale $sale, array $body)
{
    return test()->postJson("/api/v1/sales/{$sale->ulid}/returns", $body);
}

function holding(Ingredient $ingredient): int
{
    return app(StockLedger::class)->onHand($ingredient);
}

/** Refund movements as [ingredient name, delta], in write order. */
function returnMovements(): array
{
    return StockMovement::with('ingredient')->where('reason', 'sale_return')->orderBy('id')->get()
        ->map(fn ($m) => [$m->ingredient->name, $m->quantity_delta])->all();
}

it('runs the worked example: a made refund moves nothing, a voided one restocks, a third is refused (D-049)', function () {
    $i = returnSetup();
    $year = now('UTC')->year;
    $sale = recordedSale([[$i['fries'], 2]]);
    // Fries x2 took potatoes 400, oil 50, ketchup 40.
    expect([holding($i['potato']), holding($i['oil']), holding($i['ketchup'])])->toBe([9600, 9950, 9960]);

    // Row 1: made, so restock false and no movement at all.
    $first = postRefund($sale, refundBody([[$i['fries'], 1]], false))->assertCreated()
        ->assertJsonPath('message', 'Refund recorded.')
        ->assertJsonPath('data.number', "RET-{$year}-000001")
        ->assertJsonPath('data.sale.number', $sale->number)
        ->assertJsonPath('data.restock', false)
        ->assertJsonPath('data.replayed', false)
        ->assertJsonPath('data.restocked', [])
        ->assertJsonPath('data.lines.0.quantity', 1)
        ->assertJsonPath('data.lines.0.menu_item.name', 'Fries');
    expect($first->json())->assertNoIntegerIds()
        ->and(StockMovement::where('reason', 'sale_return')->count())->toBe(0)
        ->and([holding($i['potato']), holding($i['oil']), holding($i['ketchup'])])->toBe([9600, 9950, 9960]);

    // Row 2: voided before cooking. -(-400)/2 x 1 = +200, and likewise 25 and 20.
    $second = postRefund($sale, refundBody([[$i['fries'], 1]], true, ['reason' => 'Cancelled before cooking']))->assertCreated()
        ->assertJsonPath('data.number', "RET-{$year}-000002");

    $line = SaleLine::firstOrFail();
    $returnLine = $line->returnLines()->orderByDesc('id')->firstOrFail();
    $movements = StockMovement::where('reason', 'sale_return')->orderBy('id')->get();
    expect($movements)->toHaveCount(3)
        ->and($movements->every(fn ($m) => $m->reference_type === 'sale_return_line' && $m->reference_id === $returnLine->id))->toBeTrue()
        ->and($movements->every(fn ($m) => $m->reason === MovementReason::SaleReturn))->toBeTrue()
        ->and(returnMovements())->toBe([['Potatoes', 200], ['Frying oil', 25], ['Ketchup', 20]])
        // One Fries is back: down by one Fries from the start, not two.
        ->and([holding($i['potato']), holding($i['oil']), holding($i['ketchup'])])->toBe([9800, 9975, 9980])
        ->and($second->json('data.restocked'))->toBe([
            ['ingredient' => ['id' => $i['potato']->ulid, 'name' => 'Potatoes', 'unit' => 'g'], 'quantity' => 200, 'on_hand_after' => 9800],
            ['ingredient' => ['id' => $i['oil']->ulid, 'name' => 'Frying oil', 'unit' => 'ml'], 'quantity' => 25, 'on_hand_after' => 9975],
            ['ingredient' => ['id' => $i['ketchup']->ulid, 'name' => 'Ketchup', 'unit' => 'ml'], 'quantity' => 20, 'on_hand_after' => 9980],
        ]);

    // Row 3: nothing is left on the line, whatever restock says.
    foreach ([false, true] as $restock) {
        postRefund($sale, refundBody([[$i['fries'], 1]], $restock))->assertStatus(422)
            ->assertJsonPath('code', 'over_refund')
            ->assertJsonPath('message', "only 0 Fries left to refund on {$sale->number}.")
            ->assertJsonPath('errors', ['lines.0.quantity' => ["Line 1: only 0 Fries left to refund on {$sale->number}."]]);
    }

    expect(SaleReturn::count())->toBe(2)
        ->and(StockMovement::where('reason', 'sale_return')->count())->toBe(3)
        ->and(DocumentSequence::where('type', 'RET')->value('last_value'))->toBe(2)
        ->and([holding($i['potato']), holding($i['oil']), holding($i['ketchup'])])->toBe([9800, 9975, 9980]);
});

it('restocks only the refunded line of a multi-line sale, and groups an ingredient shared by two lines', function () {
    $i = returnSetup();
    $sale = recordedSale([[$i['burger'], 2], [$i['double'], 1], [$i['fries'], 1]]);
    // Beef: -300 (Classic x2) and -300 (Double x1).
    expect(holding($i['beef']))->toBe(9400);

    $response = postRefund($sale, refundBody([[$i['fries'], 1]], true))->assertCreated();

    expect(returnMovements())->toBe([['Potatoes', 200], ['Frying oil', 25], ['Ketchup', 20]])
        ->and(holding($i['beef']))->toBe(9400)
        ->and(holding($i['bun']))->toBe(997)
        ->and(collect($response->json('data.restocked'))->pluck('ingredient.name')->all())->toBe(['Potatoes', 'Frying oil', 'Ketchup']);

    // Now one Classic (half of its line: -300/2) and the Double: beef comes back from both lines, grouped as one entry.
    $both = postRefund($sale, refundBody([[$i['burger'], 1], [$i['double'], 1]], true))->assertCreated();

    expect(holding($i['beef']))->toBe(9400 + 150 + 300)
        ->and($both->json('data.restocked'))->toHaveCount(3)
        ->and($both->json('data.restocked.0.ingredient.name'))->toBe('Beef')
        ->and($both->json('data.restocked.0.quantity'))->toBe(450)
        ->and($both->json('data.restocked.0.on_hand_after'))->toBe(9850)
        ->and($both->json('data.restocked.1.quantity'))->toBe(2)
        ->and($both->json('data.restocked.2.quantity'))->toBe(20 + 40);
});

it('restocks from the movements the sale made, not from today\'s recipe', function () {
    $i = returnSetup();
    $sale = recordedSale([[$i['fries'], 2]]);

    // The recipe changes after the sale: potatoes 200 -> 500, oil is removed.
    RecipeLine::where('menu_item_id', $i['fries']->id)->where('ingredient_id', $i['potato']->id)->update(['quantity' => 500]);
    RecipeLine::where('menu_item_id', $i['fries']->id)->where('ingredient_id', $i['oil']->id)->delete();

    postRefund($sale, refundBody([[$i['fries'], 1]], true))->assertCreated();

    expect(returnMovements())->toBe([['Potatoes', 200], ['Frying oil', 25], ['Ketchup', 20]]);
});

it('restocks a legacy sale (movements on the Sale, one line) from the sale\'s own movements', function () {
    $i = returnSetup();
    $legacy = Sale::factory()->create(['number' => 'SALE-2026-000900']);
    SaleLine::factory()->create(['sale_id' => $legacy->id, 'menu_item_id' => $i['burger']->id, 'quantity' => 3]);
    $ledger = app(StockLedger::class);
    $ledger->record($i['beef'], -450, MovementReason::Sale, $legacy);
    $ledger->record($i['bun'], -3, MovementReason::Sale, $legacy);
    $ledger->record($i['cheese'], -60, MovementReason::Sale, $legacy);

    postRefund($legacy, refundBody([[$i['burger'], 2]], true))->assertCreated();

    // -(-450)/3 x 2, -(-3)/3 x 2, -(-60)/3 x 2.
    expect(returnMovements())->toBe([['Beef', 300], ['Bun', 2], ['Cheese', 40]]);
});

it('restocks nothing for a line with no movements on a sale with more than one line', function () {
    $i = returnSetup();
    $sale = Sale::factory()->create();
    SaleLine::factory()->create(['sale_id' => $sale->id, 'menu_item_id' => $i['burger']->id, 'quantity' => 1]);
    SaleLine::factory()->create(['sale_id' => $sale->id, 'menu_item_id' => $i['fries']->id, 'quantity' => 1]);
    // These belong to the sale, not to either line: with two lines nobody can say whose they are.
    app(StockLedger::class)->record($i['beef'], -150, MovementReason::Sale, $sale);

    postRefund($sale, refundBody([[$i['burger'], 1]], true))->assertCreated()->assertJsonPath('data.restocked', []);

    expect(StockMovement::where('reason', 'sale_return')->count())->toBe(0);
});

it('reports every over-refunded line at once and writes nothing, not even a RET number', function () {
    $i = returnSetup();
    $sale = recordedSale([[$i['burger'], 1], [$i['fries'], 2]]);
    $before = StockMovement::count();

    $response = postRefund($sale, refundBody([[$i['fries'], 3], [$i['burger'], 2]], true))->assertStatus(422)
        ->assertJsonPath('code', 'over_refund');

    // Keyed by the row in the request (0-based key, 1-based text), whatever order the lines are processed in.
    expect($response->json('errors'))->toBe([
        'lines.0.quantity' => ["Line 1: only 2 Fries left to refund on {$sale->number}."],
        'lines.1.quantity' => ["Line 2: only 1 Classic Burger left to refund on {$sale->number}."],
    ])
        ->and($response->json('message'))->toBe("only 2 Fries left to refund on {$sale->number}.")
        ->and(SaleReturn::count())->toBe(0)
        ->and(StockMovement::count())->toBe($before)
        ->and(DocumentSequence::where('type', 'RET')->exists())->toBeFalse();
});

it('adds duplicate rows of one item together and keys the error on its first row', function () {
    $i = returnSetup();
    $sale = recordedSale([[$i['burger'], 1], [$i['fries'], 2]]);

    // Fries 1 + 2 = 3 against 2 left.
    $response = postRefund($sale, [
        'lines' => [
            ['menu_item_id' => $i['burger']->ulid, 'quantity' => 1],
            ['menu_item_id' => $i['fries']->ulid, 'quantity' => 1],
            ['menu_item_id' => $i['fries']->ulid, 'quantity' => 2],
        ],
        'restock' => true,
        'reason' => 'Wrong order',
    ])->assertStatus(422);

    expect($response->json('errors'))->toBe(['lines.1.quantity' => ["Line 2: only 2 Fries left to refund on {$sale->number}."]]);

    // Within the limit the duplicates become one refund line.
    postRefund($sale, [
        'lines' => [
            ['menu_item_id' => $i['fries']->ulid, 'quantity' => 1],
            ['menu_item_id' => $i['fries']->ulid, 'quantity' => 1],
        ],
        'restock' => true,
        'reason' => 'Wrong order',
    ])->assertCreated()->assertJsonPath('data.lines.0.quantity', 2);

    expect(SaleReturn::firstOrFail()->lines)->toHaveCount(1)
        ->and(returnMovements())->toBe([['Potatoes', 400], ['Frying oil', 50], ['Ketchup', 40]]);
});

it('counts earlier refunds when checking what is left', function () {
    $i = returnSetup();
    $sale = recordedSale([[$i['fries'], 3]]);

    postRefund($sale, refundBody([[$i['fries'], 2]]))->assertCreated();

    postRefund($sale, refundBody([[$i['fries'], 2]]))->assertStatus(422)
        ->assertJsonPath('message', "only 1 Fries left to refund on {$sale->number}.");
    postRefund($sale, refundBody([[$i['fries'], 1]]))->assertCreated();
});

it('refuses an item that is not on the sale with item_not_on_sale and writes nothing', function () {
    $i = returnSetup();
    $sale = recordedSale([[$i['burger'], 1]]);

    postRefund($sale, refundBody([[$i['burger'], 1], [$i['fries'], 1]], true))->assertStatus(422)
        ->assertJsonPath('code', 'item_not_on_sale')
        ->assertJsonPath('message', "Fries is not on {$sale->number}.")
        ->assertJsonPath('errors', ['lines.1.menu_item_id' => ["Line 2: Fries is not on {$sale->number}."]]);

    expect(SaleReturn::count())->toBe(0)
        ->and(StockMovement::where('reason', 'sale_return')->count())->toBe(0)
        ->and(DocumentSequence::where('type', 'RET')->exists())->toBeFalse();
});

it('answers 404 for an unknown sale', function () {
    $i = returnSetup();

    $this->postJson('/api/v1/sales/01jzzzzzzzzzzzzzzzzzzzzzzz/returns', refundBody([[$i['fries'], 1]]))
        ->assertNotFound()->assertJsonPath('code', 'not_found');
});

it('answers the same pos_reference as a replay when lines come in another order, writing only an audit row', function () {
    $i = returnSetup();
    $sale = recordedSale([[$i['burger'], 2], [$i['fries'], 2]]);

    $first = postRefund($sale, refundBody([[$i['burger'], 1], [$i['fries'], 2]], true, ['pos_reference' => 'till-1:ret-1']))->assertCreated();
    $movements = StockMovement::count();
    $audits = Activity::count();

    $replay = postRefund($sale, refundBody([[$i['fries'], 2], [$i['burger'], 1]], true, ['pos_reference' => 'till-1:ret-1', 'reason' => 'Another wording']))
        ->assertOk()
        ->assertJsonPath('message', 'Refund already recorded. No stock moved.')
        ->assertJsonPath('data.replayed', true)
        ->assertJsonPath('data.id', $first->json('data.id'));

    expect($replay->json('data.restocked'))->toBe($first->json('data.restocked'))
        ->and(SaleReturn::count())->toBe(1)
        ->and(StockMovement::count())->toBe($movements)
        ->and(Activity::count())->toBe($audits + 1)
        ->and(Activity::where('event', 'sale.return_replayed')->count())->toBe(1)
        ->and(DocumentSequence::where('type', 'RET')->value('last_value'))->toBe(1);
});

it('refuses a reused pos_reference with another restock choice, quantity, item or sale, and writes nothing', function (string $change) {
    $i = returnSetup();
    $sale = recordedSale([[$i['burger'], 3], [$i['fries'], 2]]);
    $other = recordedSale([[$i['burger'], 3], [$i['fries'], 2]]);
    postRefund($sale, refundBody([[$i['burger'], 1]], false, ['pos_reference' => 'till-1:ret-1']))->assertCreated();
    $movements = StockMovement::count();

    $target = $change === 'sale' ? $other : $sale;
    $body = match ($change) {
        'restock' => refundBody([[$i['burger'], 1]], true),
        'quantity' => refundBody([[$i['burger'], 2]], false),
        'item' => refundBody([[$i['fries'], 1]], false),
        'extra line' => refundBody([[$i['burger'], 1], [$i['fries'], 1]], false),
        'sale' => refundBody([[$i['burger'], 1]], false),
    };

    postRefund($target, array_merge($body, ['pos_reference' => 'till-1:ret-1']))->assertStatus(409)
        ->assertJsonPath('code', 'idempotency_conflict');

    expect(SaleReturn::count())->toBe(1)
        ->and(StockMovement::count())->toBe($movements)
        ->and(Activity::where('event', 'sale.return_replayed')->count())->toBe(0)
        ->and(DocumentSequence::where('type', 'RET')->value('last_value'))->toBe(1);
})->with(['restock', 'quantity', 'item', 'extra line', 'sale']);

/*
| R4: two identical retries pass the lookup together and the unique index stops the loser.
| A subclass makes the first lookup miss, which is exactly what the loser saw.
*/
function racingReturns(): SaleReturnService
{
    return new class(app(StockLedger::class), app(DocumentNumber::class)) extends SaleReturnService
    {
        private bool $missed = false;

        protected function findByReference(string $posReference): ?SaleReturn
        {
            if (! $this->missed) {
                $this->missed = true;

                return null;
            }

            return parent::findByReference($posReference);
        }
    };
}

it('answers the loser of a pos_reference race as a replay, and a different payload as a conflict (R4)', function () {
    $i = returnSetup();
    $sale = recordedSale([[$i['fries'], 3]]);
    $winner = app(SaleReturnService::class)->record($sale, [['item' => $i['fries'], 'quantity' => 1]], true, 'Wrong order', 'till-1:ret-1');

    $loser = racingReturns()->record($sale, [['item' => $i['fries'], 'quantity' => 1]], true, 'Wrong order', 'till-1:ret-1');

    expect($loser->replayed)->toBeTrue()
        ->and($loser->return->is($winner->return))->toBeTrue()
        ->and(SaleReturn::count())->toBe(1)
        ->and(StockMovement::where('reason', 'sale_return')->count())->toBe(3);

    expect(fn () => racingReturns()->record($sale, [['item' => $i['fries'], 'quantity' => 2]], true, 'Wrong order', 'till-1:ret-1'))
        ->toThrow(IdempotencyConflict::class);
    expect(SaleReturn::count())->toBe(1)->and(holding($i['potato']))->toBe(10000 - 600 + 200);
});

it('refuses an empty refund at the service', function () {
    $i = returnSetup();
    $sale = recordedSale([[$i['fries'], 1]]);

    expect(fn () => app(SaleReturnService::class)->record($sale, [], true, 'Wrong order'))->toThrow(InvalidArgumentException::class);
});

it('rejects invalid input with 422 and the documented message', function (array $overrides, string $key, string $message) {
    $i = returnSetup();
    $sale = recordedSale([[$i['fries'], 2]]);
    $body = array_merge(refundBody([[$i['fries'], 1]], false), $overrides);
    // A null override removes the field. Datasets are built before the database exists, so they name the
    // item '{fries}' and it is swapped for the real id here.
    $body = array_filter($body, fn ($value) => $value !== null);
    $body['lines'] = array_map(
        fn ($line) => is_array($line) && ($line['menu_item_id'] ?? null) === '{fries}' ? ['menu_item_id' => $i['fries']->ulid] + $line : $line,
        $body['lines'] ?? [],
    );
    if ($overrides === ['lines' => null]) {
        unset($body['lines']);
    }

    $response = postRefund($sale, $body)->assertStatus(422)->assertJsonPath('code', 'validation_failed');

    expect($response->json('errors')[$key] ?? [])->toContain($message)
        ->and(SaleReturn::count())->toBe(0)
        ->and(StockMovement::where('reason', 'sale_return')->count())->toBe(0);
})->with([
    'no restock' => [['restock' => null], 'restock', 'Choose whether the ingredients go back to stock.'],
    'restock as text' => [['restock' => 'true'], 'restock', 'Choose whether the ingredients go back to stock.'],
    'restock as 1' => [['restock' => 1], 'restock', 'Choose whether the ingredients go back to stock.'],
    'restock as 0' => [['restock' => 0], 'restock', 'Choose whether the ingredients go back to stock.'],
    'no reason' => [['reason' => null], 'reason', 'Give a reason for the refund.'],
    'blank reason' => [['reason' => '   '], 'reason', 'Give a reason for the refund.'],
    'long reason' => [['reason' => str_repeat('a', 201)], 'reason', 'The reason must be at most 200 characters.'],
    'reason with a line break' => [['reason' => "Wrong\norder"], 'reason', 'The reason cannot contain line breaks or other control characters.'],
    'no lines' => [['lines' => null], 'lines', 'Add at least one item to refund.'],
    'empty lines' => [['lines' => []], 'lines', 'Add at least one item to refund.'],
    'too many lines' => [['lines' => array_fill(0, 51, ['menu_item_id' => '01jzzzzzzzzzzzzzzzzzzzzzzz', 'quantity' => 1])], 'lines', 'A refund can hold at most 50 lines.'],
    'zero quantity' => [['lines' => [['menu_item_id' => '{fries}', 'quantity' => 0]]], 'lines.0.quantity', 'Line 1: the quantity must be a whole number from 1 to 1,000.'],
    'quantity too big' => [['lines' => [['menu_item_id' => '{fries}', 'quantity' => 1001]]], 'lines.0.quantity', 'Line 1: the quantity must be a whole number from 1 to 1,000.'],
    'quantity as text' => [['lines' => [['menu_item_id' => '{fries}', 'quantity' => '1']]], 'lines.0.quantity', 'Line 1: the quantity must be a whole number from 1 to 1,000.'],
    'no menu item' => [['lines' => [['quantity' => 1]]], 'lines.0.menu_item_id', 'Line 1: choose the menu item to refund.'],
]);

it('accepts restock only as a real boolean and keeps the sale itself untouched', function () {
    $i = returnSetup();
    $sale = recordedSale([[$i['fries'], 2]]);
    $line = SaleLine::firstOrFail();

    postRefund($sale, refundBody([[$i['fries'], 1]], false))->assertCreated();
    postRefund($sale, refundBody([[$i['fries'], 1]], true))->assertCreated();

    // D-049: a refund is a new document; the sale and its line are exactly what they were.
    expect($line->fresh()->quantity)->toBe(2)
        ->and($sale->fresh()->lines)->toHaveCount(1)
        ->and(SaleReturn::pluck('restock')->all())->toBe([false, true]);
});

it('lists refunded and refunded_units on every sale with a constant number of queries', function () {
    $i = returnSetup();
    $queries = function () {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $rows = test()->getJson('/api/v1/sales?per_page=100')->assertOk()->json('data');
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return [$count, $rows];
    };

    $sale = recordedSale([[$i['burger'], 3], [$i['fries'], 2]]);
    postRefund($sale, refundBody([[$i['burger'], 2], [$i['fries'], 1]]))->assertCreated();
    postRefund($sale, refundBody([[$i['burger'], 1]], true))->assertCreated();
    [$one, $rows] = $queries();

    // Lines are in menu item id order: Classic, then Fries.
    expect($rows[0]['lines'][0]['refunded'])->toBe(3)
        ->and($rows[0]['lines'][1]['refunded'])->toBe(1)
        ->and($rows[0]['refunded_units'])->toBe(4);

    foreach (range(1, 14) as $n) {
        $other = recordedSale([[$i['burger'], 2], [$i['fries'], 1]]);
        postRefund($other, refundBody([[$i['burger'], 1]], $n % 2 === 0))->assertCreated();
    }
    [$many, $rows] = $queries();

    expect($rows)->toHaveCount(15)
        ->and(collect($rows)->sum('refunded_units'))->toBe(4 + 14)
        ->and($many)->toBe($one);

    // A sale nobody refunded reports zeros, not nulls.
    $clean = recordedSale([[$i['fries'], 1]]);
    $row = collect(test()->getJson('/api/v1/sales')->json('data'))->firstWhere('id', $clean->ulid);
    expect($row['refunded_units'])->toBe(0)->and($row['lines'][0]['refunded'])->toBe(0);
});

it('labels a restock movement in the history with the return, the sale and the item', function () {
    $i = returnSetup();
    $year = now('UTC')->year;
    $sale = recordedSale([[$i['fries'], 2]]);
    postRefund($sale, refundBody([[$i['fries'], 1]], true))->assertCreated();

    $rows = collect($this->getJson("/api/v1/ingredients/{$i['potato']->ulid}/movements")->assertOk()->json('data'));
    $restock = $rows->firstWhere('reason', 'sale_return');

    expect($restock['quantity_delta'] ?? $restock['quantity'])->toBe(200)
        ->and($restock['reference']['type'])->toBe('sale_return')
        ->and($restock['reference']['number'])->toBe("RET-{$year}-000001")
        ->and($restock['reference']['label'])->toBe("RET-{$year}-000001 for {$sale->number}: Fries x1")
        ->and($restock['reference']['sale_id'])->toBe($sale->ulid);
});

it('audits sale.returned on the sale with the right description and properties', function () {
    $i = returnSetup();
    $sale = recordedSale([[$i['burger'], 2], [$i['fries'], 2]]);
    $year = now('UTC')->year;

    postRefund($sale, refundBody([[$i['fries'], 1]], false, ['pos_reference' => 'till-1:ret-1', 'reason' => 'Long wait']))->assertCreated();
    postRefund($sale, refundBody([[$i['fries'], 1], [$i['burger'], 1]], true))->assertCreated();

    [$made, $voided] = Activity::where('event', 'sale.returned')->orderBy('id')->get()->all();

    expect($made->subject_type)->toBe('sale')
        ->and($made->subject_id)->toBe($sale->id)
        ->and($made->description)->toBe("RET-{$year}-000001 for {$sale->number}: Fries x1 (not restocked)")
        ->and($made->properties->only(['return', 'lines', 'restock', 'reason', 'pos_reference'])->all())->toBe([
            'return' => "RET-{$year}-000001",
            'lines' => [['menu_item' => $i['fries']->ulid, 'quantity' => 1]],
            'restock' => false,
            'reason' => 'Long wait',
            'pos_reference' => 'till-1:ret-1',
        ])
        ->and($voided->description)->toBe("RET-{$year}-000002 for {$sale->number}: Classic Burger x1, Fries x1 (restocked)");
});

it('refunds and restocks whatever the stock level, even below zero (D-010)', function () {
    $i = returnSetup(cheese: 10);
    $sale = recordedSale([[$i['burger'], 1]]);
    expect(holding($i['cheese']))->toBe(-10);

    postRefund($sale, refundBody([[$i['burger'], 1]], false))->assertCreated();
    expect(holding($i['cheese']))->toBe(-10);

    $sale2 = recordedSale([[$i['burger'], 1]]);
    expect(holding($i['cheese']))->toBe(-30);
    postRefund($sale2, refundBody([[$i['burger'], 1]], true))->assertCreated();
    expect(holding($i['cheese']))->toBe(-10);
});

it('keeps on hand equal to the sum of the movements across interleaved sales and refunds', function () {
    $i = returnSetup();
    $a = recordedSale([[$i['burger'], 3], [$i['fries'], 2]]);          // beef -450, bun -3, cheese -60, potato -400
    $b = recordedSale([[$i['double'], 2]]);                             // beef -600, bun -2, cheese -80
    postRefund($a, refundBody([[$i['burger'], 1]], true))->assertCreated();                 // beef +150, bun +1, cheese +20
    $c = recordedSale([[$i['fries'], 1], [$i['burger'], 1]]);           // potato -200, beef -150, bun -1, cheese -20
    postRefund($b, refundBody([[$i['double'], 1]], false))->assertCreated();                // made: nothing
    postRefund($a, refundBody([[$i['burger'], 2], [$i['fries'], 1]], true))->assertCreated(); // beef +300, bun +2, cheese +40, potato +200
    postRefund($c, refundBody([[$i['fries'], 1]], true))->assertCreated();                  // potato +200
    postRefund($a, refundBody([[$i['burger'], 1]], true))->assertStatus(422);               // a's Classic is fully refunded

    // beef: 10000 - 450 - 600 + 150 - 150 + 300 = 9250
    // bun: 1000 - 3 - 2 + 1 - 1 + 2 = 997
    // cheese: 100000 - 60 - 80 + 20 - 20 + 40 = 99900
    // potato: 10000 - 400 + 0 - 200 + 200 + 200 = 9800
    $expected = ['beef' => 9250, 'bun' => 997, 'cheese' => 99900, 'potato' => 9800];

    foreach ($expected as $key => $value) {
        $sum = (int) StockMovement::where('ingredient_id', $i[$key]->id)->sum('quantity_delta');
        expect(holding($i[$key]))->toBe($value)->and($sum)->toBe($value);
    }
    expect(app(StockLedger::class)->onHandForAll()->get($i['beef']->id))->toBe(9250);
});

it('follows the X-POS-Key rule on refunds (D-028)', function () {
    $i = returnSetup();
    $sale = recordedSale([[$i['fries'], 3]]);

    postRefund($sale, refundBody([[$i['fries'], 1]]))->assertCreated();

    config(['services.pos.api_key' => 'secret']);
    postRefund($sale, refundBody([[$i['fries'], 1]]))->assertStatus(401)->assertJsonPath('code', 'unauthorized');
    $this->withHeader('X-POS-Key', 'secret')->postJson("/api/v1/sales/{$sale->ulid}/returns", refundBody([[$i['fries'], 1]]))->assertCreated();

    expect(SaleReturn::count())->toBe(2);
});

it('logs a refund on the pos channel', function () {
    config(['logging.channels.pos' => ['driver' => 'monolog', 'handler' => TestHandler::class]]);
    $i = returnSetup();
    $sale = recordedSale([[$i['fries'], 2]]);

    postRefund($sale, refundBody([[$i['fries'], 1]], true, ['pos_reference' => 'till-1:ret-1']))->assertCreated();

    $records = collect(Log::channel('pos')->getLogger()->getHandlers()[0]->getRecords())
        ->filter(fn ($record) => $record->message === 'Sale refunded');

    expect($records)->toHaveCount(1)
        ->and($records->first()->context['sale'])->toBe($sale->ulid)
        ->and($records->first()->context['restock'])->toBeTrue()
        ->and($records->first()->context['lines'])->toBe([['menu_item' => $i['fries']->ulid, 'quantity' => 1]]);
});
