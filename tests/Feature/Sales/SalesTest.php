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

/** @return array{beef: Ingredient, bun: Ingredient, cheese: Ingredient, burger: MenuItem} */
function saleSetup(int $beef = 1000, int $bun = 10, int $cheese = 30): array
{
    $i = [
        'beef' => Ingredient::factory()->create(['name' => 'Beef', 'unit' => Unit::Gram]),
        'bun' => Ingredient::factory()->create(['name' => 'Bun', 'unit' => Unit::Piece]),
        'cheese' => Ingredient::factory()->create(['name' => 'Cheese', 'unit' => Unit::Gram]),
    ];

    $i['burger'] = MenuItem::factory()->create(['name' => 'Classic Burger']);
    foreach ([['beef', 150], ['bun', 1], ['cheese', 20]] as [$key, $qty]) {
        RecipeLine::factory()->create(['menu_item_id' => $i['burger']->id, 'ingredient_id' => $i[$key]->id, 'quantity' => $qty]);
    }

    // Stock goes in through the ledger, the only writer, as a delivery would.
    $ledger = app(StockLedger::class);
    foreach ([['beef', $beef], ['bun', $bun], ['cheese', $cheese]] as [$key, $qty]) {
        $ledger->record($i[$key], $qty, MovementReason::Delivery, DeliveryLine::factory()->create());
    }

    return $i;
}

function onHandOf(Ingredient $ingredient): int
{
    return app(StockLedger::class)->onHand($ingredient);
}

function postSale(array $i, array $overrides = [])
{
    return test()->postJson('/api/v1/sales', array_merge([
        'menu_item_id' => $i['burger']->ulid,
        'quantity' => 2,
    ], $overrides));
}

it('deducts the recipe times the quantity as three sale movements (T1)', function () {
    $i = saleSetup(1000, 1000, 1000);

    $response = postSale($i)->assertCreated()
        ->assertJsonPath('data.replayed', false)
        ->assertJsonPath('data.quantity', 2)
        ->assertJsonPath('data.menu_item.name', 'Classic Burger');
    expect($response->json())->assertNoIntegerIds();

    $sale = Sale::firstOrFail();
    expect($response->json('data.id'))->toBe($sale->ulid)
        ->and($response->json('data.number'))->toBe('SALE-'.now('UTC')->year.'-000001');

    $movements = StockMovement::where('reference_type', 'sale')->where('reference_id', $sale->id)->get();
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

    $response = postSale($i, ['pos_reference' => 'till-1:0001', 'quantity' => 3])
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
    $other = MenuItem::factory()->create(['name' => 'Plain Patty']);
    RecipeLine::factory()->create(['menu_item_id' => $other->id, 'ingredient_id' => $i['beef']->id, 'quantity' => 100]);
    postSale($i, ['pos_reference' => 'till-1:0001'])->assertCreated();

    postSale($i, ['pos_reference' => 'till-1:0001', 'menu_item_id' => $other->ulid])
        ->assertStatus(409)
        ->assertJsonPath('code', 'idempotency_conflict');

    expect(Sale::count())->toBe(1)->and(onHandOf($i['beef']))->toBe(700);
});

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
    $winner = app(SaleService::class)->record($i['burger'], 2, 'till-1:0001');

    $loser = racingService()->record($i['burger'], 2, 'till-1:0001');

    expect($loser->replayed)->toBeTrue()
        ->and($loser->sale->is($winner->sale))->toBeTrue()
        ->and(Sale::count())->toBe(1)
        ->and(StockMovement::where('reason', 'sale')->count())->toBe(3)
        ->and(onHandOf($i['beef']))->toBe(700);
});

it('answers the loser of a race with a different payload as idempotency_conflict (R4, D-029)', function () {
    $i = saleSetup();
    app(SaleService::class)->record($i['burger'], 2, 'till-1:0001');

    expect(fn () => racingService()->record($i['burger'], 3, 'till-1:0001'))
        ->toThrow(IdempotencyConflict::class);

    expect(Sale::count())->toBe(1)->and(onHandOf($i['beef']))->toBe(700);
});

it('rejects a menu item without a recipe with menu_item_not_sellable', function () {
    $item = MenuItem::factory()->create(['name' => 'Plain Bun']);

    $response = $this->postJson('/api/v1/sales', ['menu_item_id' => $item->ulid, 'quantity' => 1])
        ->assertStatus(422)
        ->assertJsonPath('code', 'menu_item_not_sellable');
    expect($response->json())->assertNoIntegerIds();
    expect(Sale::count())->toBe(0);
});

