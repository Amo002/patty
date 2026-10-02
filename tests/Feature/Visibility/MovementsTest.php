<?php

use App\Models\MenuItem;
use App\Models\PurchaseOrder;
use App\Models\RecipeLine;
use App\Models\Sale;
use App\Services\StockLedger;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';

/*
| E6: the history of one ingredient, with a running balance computed in SQL.
*/

/**
 * Beef history, oldest first: +1000 (GRN), -300 (sale x2), -150 (sale x1), +500 (GRN), -450 (sale x3).
 * Balances: 1000, 700, 550, 1050, 600.
 */
function movementsHistory(): array
{
    $beef = visIngredient('Beef');
    $burger = MenuItem::factory()->create(['name' => 'Classic Burger']);
    RecipeLine::factory()->create(['menu_item_id' => $burger->id, 'ingredient_id' => $beef->id, 'quantity' => 150]);

    $sell = fn (int $quantity) => visCall('POST', '/api/v1/sales', ['menu_item_id' => $burger->ulid, 'quantity' => $quantity])->assertCreated();

    visReceive(visOrder(['Beef' => 1000]), ['Beef' => 1000])->assertCreated();
    $sell(2);
    $sell(1);
    visReceive(visOrder(['Beef' => 500]), ['Beef' => 500])->assertCreated();
    $sell(3);

    return [$beef, [600, 1050, 550, 700, 1000]];
}

it('ends the history with a balance equal to on-hand (F18)', function () {
    [$beef, $balancesNewestFirst] = movementsHistory();

    $response = visCall('GET', "/api/v1/ingredients/{$beef->ulid}/movements")->assertOk();

    expect(collect($response->json('data'))->pluck('balance_after')->all())->toBe($balancesNewestFirst)
        ->and($response->json('data.0.balance_after'))->toBe(app(StockLedger::class)->onHand($beef));
});

it('keeps the running balance correct across page boundaries', function () {
    [$beef, $balancesNewestFirst] = movementsHistory();

    $seen = [];
    foreach ([1, 2, 3] as $page) {
        $response = visCall('GET', "/api/v1/ingredients/{$beef->ulid}/movements?per_page=2&page={$page}")->assertOk();
        array_push($seen, ...collect($response->json('data'))->pluck('balance_after')->all());
    }

    // If the window restarted on each page, page 2 would begin again from its own first row.
    expect($seen)->toBe($balancesNewestFirst);
});

it('orders the running balance by business time, so a backdated delivery lands before a later sale', function () {
    $beef = visIngredient('Beef');
    $burger = MenuItem::factory()->create(['name' => 'Classic Burger']);
    RecipeLine::factory()->create(['menu_item_id' => $burger->id, 'ingredient_id' => $beef->id, 'quantity' => 150]);

    $this->travelTo(now('UTC')->subHours(3));
    $order = visOrder(['Beef' => 1000]);
    $this->travelBack();

    // Inserted first, happened second: the sale is recorded now.
    visCall('POST', '/api/v1/sales', ['menu_item_id' => $burger->ulid, 'quantity' => 2])->assertCreated();

    // Inserted second, happened first: the delivery arrived an hour ago and is entered late.
    visCall('POST', "/api/v1/purchase-orders/{$order['id']}/deliveries", [
        'lines' => [['purchase_order_line_id' => $order['lines']['Beef'], 'quantity' => 1000]],
        'received_at' => now('UTC')->subHour()->toIso8601ZuluString(),
    ])->assertCreated();

    $rows = visCall('GET', "/api/v1/ingredients/{$beef->ulid}/movements")->json('data');

    // By business time: +1000 then -300, so 1000 then 700. A window ordered by id would give -300 and 700.
    expect(collect($rows)->pluck('reason')->all())->toBe(['sale', 'delivery'])
        ->and(collect($rows)->pluck('balance_after')->all())->toBe([700, 1000]);
});

it('links each movement to the document a manager can open, by public id', function () {
    [$beef] = movementsHistory();

    $rows = visCall('GET', "/api/v1/ingredients/{$beef->ulid}/movements")->json('data');
    $order = PurchaseOrder::query()->orderBy('id')->first();
    $sale = Sale::query()->orderByDesc('id')->first();

    expect($rows[4]['reference']['purchase_order_id'])->toBe($order->ulid)
        ->and($rows[0]['reference']['sale_id'])->toBe($sale->ulid);
});

it('labels each movement with its source document and carries no ids', function () {
    [$beef] = movementsHistory();
    $year = now('UTC')->year;

    $rows = visCall('GET', "/api/v1/ingredients/{$beef->ulid}/movements")->json('data');
    $oldest = $rows[4];
    $newest = $rows[0];

    expect($oldest['reason'])->toBe('delivery')
        ->and($oldest['quantity_delta'])->toBe(1000)
        ->and($oldest['reference']['label'])->toBe("GRN-{$year}-0001 for PO-{$year}-0001")
        ->and($newest['reason'])->toBe('sale')
        ->and($newest['quantity_delta'])->toBe(-450)
        ->and($newest['reference']['label'])->toBe("SALE-{$year}-000003 Classic Burger x3")
        ->and($newest)->not->toHaveKey('id');
});

it('makes the same number of queries for 2 movements as for 5', function () {
    $beef = visIngredient('Beef');
    visReceive(visOrder(['Beef' => 1000]), ['Beef' => 400])->assertCreated();
    visReceive(visOrder(['Beef' => 1000]), ['Beef' => 400])->assertCreated();

    $count = function () use ($beef) {
        DB::flushQueryLog();
        DB::enableQueryLog();
        visCall('GET', "/api/v1/ingredients/{$beef->ulid}/movements")->assertOk();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    };

    $small = $count();

    foreach ([1, 2, 3] as $ignored) {
        visReceive(visOrder(['Beef' => 1000]), ['Beef' => 100])->assertCreated();
    }

    expect($count())->toBe($small);
});

it('answers 404 for an unknown ingredient and 422 for a bad page size', function () {
    visCall('GET', '/api/v1/ingredients/01JA8ZK3W5Y7Q2M4N6P8R0T2V4/movements')->assertNotFound();

    $beef = visIngredient('Beef');
    visCall('GET', "/api/v1/ingredients/{$beef->ulid}/movements?per_page=500")->assertStatus(422);
});
