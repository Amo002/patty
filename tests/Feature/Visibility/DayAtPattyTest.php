<?php

use App\Enums\Unit;
use App\Models\Delivery;
use App\Models\Ingredient;
use App\Models\MenuItem;
use App\Models\RecipeLine;
use App\Models\Sale;
use App\Models\StockMovement;
use App\Models\Supplier;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;

/*
| T8 "A day at Patty" (flows.md). Every row of the table runs through the real HTTP
| endpoints, and after every row on-hand AND incoming of beef, bun and cheese are read
| back from E27 and compared with the table. Nothing is mocked.
*/

afterEach(fn () => Carbon::setTestNow());

/** Every response is checked for leaked integer ids (D-031). */
function dayCall(string $method, string $url, array $data = []): TestResponse
{
    $response = $method === 'GET' ? test()->getJson($url) : test()->postJson($url, $data);
    expect($response->json())->assertNoIntegerIds();

    return $response;
}

/**
 * E27 read back as name => [on_hand, incoming].
 *
 * @return array<string, array{0: int, 1: int}>
 */
function dayStock(): array
{
    $rows = dayCall('GET', '/api/v1/stock')->assertOk()->json('data');

    return collect($rows)->mapWithKeys(fn ($row) => [$row['ingredient']['name'] => [$row['on_hand'], $row['incoming']]])->all();
}

/** The row of the flows.md table: on-hand beef, bun, cheese then incoming beef, bun, cheese. */
function dayExpect(array $onHand, array $incoming, string $when): void
{
    expect(dayStock())->toBe([
        'Beef' => [$onHand[0], $incoming[0]],
        'Bun' => [$onHand[1], $incoming[1]],
        'Cheese' => [$onHand[2], $incoming[2]],
    ], "stock after {$when}");
}

/** Create and send a PO for one ingredient; returns [order ulid, line ulid]. */
function dayOrder(Supplier $supplier, Ingredient $ingredient, int $quantity): array
{
    $created = dayCall('POST', '/api/v1/purchase-orders', [
        'supplier_id' => $supplier->ulid,
        'lines' => [['ingredient_id' => $ingredient->ulid, 'quantity_ordered' => $quantity]],
    ])->assertCreated();

    $id = $created->json('data.id');
    dayCall('POST', "/api/v1/purchase-orders/{$id}/send")->assertOk();

    return [$id, $created->json('data.lines.0.id')];
}

function dayReceive(string $order, string $line, int $quantity, string $at): TestResponse
{
    return dayCall('POST', "/api/v1/purchase-orders/{$order}/deliveries", [
        'lines' => [['purchase_order_line_id' => $line, 'quantity' => $quantity]],
        'received_at' => "2026-10-02T{$at}:00Z",
    ]);
}

function daySell(MenuItem $burger, int $quantity, string $reference, string $at): TestResponse
{
    return dayCall('POST', '/api/v1/sales', [
        'menu_item_id' => $burger->ulid,
        'quantity' => $quantity,
        'pos_reference' => $reference,
        'sold_at' => "2026-10-02T{$at}:00Z",
    ]);
}

function dayAt(string $time): void
{
    Carbon::setTestNow("2026-10-02 {$time}:00", 'UTC');
}

