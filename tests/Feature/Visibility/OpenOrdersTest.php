<?php

require_once __DIR__.'/helpers.php';

/*
| T11: E16 with status=open is the "what is still outstanding" screen.
*/

it('shows the outstanding quantity per line after a partial delivery and hides closed orders (T11)', function () {
    $partial = visOrder(['Beef' => 1000, 'Cheese' => 500]);
    $finished = visOrder(['Bun' => 40]);
    $draft = visOrder(['Lettuce' => 100], send: false);

    visReceive($partial, ['Beef' => 600])->assertCreated();
    visReceive($finished, ['Bun' => 40])->assertCreated()->assertJsonPath('data.purchase_order.status', 'closed');

    $response = visCall('GET', '/api/v1/purchase-orders?status=open')->assertOk();

    // Only the partly received order: the closed one and the draft are absent.
    expect(collect($response->json('data'))->pluck('id')->all())->toBe([$partial['id']])
        ->and(collect($response->json('data'))->pluck('id')->all())->not->toContain($finished['id'], $draft['id']);

    $lines = collect($response->json('data.0.lines'))->keyBy('ingredient.name');
    expect($lines['Beef']['quantity_received'])->toBe(600)
        ->and($lines['Beef']['quantity_outstanding'])->toBe(400)
        ->and($lines['Cheese']['quantity_received'])->toBe(0)
        ->and($lines['Cheese']['quantity_outstanding'])->toBe(500)
        // Beef 60% and cheese 0%: the average of each line's own completion (D-030).
        ->and($response->json('data.0.progress_percent'))->toBe(30);
});

it('updates the open list the moment a delivery completes the order', function () {
    $order = visOrder(['Beef' => 1000]);

    expect(visCall('GET', '/api/v1/purchase-orders?status=open')->json('meta.pagination.total'))->toBe(1);

    visReceive($order, ['Beef' => 1000])->assertCreated();

    expect(visCall('GET', '/api/v1/purchase-orders?status=open')->json('data'))->toBe([]);
});

it('stamps the open list with generated_at so the screen can say how fresh it is', function () {
    visOrder(['Beef' => 1000]);

    $generatedAt = visCall('GET', '/api/v1/purchase-orders?status=open')->json('meta.generated_at');

    expect($generatedAt)->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/');
});
