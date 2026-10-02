<?php

use App\Models\Delivery;
use App\Models\Ingredient;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Sale;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

it('gives a created model a 26-character ULID', function () {
    $ingredient = Ingredient::factory()->create();

    expect($ingredient->ulid)->toHaveLength(26)
        ->and(Str::isUlid($ingredient->ulid))->toBeTrue();
});

it('uses the ulid as the route key', function () {
    $ingredient = Ingredient::factory()->create();

    expect($ingredient->getRouteKeyName())->toBe('ulid')
        ->and($ingredient->getRouteKey())->toBe($ingredient->ulid);
});

it('keeps an auto-incrementing integer primary key', function () {
    $ingredient = Ingredient::factory()->create();

    expect($ingredient->getIncrementing())->toBeTrue()
        ->and($ingredient->getKeyType())->toBe('int')
        ->and($ingredient->id)->toBeInt();
});

it('never serialises id or any *_id column', function () {
    $line = PurchaseOrderLine::factory()->create();

    $keys = array_keys($line->fresh()->toArray());

    expect($keys)->toContain('ulid', 'quantity_ordered')
        ->and($keys)->not->toContain('id', 'purchase_order_id', 'ingredient_id');
});

it('hides internal keys on every addressable model', function (string $model) {
    $keys = array_keys($model::factory()->create()->fresh()->toArray());

    $internal = array_filter($keys, fn ($key) => $key === 'id' || str_ends_with($key, '_id'));

    expect($internal)->toBe([]);
})->with([
    'ingredient' => [Ingredient::class],
    'supplier' => [Supplier::class],
    'purchase order' => [PurchaseOrder::class],
    'delivery' => [Delivery::class],
    'sale' => [Sale::class],
]);

it('resolves an uppercase ULID to the same record', function () {
    $ingredient = Ingredient::factory()->create();

    $found = $ingredient->resolveRouteBinding(strtoupper($ingredient->ulid));

    expect($found?->is($ingredient))->toBeTrue();
});

it('stores a caller-supplied ULID in lowercase', function () {
    $ingredient = Ingredient::factory()->create(['ulid' => '01ARZ3NDEKTSV4RRFFQ69G5FAV']);

    expect($ingredient->fresh()->ulid)->toBe('01arz3ndektsv4rrffq69g5fav');
});

it('resolves a model by its ulid and not by its integer id', function () {
    $ingredient = Ingredient::factory()->create();

    expect($ingredient->resolveRouteBinding($ingredient->ulid)->is($ingredient))->toBeTrue();

    $ingredient->resolveRouteBinding((string) $ingredient->id);
})->throws(ModelNotFoundException::class);
