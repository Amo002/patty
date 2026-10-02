<?php

use App\Models\MenuItem;
use App\Models\RecipeLine;
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
