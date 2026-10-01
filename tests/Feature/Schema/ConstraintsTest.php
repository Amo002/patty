<?php

use App\Models\Ingredient;
use App\Models\MenuItem;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\RecipeLine;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Supplier;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

it('rejects the same ingredient twice in one recipe', function () {
    $recipe = RecipeLine::factory()->create();

    RecipeLine::factory()->create([
        'menu_item_id' => $recipe->menu_item_id,
        'ingredient_id' => $recipe->ingredient_id,
    ]);
})->throws(QueryException::class, 'UNIQUE constraint failed');

it('rejects the same ingredient twice on one purchase order', function () {
    $line = PurchaseOrderLine::factory()->create();

    PurchaseOrderLine::factory()->create([
        'purchase_order_id' => $line->purchase_order_id,
        'ingredient_id' => $line->ingredient_id,
    ]);
})->throws(QueryException::class, 'UNIQUE constraint failed');

it('treats names as equal regardless of case (G6)', function (string $model) {
    $model::factory()->create(['name' => 'Beef']);

    $model::factory()->create(['name' => 'beef']);
})->with([
    'ingredient' => [Ingredient::class],
    'supplier' => [Supplier::class],
    'menu item' => [MenuItem::class],
])->throws(QueryException::class, 'UNIQUE constraint failed');

it('rejects a replayed POS reference', function () {
    Sale::factory()->create(['pos_reference' => 'POS-1001']);

    Sale::factory()->create(['pos_reference' => 'POS-1001']);
})->throws(QueryException::class, 'UNIQUE constraint failed');

it('allows many sales without a POS reference', function () {
    Sale::factory()->count(2)->create(['pos_reference' => null]);

    expect(Sale::count())->toBe(2);
});

it('refuses to update a stock movement (T19)', function () {
    $movement = StockMovement::factory()->create(['quantity_delta' => -5]);

    DB::table('stock_movements')->where('id', $movement->id)->update(['quantity_delta' => -500]);
})->throws(QueryException::class, 'stock_movements is append-only');

it('refuses to delete a stock movement (T19)', function () {
    StockMovement::factory()->create();

    DB::table('stock_movements')->delete();
})->throws(QueryException::class, 'stock_movements is append-only');

it('leaves the stored movement untouched after a refused update', function () {
    $movement = StockMovement::factory()->create(['quantity_delta' => -5]);

    try {
        DB::table('stock_movements')->where('id', $movement->id)->update(['quantity_delta' => -500]);
    } catch (QueryException) {
        // Expected: the trigger aborts the statement.
    }

    expect($movement->fresh()->quantity_delta)->toBe(-5);
});

it('defaults short_closed to false on a new purchase order', function () {
    $order = PurchaseOrder::factory()->create();

    expect($order->fresh()->short_closed)->toBeFalse();
});
