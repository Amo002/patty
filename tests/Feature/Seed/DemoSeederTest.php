<?php

use App\Enums\PurchaseOrderStatus;
use App\Models\Ingredient;
use App\Models\MenuItem;
use App\Models\PurchaseOrder;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Services\IngredientService;
use App\Support\DemoTools;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/*
| The demo restaurant (PTY-22, D-036). Seeded through the real services, so these tests check the story
| the data tells: every PO case present, cheese negative, nothing without a reference.
*/

/*
| migrate:fresh ends with VACUUM, which SQLite refuses inside a transaction, and RefreshDatabase wraps every
| test in one. Leaving it first is safe: each test boots its own in-memory database (see tests/Pest.php).
*/
beforeEach(function () {
    DB::rollBack();
    $this->seed(DemoSeeder::class);
});

/** @return array<int, PurchaseOrder> the seven orders in number order */
function seededOrders(): array
{
    return PurchaseOrder::query()->with('lines')->orderBy('id')->get()->all();
}

function stockByName(): array
{
    return StockMovement::query()
        ->join('ingredients', 'ingredients.id', '=', 'stock_movements.ingredient_id')
        ->groupBy('ingredients.name')->orderBy('ingredients.name')
        ->selectRaw('ingredients.name as name, SUM(quantity_delta) as on_hand')
        ->pluck('on_hand', 'name')->map(fn ($v) => (int) $v)->all();
}

it('creates the catalogue the brief describes', function () {
    expect(Ingredient::query()->count())->toBe(12)
        ->and(MenuItem::query()->count())->toBe(5)
        ->and(DB::table('suppliers')->count())->toBe(4);

    // The Classic Burger is exactly the brief: 150 g beef, 1 bun, 20 g cheese.
    $classic = MenuItem::query()->where('name', 'Classic Burger')->firstOrFail();
    $recipe = $classic->recipeLines()->with('ingredient')->get()->mapWithKeys(fn ($l) => [$l->ingredient->name => $l->quantity])->all();
    expect($recipe)->toBe(['Beef' => 150, 'Bun' => 1, 'Cheese' => 20]);

    // Beef alone overrides the under-tolerance, at 2%.
    expect(Ingredient::query()->where('name', 'Beef')->value('under_tolerance_bps'))->toBe(200)
        ->and(Ingredient::query()->whereNotNull('under_tolerance_bps')->count())->toBe(1);
});

it('uses fictional supplier contacts', function () {
    foreach (DB::table('suppliers')->get() as $supplier) {
        expect($supplier->email)->toEndWith('.example')
            ->and($supplier->phone)->toStartWith('+962 6 5');
    }
});

it('covers every purchase order case in seven orders, each with a number', function () {
    $orders = seededOrders();
    expect($orders)->toHaveCount(7);

    foreach ($orders as $po) {
        expect($po->number)->toMatch('/^PO-\d{4}-\d{4}$/');
    }

    [$full, $partial, $draft, $underTolerance, $overReceipt, $shortClosed, $sent] = $orders;

    // (a) closed by full receipt, over two deliveries, nothing outstanding
    expect($full->status)->toBe(PurchaseOrderStatus::Closed)
        ->and($full->short_closed)->toBeFalse()
        ->and(DB::table('deliveries')->where('purchase_order_id', $full->id)->count())->toBe(2)
        ->and($full->lines->every(fn ($l) => $l->received() === $l->quantity_ordered))->toBeTrue();

    // (b) closed within under-tolerance: a normal close, short_closed stays false (D-013)
    expect($underTolerance->status)->toBe(PurchaseOrderStatus::Closed)
        ->and($underTolerance->short_closed)->toBeFalse()
        ->and($underTolerance->lines->every(fn ($l) => $l->received() < $l->quantity_ordered && $l->isComplete()))->toBeTrue();

    // (c) closed with over-receipt, within the tolerance
    expect($overReceipt->status)->toBe(PurchaseOrderStatus::Closed)
        ->and($overReceipt->lines->every(fn ($l) => $l->received() > $l->quantity_ordered && $l->received() <= $l->maxReceivable()))->toBeTrue();

    // (d) short-closed: closed by hand with quantity still short of the tolerance
    expect($shortClosed->status)->toBe(PurchaseOrderStatus::Closed)
        ->and($shortClosed->short_closed)->toBeTrue()
        ->and($shortClosed->lines->contains(fn ($l) => ! $l->isComplete()))->toBeTrue();

    // (e) partially received: still open, something outstanding, something delivered
    expect($partial->status)->toBe(PurchaseOrderStatus::Received)
        ->and($partial->lines->contains(fn ($l) => ! $l->isComplete()))->toBeTrue()
        ->and($partial->lines->contains(fn ($l) => $l->received() > 0))->toBeTrue();

    // (f) sent, nothing arrived; (g) draft
    expect($sent->status)->toBe(PurchaseOrderStatus::Sent)
        ->and(DB::table('deliveries')->where('purchase_order_id', $sent->id)->count())->toBe(0)
        ->and($draft->status)->toBe(PurchaseOrderStatus::Draft)
        ->and($draft->sent_at)->toBeNull();
});

