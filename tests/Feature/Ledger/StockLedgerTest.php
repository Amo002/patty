<?php

use App\Enums\MovementReason;
use App\Exceptions\ImmutableMovement;
use App\Models\DeliveryLine;
use App\Models\Ingredient;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Services\StockLedger;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\Level;

beforeEach(function () {
    // Capture the stock channel in memory instead of writing storage/logs/stock-*.log.
    config(['logging.channels.stock' => ['driver' => 'monolog', 'handler' => TestHandler::class]]);

    $this->ledger = new StockLedger;
});

function stockRecords(): array
{
    return Log::channel('stock')->getLogger()->getHandlers()[0]->getRecords();
}

/** Movement helper: deliveries use a delivery line as reference, sales use a sale. */
function moveStock(Ingredient $ingredient, int $delta): StockMovement
{
    return $delta > 0
        ? test()->ledger->record($ingredient, $delta, MovementReason::Delivery, DeliveryLine::factory()->create())
        : test()->ledger->record($ingredient, $delta, MovementReason::Sale, Sale::factory()->create());
}

it('on-hand is the sum of signed deltas (worked example: 800)', function () {
    $beef = Ingredient::factory()->create(['name' => 'Beef']);

    foreach ([1000, -150, -150, 400, -300] as $delta) {
        moveStock($beef, $delta);
    }

    // 1000 - 150 - 150 + 400 - 300 = 800
    expect($this->ledger->onHand($beef))->toBe(800);
});

it('an ingredient with no movements has on-hand 0', function () {
    $beef = Ingredient::factory()->create();

    expect($this->ledger->onHand($beef))->toBe(0);
});

it('onHand only counts the given ingredient', function () {
    $beef = Ingredient::factory()->create();
    $bun = Ingredient::factory()->create();

    moveStock($beef, 500);
    moveStock($bun, 7);

    expect($this->ledger->onHand($beef))->toBe(500)
        ->and($this->ledger->onHand($bun))->toBe(7);
});

it('onHandForAll matches onHand for each ingredient, in one query', function () {
    $beef = Ingredient::factory()->create();
    $bun = Ingredient::factory()->create();
    $cheese = Ingredient::factory()->create();

    foreach ([1000, -150, -150] as $delta) {
        moveStock($beef, $delta);
    }
    moveStock($bun, 20);
    moveStock($bun, -22);

    DB::enableQueryLog();
    $all = $this->ledger->onHandForAll();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($queries)->toBe(1)
        ->and($all->get($beef->id))->toBe($this->ledger->onHand($beef))->toBe(700)
        ->and($all->get($bun->id))->toBe($this->ledger->onHand($bun))->toBe(-2)
        // An ingredient with no movements is absent from the map; callers default it to 0.
        ->and($all->has($cheese->id))->toBeFalse();
});

it('rejects a zero delta and writes nothing', function () {
    $beef = Ingredient::factory()->create();

    expect(fn () => $this->ledger->record($beef, 0, MovementReason::Delivery, DeliveryLine::factory()->create()))
        ->toThrow(InvalidArgumentException::class, 'delta cannot be 0');

    expect(StockMovement::count())->toBe(0);
});

it('updating a movement throws ImmutableMovement and leaves the row unchanged', function () {
    $movement = moveStock(Ingredient::factory()->create(), 100);

    expect(fn () => $movement->update(['quantity_delta' => 999]))->toThrow(ImmutableMovement::class);

    expect($movement->fresh()->quantity_delta)->toBe(100);
});

it('deleting a movement throws ImmutableMovement and leaves the row in place', function () {
    $movement = moveStock(Ingredient::factory()->create(), 100);

    expect(fn () => $movement->delete())->toThrow(ImmutableMovement::class);

    expect(StockMovement::count())->toBe(1);
});

it('stores the short morph name in reference_type, not the class name', function () {
    $line = DeliveryLine::factory()->create();
    $sale = Sale::factory()->create();
    $beef = Ingredient::factory()->create();

    $in = $this->ledger->record($beef, 100, MovementReason::Delivery, $line);
    $out = $this->ledger->record($beef, -10, MovementReason::Sale, $sale);

    expect($in->fresh()->reference_type)->toBe('delivery_line')
        ->and($out->fresh()->reference_type)->toBe('sale')
        ->and($in->fresh()->reference->is($line))->toBeTrue();
});

it('records occurred_at as given, and as now when omitted', function () {
    $beef = Ingredient::factory()->create();
    $when = now()->subDays(3)->startOfSecond();

    $past = $this->ledger->record($beef, 10, MovementReason::Delivery, DeliveryLine::factory()->create(), $when);
    $default = $this->ledger->record($beef, 10, MovementReason::Delivery, DeliveryLine::factory()->create());

    expect($past->fresh()->occurred_at->equalTo($when))->toBeTrue()
        ->and($default->fresh()->occurred_at->diffInSeconds(now(), true))->toBeLessThan(5);
});

it('logs one info line per movement with ingredient, delta, reason and reference', function () {
    $beef = Ingredient::factory()->create(['name' => 'Beef']);
    $line = DeliveryLine::factory()->create();

    $this->ledger->record($beef, 250, MovementReason::Delivery, $line);

    $records = stockRecords();

    expect($records)->toHaveCount(1)
        ->and($records[0]->level)->toBe(Level::Info)
        ->and($records[0]->context)->toMatchArray([
            'ingredient' => $beef->ulid,
            'ingredient_name' => 'Beef',
            'delta' => 250,
            'reason' => 'delivery',
            'reference_type' => 'delivery_line',
            'reference' => $line->id,
        ]);
});

it('a movement that takes on-hand below zero is recorded and logs a warning (D-010)', function () {
    $cheese = Ingredient::factory()->create(['name' => 'Cheese']);

    moveStock($cheese, 30);
    expect(collect(stockRecords())->where('level', Level::Warning))->toBeEmpty();

    moveStock($cheese, -40);

    $warnings = collect(stockRecords())->where('level', Level::Warning)->values();

    // The sale is never blocked: the movement exists and on-hand is negative.
    expect($this->ledger->onHand($cheese))->toBe(-10)
        ->and($warnings)->toHaveCount(1)
        ->and($warnings[0]->context['on_hand'])->toBe(-10)
        ->and($warnings[0]->context['ingredient_name'])->toBe('Cheese');
});

it('does not warn when on-hand lands exactly on zero', function () {
    $cheese = Ingredient::factory()->create();

    moveStock($cheese, 40);
    moveStock($cheese, -40);

    expect(collect(stockRecords())->where('level', Level::Warning))->toBeEmpty();
});