it('rejects invalid input with 422', function (array $overrides) {
    $i = saleSetup();

    $response = postSale($i, $overrides)->assertStatus(422)->assertJsonPath('code', 'validation_failed');
    expect($response->json())->assertNoIntegerIds();
    expect(Sale::count())->toBe(0)->and(onHandOf($i['beef']))->toBe(1000);
})->with([
    'quantity 0' => [['quantity' => 0]],
    'quantity above 1000' => [['quantity' => 1001]],
    'quantity true' => [['quantity' => true]],
    'quantity as string' => [['quantity' => '2']],
    'unknown menu item' => [['menu_item_id' => '01jzzzzzzzzzzzzzzzzzzzzzzz']],
    'numeric menu item id' => [['menu_item_id' => 1]],
    'reference with spaces' => [['pos_reference' => 'till 1']],
    'reference too long' => [['pos_reference' => str_repeat('a', 65)]],
    'sold_at an hour ahead' => [['sold_at' => '+1 hour']],
]);

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
        'menu_item_id' => $i['burger']->ulid, 'quantity' => 1, 'sold_at' => $when->toIso8601String(),
    ])->assertCreated();

    expect(Sale::firstOrFail()->sold_at->equalTo($when))->toBeTrue()
        ->and(StockMovement::where('reason', 'sale')->first()->occurred_at->equalTo($when))->toBeTrue();
});

it('reports on_hand_after as the balance at that sale, so a replay and the list match the first answer', function () {
    $i = saleSetup(1000, 10, 100);

    $a = postSale($i, ['pos_reference' => 'till-1:A'])->assertCreated();
    postSale($i, ['pos_reference' => 'till-1:B', 'quantity' => 3])->assertCreated();
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

it('uses the same number of queries to list one sale as ten', function () {
    $i = saleSetup(1000000, 1000000, 1000000);
    $queries = function () {
        DB::flushQueryLog();
        DB::enableQueryLog();
        test()->getJson('/api/v1/sales?per_page=100')->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    };

    postSale($i)->assertCreated();
    $one = $queries();
    foreach (range(1, 9) as $n) {
        postSale($i)->assertCreated();
    }

    expect(Sale::count())->toBe(10)->and($queries())->toBe($one);
});

it('stores a sold_at sent with a UTC offset as the same instant in UTC', function () {
    $i = saleSetup();
    // An Amman till (+03:00) sending "now": inside the 5-minute window, but 3 hours off if stored unconverted.
    $when = now()->subMinute()->startOfSecond();
    $local = $when->copy()->setTimezone('Asia/Amman')->toIso8601String();
    expect($local)->toEndWith('+03:00');

    $response = $this->postJson('/api/v1/sales', [
        'menu_item_id' => $i['burger']->ulid, 'quantity' => 1, 'sold_at' => $local,
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
    $this->withHeader('X-POS-Key', 'wrong')->postJson('/api/v1/sales', ['menu_item_id' => $i['burger']->ulid, 'quantity' => 2])->assertStatus(401);
    $this->withHeader('X-POS-Key', 'secret')->postJson('/api/v1/sales', ['menu_item_id' => $i['burger']->ulid, 'quantity' => 2])->assertCreated();

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
    $response = postSale($i)->assertCreated();

    $this->putJson("/api/v1/menu-items/{$i['burger']->ulid}/recipe", ['lines' => [
        ['ingredient_id' => $i['beef']->ulid, 'quantity' => 999],
    ]])->assertOk();

    expect(StockMovement::where('reason', 'sale')->pluck('quantity_delta', 'ingredient_id')->all())->toBe([
        $i['beef']->id => -300,
        $i['bun']->id => -2,
        $i['cheese']->id => -40,
    ]);

    // Replaying the old sale still shows the old deductions, not the new recipe.
    $sale = Sale::firstOrFail();
    $this->getJson('/api/v1/sales')->assertOk()
        ->assertJsonPath('data.0.id', $sale->ulid)
        ->assertJsonCount(3, 'data.0.deductions');
    expect($response->json('data.deductions'))->toHaveCount(3);
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

    $this->postJson('/api/v1/sales', ['menu_item_id' => $i['burger']->ulid, 'quantity' => 2])->assertStatus(500);

    expect(Sale::count())->toBe(0)
        ->and(StockMovement::count())->toBe($movementsBefore)
        ->and(Activity::where('event', 'sale.recorded')->count())->toBe(0)
        // The SALE counter rolled back too, so the next sale is still number 1.
        ->and(DocumentSequence::where('type', 'SALE')->value('last_value'))->toBeNull();
});

it('lists sales newest first, paginated, with deductions (E26)', function () {
    $i = saleSetup(100000, 100000, 100000);
    foreach ([3, 2, 1] as $hoursAgo) {
        $this->postJson('/api/v1/sales', [
            'menu_item_id' => $i['burger']->ulid,
            'quantity' => $hoursAgo,
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
    expect(collect($response->json('data'))->pluck('quantity')->all())->toBe([1, 2])
        ->and($response->json('data.0.deductions'))->toHaveCount(3);

    $page2 = $this->getJson('/api/v1/sales?per_page=2&page=2')->assertOk();
    expect(collect($page2->json('data'))->pluck('quantity')->all())->toBe([3]);

    $this->getJson('/api/v1/sales?per_page=101')->assertStatus(422);
});
