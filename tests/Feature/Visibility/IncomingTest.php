<?php

use App\Enums\Unit;

require_once __DIR__.'/helpers.php';

/*
| T16 and the D-035 to D-038 additions: incoming is what is still expected from suppliers,
| and a line that counts as complete expects nothing more.
*/

it('counts the outstanding quantity of a sent order as incoming (T16)', function () {
    $order = visOrder(['Beef' => 1000]);

    expect(visIncoming('Beef'))->toBe(1000);

    visReceive($order, ['Beef' => 600])->assertCreated();

    expect(visIncoming('Beef'))->toBe(400);
});

it('drops incoming to zero when a partly received order is short-closed (T16)', function () {
    $order = visOrder(['Beef' => 1000]);
    visReceive($order, ['Beef' => 600])->assertCreated();
    expect(visIncoming('Beef'))->toBe(400);

    visCall('POST', "/api/v1/purchase-orders/{$order['id']}/close")->assertOk();

    expect(visIncoming('Beef'))->toBe(0);
});

it('does not count a draft order as incoming (T16)', function () {
    $order = visOrder(['Beef' => 1000], send: false);

    expect(visIncoming('Beef'))->toBe(0);

    visCall('POST', "/api/v1/purchase-orders/{$order['id']}/send")->assertOk();

    expect(visIncoming('Beef'))->toBe(1000);
});

it('counts a line completed within the under-tolerance as zero incoming (D-036)', function () {
    // Two lines, so the order stays open after the beef line completes at 960 (min to complete is 950).
    $order = visOrder(['Beef' => 1000, 'Cheese' => 500]);

    visReceive($order, ['Beef' => 960])->assertCreated();

    // The missing 40 g is not "coming": the line is complete. Cheese is untouched.
    expect(visIncoming('Beef'))->toBe(0)
        ->and(visIncoming('Cheese'))->toBe(500);
});

it('keeps counting a line that is under the completion threshold', function () {
    $order = visOrder(['Beef' => 1000, 'Cheese' => 500]);

    visReceive($order, ['Beef' => 900])->assertCreated();

    expect(visIncoming('Beef'))->toBe(100);
});

it('adds the outstanding of every open order of the same ingredient', function () {
    $first = visOrder(['Bun' => 40]);
    visOrder(['Bun' => 20]);
    visReceive($first, ['Bun' => 10])->assertCreated();

    expect(visIncoming('Bun'))->toBe(50);
});

it('shows incoming on the ingredient endpoints too (E2, E4)', function () {
    visOrder(['Beef' => 1000]);
    $beef = visIngredient('Beef');

    expect(visCall('GET', "/api/v1/ingredients/{$beef->ulid}")->json('data.incoming'))->toBe(1000);

    $listed = collect(visCall('GET', '/api/v1/ingredients')->json('data'))->firstWhere('name', 'Beef');
    expect($listed['incoming'])->toBe(1000);

    // An ingredient nobody has ordered has nothing coming.
    visIngredient('Lettuce', Unit::Gram);
    expect(visIncoming('Lettuce'))->toBe(0);
});