it('runs a whole day at Patty and the numbers match the table at every step (T8)', function () {
    $beef = Ingredient::factory()->unit(Unit::Gram)->create(['name' => 'Beef']);
    $bun = Ingredient::factory()->unit(Unit::Piece)->create(['name' => 'Bun']);
    $cheese = Ingredient::factory()->unit(Unit::Gram)->create(['name' => 'Cheese']);
    $supplier = Supplier::factory()->create();

    $burger = MenuItem::factory()->create(['name' => 'Classic Burger']);
    foreach ([[$beef, 150], [$bun, 1], [$cheese, 20]] as [$ingredient, $quantity]) {
        RecipeLine::factory()->create(['menu_item_id' => $burger->id, 'ingredient_id' => $ingredient->id, 'quantity' => $quantity]);
    }

    dayExpect([0, 0, 0], [0, 0, 0], 'setup');

    // 08:00 three orders, all sent.
    dayAt('08:00');
    [$orderA, $lineA] = dayOrder($supplier, $beef, 3000);
    [$orderB, $lineB] = dayOrder($supplier, $bun, 40);
    [$orderC, $lineC] = dayOrder($supplier, $cheese, 500);
    dayExpect([0, 0, 0], [3000, 40, 500], '08:00');

    // 09:00 GRN-1: beef 2400 on A. A is partially received.
    dayAt('09:00');
    dayReceive($orderA, $lineA, 2400, '09:00')->assertCreated()->assertJsonPath('data.purchase_order.status', 'received');
    dayExpect([2400, 0, 0], [600, 40, 500], '09:00');

    // 09:30 GRN-2: bun 40 on B. B closes by itself.
    dayAt('09:30');
    dayReceive($orderB, $lineB, 40, '09:30')->assertCreated()->assertJsonPath('data.purchase_order.status', 'closed');
    dayExpect([2400, 40, 0], [600, 0, 500], '09:30');

    // 10:00 GRN-3: cheese 300 on C.
    dayAt('10:00');
    dayReceive($orderC, $lineC, 300, '10:00')->assertCreated()->assertJsonPath('data.purchase_order.status', 'received');
    dayExpect([2400, 40, 300], [600, 0, 200], '10:00');

    // 12:00, 12:30, 13:00 sales; cheese goes negative at 13:00 and the sale is still accepted.
    dayAt('12:00');
    daySell($burger, 6, 'till-1:0001', '12:00')->assertCreated();
    dayExpect([1500, 34, 180], [600, 0, 200], '12:00');

    dayAt('12:30');
    daySell($burger, 4, 'till-1:0002', '12:30')->assertCreated();
    dayExpect([900, 30, 100], [600, 0, 200], '12:30');

    dayAt('13:00');
    $third = daySell($burger, 6, 'till-1:0003', '13:00')->assertCreated();
    dayExpect([0, 24, -20], [600, 0, 200], '13:00');
    $negative = collect(dayCall('GET', '/api/v1/stock')->json('data'))->firstWhere('ingredient.name', 'Cheese');
    expect($negative['is_negative'])->toBeTrue();
    expect(dayCall('GET', '/api/v1/dashboard')->json('data.negative_count'))->toBe(1);

    // 14:55 over-delivery: 2400 + 800 = 3200 > 3150. Rejected, nothing saved.
    dayAt('14:55');
    dayReceive($orderA, $lineA, 800, '14:55')->assertStatus(422)->assertJsonPath('code', 'over_delivery');
    dayExpect([0, 24, -20], [600, 0, 200], '14:55');

    // 15:00 GRN-4: beef 630 (total 3030). A closes, over-received 30.
    dayAt('15:00');
    dayReceive($orderA, $lineA, 630, '15:00')->assertCreated()->assertJsonPath('data.purchase_order.status', 'closed');
    dayExpect([630, 24, -20], [0, 0, 200], '15:00');

    // 15:30 GRN-5: cheese 200. C closes, cheese recovers.
    dayAt('15:30');
    dayReceive($orderC, $lineC, 200, '15:30')->assertCreated()->assertJsonPath('data.purchase_order.status', 'closed');
    dayExpect([630, 24, 180], [0, 0, 0], '15:30');

    // 16:00 the till retries till-1:0003 with the same payload: a replay, nothing moves.
    dayAt('16:00');
    daySell($burger, 6, 'till-1:0003', '13:00')->assertOk()
        ->assertJsonPath('data.replayed', true)
        ->assertJsonPath('data.id', $third->json('data.id'));
    dayExpect([630, 24, 180], [0, 0, 0], '16:00');

    // 16:05 till-1:0003 again as x2: a conflict, nothing moves.
    dayAt('16:05');
    daySell($burger, 2, 'till-1:0003', '16:05')->assertStatus(409)->assertJsonPath('code', 'idempotency_conflict');
    dayExpect([630, 24, 180], [0, 0, 0], '16:05');

    // 17:00 the last sale.
    dayAt('17:00');
    daySell($burger, 3, 'till-1:0004', '17:00')->assertCreated();
    dayExpect([180, 21, 120], [0, 0, 0], '17:00');

    // Counts at close: 5 deliveries, 4 sales, 5 + 4 x 3 = 17 movements; the two rejections wrote nothing.
    expect(Delivery::count())->toBe(5)
        ->and(Sale::count())->toBe(4)
        ->and(StockMovement::count())->toBe(17);

    // No open orders are left and cheese recovered, so the dashboard is calm.
    expect(dayCall('GET', '/api/v1/dashboard')->json('data'))->toBe([
        'ingredients_count' => 3,
        'negative_count' => 0,
        'open_orders_count' => 0,
        'outstanding_lines_count' => 0,
    ]);
    expect(dayCall('GET', '/api/v1/purchase-orders?status=open')->json('data'))->toBe([]);

    // Done means: the newest movement of each ingredient carries the on-hand as its balance_after.
    foreach ([[$beef, 180, 6], [$bun, 21, 5], [$cheese, 120, 6]] as [$ingredient, $onHand, $count]) {
        $history = dayCall('GET', "/api/v1/ingredients/{$ingredient->ulid}/movements")->assertOk();
        expect($history->json('data.0.balance_after'))->toBe($onHand)
            ->and($history->json('meta.pagination.total'))->toBe($count);
    }
});