it('records exactly 120 sales with sequential till references and one replay', function () {
    expect(Sale::query()->count())->toBe(DemoSeeder::SALES)
        ->and(Sale::query()->where('pos_reference', 'seed-till-1:00001')->exists())->toBeTrue()
        ->and(Sale::query()->where('pos_reference', 'seed-till-1:00120')->exists())->toBeTrue()
        ->and(Sale::query()->whereNull('pos_reference')->count())->toBe(0)
        ->and(Activity::query()->where('event', 'sale.replayed')->count())->toBe(1);

    // A replay moves no stock: one set of sale movements per sale, so no sale has two.
    $perSale = StockMovement::query()->where('reason', 'sale')->where('reference_type', 'sale')
        ->selectRaw('reference_id, ingredient_id, COUNT(*) as n')->groupBy('reference_id', 'ingredient_id')->pluck('n');
    expect($perSale->max())->toBe(1);
});

it('puts the sales in the last three days, in the lunch and dinner peaks, never in the future', function () {
    $sales = Sale::query()->get();

    expect($sales->min('sold_at')->greaterThanOrEqualTo(today()->subDays(3)))->toBeTrue()
        ->and($sales->max('sold_at')->lessThanOrEqualTo(now()))->toBeTrue();

    foreach ($sales as $sale) {
        $hour = $sale->sold_at->hour;
        expect(($hour >= 12 && $hour <= 14) || ($hour >= 19 && $hour <= 22))->toBeTrue();
    }

    // Mostly Classic and Fries.
    $top = $sales->groupBy('menu_item_id')->map->count()->sortDesc()->keys()->take(2)->all();
    $names = MenuItem::query()->whereIn('id', $top)->pluck('name')->all();
    expect($names)->toContain('Classic Burger', 'Fries');
});

it('ends with cheese negative and every other ingredient positive (D-010)', function () {
    $stock = stockByName();

    expect($stock['Cheese'])->toBeLessThan(0);

    foreach ($stock as $name => $onHand) {
        if ($name !== 'Cheese') {
            expect($onHand)->toBeGreaterThan(0, "{$name} should be in stock");
        }
    }
});

it('leaves no stock movement without a real reference', function () {
    expect(StockMovement::query()->whereNull('reference_type')->orWhereNull('reference_id')->count())->toBe(0);

    foreach (StockMovement::query()->get() as $movement) {
        expect($movement->reference)->not->toBeNull();
    }
});

it('marks every audit entry with channel api and seed true', function () {
    expect(Activity::query()->count())->toBeGreaterThan(100);

    foreach (Activity::query()->get() as $activity) {
        expect($activity->properties['channel'])->toBe('api')
            ->and($activity->properties['seed'])->toBeTrue();
    }
});

it('stops marking audit entries as seed once the seeder has finished', function () {
    $ingredient = Ingredient::factory()->create();
    app(IngredientService::class)->update($ingredient, ['name' => 'Renamed after the seed']);

    $entry = Activity::query()->latest('id')->first();

    expect($entry->properties->has('seed'))->toBeFalse();
});

it('points every image_path at a file that exists, and keeps the photos under 1 MB in total', function () {
    $paths = Ingredient::query()->pluck('image_path')->merge(MenuItem::query()->pluck('image_path'))->filter();

    foreach ($paths as $path) {
        expect(is_file(public_path($path)))->toBeTrue("{$path} is missing");
    }

    $bytes = collect(glob(public_path('images/seed/*/*.webp')) ?: [])->sum(fn ($file) => filesize($file));
    expect($bytes)->toBeLessThan(1_000_000);
});

it('gives the same counts and the same final stock on every run', function () {
    $snapshot = fn () => [
        'counts' => DemoTools::counts(),
        'stock' => stockByName(),
        'po' => PurchaseOrder::query()->orderBy('id')->get()->map(fn ($po) => [$po->status->value, $po->short_closed])->all(),
        'units_sold' => (int) Sale::query()->sum('quantity'),
    ];

    $first = $snapshot();
    DemoTools::reset();

    expect($snapshot())->toBe($first);
});
