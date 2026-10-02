<?php

use App\Models\PurchaseOrder;

require_once __DIR__.'/helpers.php';

/*
| E29: the audit trail, filtered by subject.
*/

it('returns only the events of the requested order, each with its channel', function () {
    $mine = visOrder(['Beef' => 1000], send: false);
    $other = visOrder(['Cheese' => 500], send: false);

    test()->postJson("/api/v1/purchase-orders/{$mine['id']}/send", [], ['X-Patty-Channel' => 'ui'])->assertOk();

    $response = visCall('GET', "/api/v1/activity?subject_type=purchase_order&subject_id={$mine['id']}")->assertOk();
    $entries = collect($response->json('data'));

    expect($entries->pluck('subject.id')->unique()->all())->toBe([$mine['id']])
        ->and($entries->pluck('subject.id')->all())->not->toContain($other['id'])
        // Newest first: the send events come before the creation.
        ->and($entries->last()['event'])->toBe('purchase_order.created')
        ->and($entries->pluck('event')->all())->toContain('purchase_order.sent')
        ->and($entries->firstWhere('event', 'purchase_order.sent')['channel'])->toBe('ui')
        ->and($entries->firstWhere('event', 'purchase_order.created')['channel'])->toBe('api')
        ->and($entries[0]['request_id'])->not->toBeEmpty()
        ->and($entries[0]['subject']['type'])->toBe('purchase_order')
        ->and($entries[0]['subject']['label'])->toBe(PurchaseOrder::query()->where('ulid', $mine['id'])->value('number'))
        ->and($response->json('meta.pagination.total'))->toBe($entries->count());
});

it('reports field changes as old and new values without any integer id', function () {
    $order = visOrder(['Beef' => 1000]);

    $entries = collect(visCall('GET', "/api/v1/activity?subject_type=purchase_order&subject_id={$order['id']}")->json('data'));
    $statusChange = $entries->first(fn ($entry) => isset($entry['changes']['status']));

    expect($statusChange['changes']['status'])->toBe(['draft', 'sent']);

    // Nothing in any entry's changes may be an internal key, whatever the event wrote.
    foreach ($entries as $entry) {
        foreach (array_keys($entry['changes']) as $field) {
            expect($field === 'id' || str_ends_with($field, '_id'))->toBeFalse();
        }
    }
});

it('lists the global trail newest first with an empty changes object when nothing changed', function () {
    visOrder(['Beef' => 1000], send: false);

    $response = visCall('GET', '/api/v1/activity')->assertOk();

    expect($response->json('data.0.event'))->toBe('purchase_order.created')
        ->and($response->json('data.0.changes'))->toBe([]);
    // The raw JSON must hold {} for no changes, not [].
    expect($response->getContent())->toContain('"changes":{}');
});

it('accepts an uppercase ULID and finds the same events', function () {
    $order = visOrder(['Beef' => 1000], send: false);
    $upper = strtoupper($order['id']);

    $response = visCall('GET', "/api/v1/activity?subject_type=purchase_order&subject_id={$upper}")->assertOk();

    expect($response->json('meta.pagination.total'))->toBeGreaterThan(0);
});

it('returns an empty page for a well-formed ULID that matches nothing', function () {
    $response = visCall('GET', '/api/v1/activity?subject_type=purchase_order&subject_id=01ja8zk3w5y7q2m4n6p8r0t2v4')->assertOk();

    expect($response->json('data'))->toBe([]);
});

it('rejects a numeric subject_id, a lone filter field and an unknown type with 422', function () {
    visCall('GET', '/api/v1/activity?subject_type=purchase_order&subject_id=1')->assertStatus(422)->assertJsonValidationErrors('subject_id');
    visCall('GET', '/api/v1/activity?subject_type=purchase_order')->assertStatus(422)->assertJsonValidationErrors('subject_id');
    visCall('GET', '/api/v1/activity?subject_id=01ja8zk3w5y7q2m4n6p8r0t2v4')->assertStatus(422)->assertJsonValidationErrors('subject_type');
    visCall('GET', '/api/v1/activity?subject_type=delivery&subject_id=01ja8zk3w5y7q2m4n6p8r0t2v4')->assertStatus(422)->assertJsonValidationErrors('subject_type');
});
