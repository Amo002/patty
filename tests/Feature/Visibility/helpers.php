<?php

use App\Enums\Unit;
use App\Models\Ingredient;
use App\Models\Supplier;
use Illuminate\Testing\TestResponse;

/*
| Shared by the Visibility tests (required with require_once, because Pest loads every
| test file in one process and a function may only be declared once).
*/

/** Every JSON response goes through here so D-031 (no integer ids) is checked on each one. */
function visCall(string $method, string $url, array $data = []): TestResponse
{
    $response = $method === 'GET' ? test()->getJson($url) : test()->postJson($url, $data);
    expect($response->json())->assertNoIntegerIds();

    return $response;
}

function visIngredient(string $name, Unit $unit = Unit::Gram): Ingredient
{
    return Ingredient::query()->where('name', $name)->first()
        ?? Ingredient::factory()->unit($unit)->create(['name' => $name]);
}

/**
 * Create a purchase order through the API, optionally send it.
 *
 * @param  array<string, int>  $lines  ingredient name => quantity ordered
 * @return array{id: string, lines: array<string, string>} order ulid and line ulids by ingredient name
 */
function visOrder(array $lines, bool $send = true): array
{
    $supplier = Supplier::query()->first() ?? Supplier::factory()->create();

    $created = visCall('POST', '/api/v1/purchase-orders', [
        'supplier_id' => $supplier->ulid,
        'lines' => collect($lines)->map(fn (int $quantity, string $name) => [
            'ingredient_id' => visIngredient($name)->ulid,
            'quantity_ordered' => $quantity,
        ])->values()->all(),
    ])->assertCreated();

    $id = $created->json('data.id');

    if ($send) {
        visCall('POST', "/api/v1/purchase-orders/{$id}/send")->assertOk();
    }

    return ['id' => $id, 'lines' => collect($created->json('data.lines'))->pluck('id', 'ingredient.name')->all()];
}

/**
 * Receive quantities against an order's lines.
 *
 * @param  array{id: string, lines: array<string, string>}  $order
 * @param  array<string, int>  $quantities  ingredient name => quantity received
 */
function visReceive(array $order, array $quantities): TestResponse
{
    return visCall('POST', "/api/v1/purchase-orders/{$order['id']}/deliveries", [
        'lines' => collect($quantities)->map(fn (int $quantity, string $name) => [
            'purchase_order_line_id' => $order['lines'][$name],
            'quantity' => $quantity,
        ])->values()->all(),
    ]);
}

/** Incoming of one ingredient as E27 reports it. */
function visIncoming(string $name): int
{
    $rows = visCall('GET', '/api/v1/stock?per_page=100')->assertOk()->json('data');

    return collect($rows)->firstWhere('ingredient.name', $name)['incoming'];
}
