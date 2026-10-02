<?php

use App\Enums\MovementReason;
use App\Models\Ingredient;
use App\Models\Sale;
use App\Services\StockLedger;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/helpers.php';

/*
| E27 and E28: the live numbers on the dashboard.
*/

/** Count the queries one GET makes. */
function visQueries(string $url): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    visCall('GET', $url)->assertOk();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

it('shows on-hand, incoming and the negative flag per ingredient, with generated_at', function () {
    $beef = visIngredient('Beef');
    visIngredient('Cheese');
    visReceive(visOrder(['Beef' => 700]), ['Beef' => 700])->assertCreated();
    app(StockLedger::class)->record(visIngredient('Cheese'), -10, MovementReason::Sale, Sale::factory()->create());
    visOrder(['Beef' => 1000]);

    $response = visCall('GET', '/api/v1/stock')->assertOk();
    $rows = collect($response->json('data'))->keyBy('ingredient.name');

    expect($rows['Beef']['on_hand'])->toBe(700)
        ->and($rows['Beef']['incoming'])->toBe(1000)
        ->and($rows['Beef']['is_negative'])->toBeFalse()
        ->and($rows['Cheese']['on_hand'])->toBe(-10)
        ->and($rows['Cheese']['is_negative'])->toBeTrue()
        ->and($rows['Beef']['ingredient']['id'])->toBe($beef->ulid)
        ->and($response->json('meta.generated_at'))->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');
});

it('forbids caching the stock response so it can never be stale', function () {
    visIngredient('Beef');

    $response = visCall('GET', '/api/v1/stock')->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('no-store');
});

it('paginates stock by name', function () {
    foreach (['Cheese', 'Beef', 'Bun'] as $name) {
        visIngredient($name);
    }

    $response = visCall('GET', '/api/v1/stock?per_page=2&page=2')->assertOk();

    expect(collect($response->json('data'))->pluck('ingredient.name')->all())->toBe(['Cheese'])
        ->and($response->json('meta.pagination.total'))->toBe(3);
});

it('makes the same number of queries for 1 ingredient as for 11 on E27 and E2', function () {
    visOrder(['Beef' => 1000]);

    $stockOne = visQueries('/api/v1/stock');
    $listOne = visQueries('/api/v1/ingredients');

    Ingredient::factory()->count(10)->create();
    visOrder(['Cheese' => 500]);

    expect(visQueries('/api/v1/stock'))->toBe($stockOne)
        ->and(visQueries('/api/v1/ingredients'))->toBe($listOne);
});

it('counts the KPI row from a known setup (E28)', function () {
    // Four ingredients; cheese is negative; two open orders with three outstanding lines.
    $cheese = visIngredient('Cheese');
    visIngredient('Beef');
    visIngredient('Bun');
    visIngredient('Lettuce');
    app(StockLedger::class)->record($cheese, -20, MovementReason::Sale, Sale::factory()->create());

    $partial = visOrder(['Beef' => 1000, 'Cheese' => 500]);
    visReceive($partial, ['Beef' => 1000])->assertCreated();   // beef line complete, cheese line outstanding
    visOrder(['Bun' => 40, 'Lettuce' => 10]);                  // two outstanding lines
    visOrder(['Beef' => 100], send: false);                    // a draft counts for nothing
    $closed = visOrder(['Bun' => 5]);
    visReceive($closed, ['Bun' => 5])->assertCreated();        // closed, so not open

    $response = visCall('GET', '/api/v1/dashboard')->assertOk();

    expect($response->json('data'))->toBe([
        'ingredients_count' => 4,
        'negative_count' => 1,
        'open_orders_count' => 2,
        'outstanding_lines_count' => 3,
    ])->and($response->json('meta.generated_at'))->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');
});
