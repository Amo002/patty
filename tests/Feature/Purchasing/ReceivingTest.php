<?php

use App\Enums\MovementReason;
use App\Enums\PurchaseOrderStatus;
use App\Enums\Unit;
use App\Exceptions\Domain\OverDelivery;
use App\Models\Delivery;
use App\Models\DeliveryLine;
use App\Models\Ingredient;
use App\Models\PurchaseOrder;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Services\ReceivingService;
use App\Services\StockLedger;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Monolog\Handler\TestHandler;
use Spatie\Activitylog\Models\Activity;

const RCV_URL = '/api/v1/purchase-orders';

/**
 * Every JSON response in this file goes through here, so D-031 (no integer ids
 * reach a client) is checked on each of them, not only on a few.
 */
function api(string $method, string $url, array $data = []): TestResponse
{
    $response = $method === 'getJson' ? test()->getJson($url) : test()->postJson($url, $data);
    expect($response->json())->assertNoIntegerIds();

    return $response;
}

/** An ingredient by name, with the unit's default tolerance (over 5%, under 5%, cap 2000 g, none for pieces). */
function rcvIngredient(string $name, Unit $unit = Unit::Gram): Ingredient
{
    return Ingredient::query()->where('name', $name)->first()
        ?? Ingredient::factory()->unit($unit)->create(['name' => $name]);
}

/**
 * Create a purchase order through the API and send it. `$lines` is name => quantity ordered;
 * names ending in "Bun" are pieces, everything else grams. Returns the order id and each line's id by name.
 *
 * @param  array<string, int>  $lines
 * @return array{id: string, lines: array<string, string>}
 */
function sentOrder(array $lines): array
{
    $payload = [
        'supplier_id' => (Supplier::query()->first() ?? Supplier::factory()->create())->ulid,
        'lines' => collect($lines)->map(fn (int $qty, string $name) => [
            'ingredient_id' => rcvIngredient($name, str_ends_with($name, 'Bun') ? Unit::Piece : Unit::Gram)->ulid,
            'quantity_ordered' => $qty,
        ])->values()->all(),
    ];

    $created = api('postJson', RCV_URL, $payload)->assertCreated();
    $id = $created->json('data.id');
    api('postJson', RCV_URL."/$id/send")->assertOk();

    return [
        'id' => $id,
        'lines' => collect($created->json('data.lines'))->pluck('id', 'ingredient.name')->all(),
    ];
}

/**
 * The E23 body for name => quantity, in the order given.
 *
 * @param  array{id: string, lines: array<string, string>}  $order
 * @param  array<string, int>  $quantities
 * @return array<string, mixed>
 */
function deliveryBody(array $order, array $quantities, array $extra = []): array
{
    return [
        'lines' => collect($quantities)->map(fn (int $qty, string $name) => [
            'purchase_order_line_id' => $order['lines'][$name],
            'quantity' => $qty,
        ])->values()->all(),
        ...$extra,
    ];
}

function receiveOn(array $order, array $quantities, array $extra = [])
{
    return api('postJson', RCV_URL.'/'.$order['id'].'/deliveries', deliveryBody($order, $quantities, $extra));
}

function onHand(string $name): int
{
    return app(StockLedger::class)->onHand(rcvIngredient($name));
}

/** @return array<string, mixed> the line of the response order for an ingredient name */
function orderLine($response, string $name, string $path = 'data.purchase_order.lines'): array
{
    return collect($response->json($path))->firstWhere('ingredient.name', $name);
}

// ---------------------------------------------------------------------------
// The worked example (PO 1): partial, rejected over-delivery, completing delivery
// ---------------------------------------------------------------------------

it('T2: a partial delivery raises stock by what arrived and leaves the rest outstanding', function () {
    $order = sentOrder(['Beef' => 1000, 'Bun' => 10]);

    $response = receiveOn($order, ['Beef' => 600])->assertCreated();

    expect($response->json())->assertNoIntegerIds();
    expect(onHand('Beef'))->toBe(600)
        ->and(onHand('Bun'))->toBe(0)
        ->and($response->json('data.purchase_order.status'))->toBe('received')
        ->and($response->json('data.purchase_order.status_label'))->toBe('Partially received')
        ->and(orderLine($response, 'Beef')['quantity_outstanding'])->toBe(400)
        ->and(orderLine($response, 'Beef')['quantity_received'])->toBe(600)
        ->and(orderLine($response, 'Bun')['quantity_outstanding'])->toBe(10)
        ->and($response->json('data.purchase_order.closed_at'))->toBeNull()
        ->and($response->json('data.delivery.number'))->toBe('GRN-'.now('UTC')->year.'-0001')
        ->and($response->json('data.delivery.lines.0.quantity'))->toBe(600)
        ->and($response->json('data.delivery.lines.0.ingredient.name'))->toBe('Beef');
});

it('rejects 500 more beef after 600 (1,100 is above the 1,050 limit) and saves nothing', function () {
    $order = sentOrder(['Beef' => 1000, 'Bun' => 10]);
    receiveOn($order, ['Beef' => 600])->assertCreated();

    $deliveries = Delivery::count();
    $movements = StockMovement::count();
    $audits = Activity::query()->where('event', 'delivery.recorded')->count();

    $response = receiveOn($order, ['Beef' => 500])->assertStatus(422);

    expect($response->json())->assertNoIntegerIds();
    expect($response->json('code'))->toBe('over_delivery')
        ->and($response->json('message'))->toBe('Beef: receiving 500 g would bring the total to 1,100 g, above the 1,050 g limit.')
        ->and($response->json('errors'))->toBe(['lines.0.quantity' => ['Beef: 1,100 g is above the 1,050 g limit.']]);

    expect(Delivery::count())->toBe($deliveries)
        ->and(DeliveryLine::count())->toBe(1)
        ->and(StockMovement::count())->toBe($movements)
        ->and(Activity::query()->where('event', 'delivery.recorded')->count())->toBe($audits)
        ->and(onHand('Beef'))->toBe(600)
        ->and(PurchaseOrder::query()->where('ulid', $order['id'])->value('status'))->toBe(PurchaseOrderStatus::Received);
});

it('does not burn a GRN number on a rejected delivery', function () {
    $order = sentOrder(['Beef' => 1000]);
    receiveOn($order, ['Beef' => 2000])->assertStatus(422);

    $response = receiveOn($order, ['Beef' => 100])->assertCreated();

    expect($response->json('data.delivery.number'))->toBe('GRN-'.now('UTC')->year.'-0001');
});

it('T3: the delivery that completes the order closes it, with beef over-received by 30', function () {
    $order = sentOrder(['Beef' => 1000, 'Bun' => 10]);
    receiveOn($order, ['Beef' => 600])->assertCreated();

    $response = receiveOn($order, ['Beef' => 430, 'Bun' => 10])->assertCreated();

    expect($response->json())->assertNoIntegerIds();
    expect(onHand('Beef'))->toBe(1030)
        ->and(onHand('Bun'))->toBe(10)
        ->and($response->json('data.purchase_order.status'))->toBe('closed')
        ->and($response->json('data.purchase_order.short_closed'))->toBeFalse()
        ->and($response->json('data.purchase_order.closed_at'))->not->toBeNull()
        ->and($response->json('data.purchase_order.allowed_actions'))->toBe([])
        ->and(orderLine($response, 'Beef')['is_complete'])->toBeTrue()
        ->and(orderLine($response, 'Beef')['quantity_outstanding'])->toBe(0)
        ->and(orderLine($response, 'Beef')['quantity_over_received'])->toBe(30)
        ->and(orderLine($response, 'Bun')['is_complete'])->toBeTrue()
        ->and($response->json('data.purchase_order.progress_percent'))->toBe(100);
});

it('goes from sent to closed in a single full delivery', function () {
    $order = sentOrder(['Beef' => 1000, 'Bun' => 10]);

    $response = receiveOn($order, ['Beef' => 1000, 'Bun' => 10])->assertCreated();

    expect($response->json('data.purchase_order.status'))->toBe('closed')
        ->and(onHand('Beef'))->toBe(1000)
        ->and(onHand('Bun'))->toBe(10)
        ->and(StockMovement::count())->toBe(2);

    // Both status moves are in the trail, in order: sent to received, then received to closed.
    $changes = Activity::query()->where('event', 'updated')->get()->map(fn ($a) => $a->attribute_changes['attributes']['status'] ?? null)->all();
    expect($changes)->toContain('received')->and($changes)->toContain('closed');
});

// ---------------------------------------------------------------------------
// T9: tolerance boundaries
// ---------------------------------------------------------------------------

it('T9a: accepts exactly the 5% limit and raises stock by the full 1050', function () {
    $order = sentOrder(['Beef' => 1000]);

    receiveOn($order, ['Beef' => 1050])->assertCreated();

    expect(onHand('Beef'))->toBe(1050);
});

it('T9b: rejects one gram over the limit, and a bad second line saves nothing for the first', function () {
    $order = sentOrder(['Beef' => 1000, 'Cheese' => 1000]);

    $single = receiveOn($order, ['Beef' => 1051])->assertStatus(422);
    expect($single->json('code'))->toBe('over_delivery');

    $response = receiveOn($order, ['Beef' => 500, 'Cheese' => 1051])->assertStatus(422);

    expect($response->json('errors'))->toHaveKey('lines.1.quantity')
        ->and($response->json('errors'))->not->toHaveKey('lines.0.quantity')
        ->and(Delivery::count())->toBe(0)
        ->and(StockMovement::count())->toBe(0)
        ->and(onHand('Beef'))->toBe(0);
});

it('names every over-limit line in one response', function () {
    $order = sentOrder(['Beef' => 1000, 'Cheese' => 1000]);

    $response = receiveOn($order, ['Beef' => 1051, 'Cheese' => 1060])->assertStatus(422);

    expect(array_keys($response->json('errors')))->toBe(['lines.0.quantity', 'lines.1.quantity']);
});

it('T9c: the 2000 g cap beats the 5% on a large order', function () {
    $order = sentOrder(['Beef' => 50000]);

    expect(receiveOn($order, ['Beef' => 52001])->assertStatus(422)->json('code'))->toBe('over_delivery');

    receiveOn($order, ['Beef' => 52000])->assertCreated();

    expect(onHand('Beef'))->toBe(52000);
});

it('T9d: 10 buns allow 10 and not 11, and 9 leaves one outstanding', function () {
    $order = sentOrder(['Bun' => 10]);

    $rejected = receiveOn($order, ['Bun' => 11])->assertStatus(422);

    expect($rejected->json('message'))->toBe('Bun: receiving 11 pcs would bring the total to 11 pcs, above the 10 pcs limit.')
        ->and($rejected->json('errors')['lines.0.quantity'][0])->toBe('Bun: 11 pcs is above the 10 pcs limit.');

    $response = receiveOn($order, ['Bun' => 9])->assertCreated();

    expect(orderLine($response, 'Bun')['is_complete'])->toBeFalse()
        ->and(orderLine($response, 'Bun')['quantity_outstanding'])->toBe(1)
        ->and($response->json('data.purchase_order.status'))->toBe('received');
});

it('T9e: 960 of 1000 is complete by under-tolerance and closes the order normally', function () {
    $order = sentOrder(['Beef' => 1000]);

    $response = receiveOn($order, ['Beef' => 960])->assertCreated();

    expect(orderLine($response, 'Beef')['is_complete'])->toBeTrue()
        ->and(orderLine($response, 'Beef')['quantity_outstanding'])->toBe(0)
        ->and(orderLine($response, 'Beef')['quantity_under_delivered'])->toBe(40)
        ->and($response->json('data.purchase_order.status'))->toBe('closed')
        ->and($response->json('data.purchase_order.short_closed'))->toBeFalse()
        ->and(onHand('Beef'))->toBe(960);
});

it('T9f: 900 of 1000 is below the under-tolerance, so 100 stays outstanding', function () {
    $order = sentOrder(['Beef' => 1000]);

    $response = receiveOn($order, ['Beef' => 900])->assertCreated();

    expect(orderLine($response, 'Beef')['is_complete'])->toBeFalse()
        ->and(orderLine($response, 'Beef')['quantity_outstanding'])->toBe(100)
        ->and($response->json('data.purchase_order.status'))->toBe('received');
});

it('T26: uses the line snapshot, not the ingredient settings changed after sending', function () {
    $order = sentOrder(['Beef' => 1000]);
    rcvIngredient('Beef')->update(['over_tolerance_bps' => 0]);

    receiveOn($order, ['Beef' => 1040])->assertCreated();

    expect(onHand('Beef'))->toBe(1040);
});

it('does not close an order while a different line is still open', function () {
    $order = sentOrder(['Beef' => 1000, 'Bun' => 10]);

    $response = receiveOn($order, ['Beef' => 1000])->assertCreated();

    expect(orderLine($response, 'Beef')['is_complete'])->toBeTrue()
        ->and($response->json('data.purchase_order.status'))->toBe('received');
});

// ---------------------------------------------------------------------------
// T5: status guards
// ---------------------------------------------------------------------------

it('T5: a delivery on a draft gives 409 cannot_receive and no movements', function () {
    $payload = [
        'supplier_id' => Supplier::factory()->create()->ulid,
        'lines' => [['ingredient_id' => rcvIngredient('Beef')->ulid, 'quantity_ordered' => 1000]],
    ];
    $created = api('postJson', RCV_URL, $payload)->assertCreated();
    $order = ['id' => $created->json('data.id'), 'lines' => ['Beef' => $created->json('data.lines.0.id')]];

    $response = receiveOn($order, ['Beef' => 100])->assertStatus(409);

    expect($response->json())->assertNoIntegerIds();
    expect($response->json('code'))->toBe('cannot_receive')
        ->and($response->json('message'))->toContain('not been sent')
        ->and(StockMovement::count())->toBe(0)
        ->and(Delivery::count())->toBe(0);
});

it('T5: a delivery on a closed order gives 409 cannot_receive and changes nothing', function () {
    $order = sentOrder(['Beef' => 1000]);
    receiveOn($order, ['Beef' => 1000])->assertCreated();

    $response = receiveOn($order, ['Beef' => 10])->assertStatus(409);

    expect($response->json('code'))->toBe('cannot_receive')
        ->and($response->json('message'))->toContain('closed')
        ->and(StockMovement::count())->toBe(1)
        ->and(Delivery::count())->toBe(1)
        ->and(onHand('Beef'))->toBe(1000);
});

it('moves no stock when a received order is short-closed', function () {
    $order = sentOrder(['Beef' => 1000, 'Bun' => 10]);
    receiveOn($order, ['Beef' => 600])->assertCreated();

    $closed = api('postJson', RCV_URL.'/'.$order['id'].'/close')->assertOk();

    expect($closed->json('data.short_closed'))->toBeTrue()
        ->and($closed->json('data.status'))->toBe('closed')
        ->and(StockMovement::count())->toBe(1)
        ->and(onHand('Beef'))->toBe(600);
});

// ---------------------------------------------------------------------------
// Validation (422)
// ---------------------------------------------------------------------------

it('rejects a line that belongs to another order', function () {
    $mine = sentOrder(['Beef' => 1000]);
    $other = sentOrder(['Cheese' => 500]);

    $response = api('postJson', RCV_URL.'/'.$mine['id'].'/deliveries', [
        'lines' => [['purchase_order_line_id' => $other['lines']['Cheese'], 'quantity' => 10]],
    ])->assertStatus(422);

    expect($response->json('errors'))->toHaveKey('lines.0.purchase_order_line_id')
        ->and($response->json('errors')['lines.0.purchase_order_line_id'][0])->toContain('not part of this order')
        ->and(StockMovement::count())->toBe(0);
});

it('rejects the same order line twice in one delivery', function () {
    $order = sentOrder(['Beef' => 1000]);

    receiveOn($order, ['Beef' => 100])->assertCreated();

    $body = deliveryBody($order, ['Beef' => 100]);
    $body['lines'][] = $body['lines'][0];
    $duplicate = api('postJson', RCV_URL.'/'.$order['id'].'/deliveries', $body)->assertStatus(422);

    expect($duplicate->json('errors'))->toHaveKey('lines.0.purchase_order_line_id')
        ->and(StockMovement::count())->toBe(1);
});

it('applies the limit to a repeated line even when the request check is bypassed', function () {
    $order = sentOrder(['Beef' => 1000]);
    $po = PurchaseOrder::query()->where('ulid', $order['id'])->firstOrFail();
    $line = $po->lines()->firstOrFail();

    // 600 + 600 = 1200 > 1050: each half alone is fine, so only a running total catches it.
    $call = fn () => app(ReceivingService::class)->receive($po, [
        ['purchase_order_line' => $line, 'quantity' => 600],
        ['purchase_order_line' => $line, 'quantity' => 600],
    ]);

    expect($call)->toThrow(OverDelivery::class)
        ->and(StockMovement::count())->toBe(0);
});

it('rejects a received_at in the future', function () {
    $order = sentOrder(['Beef' => 1000]);

    $response = receiveOn($order, ['Beef' => 100], ['received_at' => now()->addHour()->toIso8601String()])->assertStatus(422);

    expect($response->json('errors'))->toHaveKey('received_at')
        ->and($response->json('errors.received_at.0'))->toContain('future')
        ->and(StockMovement::count())->toBe(0);
});

it('rejects a received_at before the order was sent', function () {
    $order = sentOrder(['Beef' => 1000]);

    $response = receiveOn($order, ['Beef' => 100], ['received_at' => now()->subHour()->toIso8601String()])->assertStatus(422);

    expect($response->json('errors.received_at.0'))->toContain('before the order was sent')
        ->and(StockMovement::count())->toBe(0);
});

it('accepts only ISO-8601 for received_at, so "yesterday" is a 422', function () {
    $order = sentOrder(['Beef' => 1000]);

    foreach (['yesterday', '2026-10-01', 'next monday', 12345] as $bad) {
        $response = receiveOn($order, ['Beef' => 100], ['received_at' => $bad])->assertStatus(422);
        expect($response->json('errors'))->toHaveKey('received_at');
    }

    expect(StockMovement::count())->toBe(0);
});

it('rejects quantity true, 0, a string number and a missing quantity', function () {
    $order = sentOrder(['Beef' => 1000]);
    $lineId = $order['lines']['Beef'];

    foreach ([true, 0, '5', null] as $bad) {
        $response = api('postJson', RCV_URL.'/'.$order['id'].'/deliveries', [
            'lines' => [['purchase_order_line_id' => $lineId, 'quantity' => $bad]],
        ])->assertStatus(422);

        expect($response->json('errors'))->toHaveKey('lines.0.quantity');
    }

    expect(StockMovement::count())->toBe(0);
});

it('rejects an empty lines list and array input for a scalar field', function () {
    $order = sentOrder(['Beef' => 1000]);
    $url = RCV_URL.'/'.$order['id'].'/deliveries';

    api('postJson', $url, ['lines' => []])->assertStatus(422);
    api('postJson', $url, [])->assertStatus(422);
    api('postJson', $url, ['lines' => [['purchase_order_line_id' => ['x'], 'quantity' => 1]]])->assertStatus(422);
    api('postJson', $url, [...deliveryBody($order, ['Beef' => 1]), 'received_at' => ['2026-01-01']])->assertStatus(422);
    api('postJson', $url, [...deliveryBody($order, ['Beef' => 1]), 'note' => str_repeat('x', 256)])->assertStatus(422);

    expect(StockMovement::count())->toBe(0);
});

it('stores a +03:00 received_at as the correct UTC instant on the delivery and its movements', function () {
    $this->travelTo(Carbon::parse('2026-10-01T12:00:00Z'));
    $order = sentOrder(['Beef' => 1000]);
    $this->travelTo(Carbon::parse('2026-10-01T12:30:00Z'));

    // 15:10 at +03:00 is 12:10 UTC: after sending (12:00) and before now (12:30).
    $response = receiveOn($order, ['Beef' => 100], ['received_at' => '2026-10-01T15:10:00+03:00'])->assertCreated();

    expect($response->json('data.delivery.received_at'))->toBe('2026-10-01T12:10:00Z')
        ->and(DB::table('deliveries')->value('received_at'))->toBe('2026-10-01 12:10:00')
        ->and(DB::table('stock_movements')->value('occurred_at'))->toBe('2026-10-01 12:10:00');
});

it('rejects a +03:00 time that is before sent_at once converted to UTC', function () {
    $this->travelTo(Carbon::parse('2026-10-01T12:00:00Z'));
    $order = sentOrder(['Beef' => 1000]);
    $this->travelTo(Carbon::parse('2026-10-01T12:30:00Z'));

    // 14:50 at +03:00 is 11:50 UTC, ten minutes before sending. Read as UTC clock time it would pass.
    receiveOn($order, ['Beef' => 100], ['received_at' => '2026-10-01T14:50:00+03:00'])->assertStatus(422);

    // 15:40 at +03:00 is 12:40 UTC, after "now" (12:30). Read as UTC clock time it would pass the future check.
    receiveOn($order, ['Beef' => 100], ['received_at' => '2026-10-01T15:40:00+03:00'])->assertStatus(422);
});

it('accepts a Z-suffixed time with fractional seconds, as JavaScript sends it', function () {
    $this->travelTo(Carbon::parse('2026-10-01T12:00:00Z'));
    $order = sentOrder(['Beef' => 1000]);
    $this->travelTo(Carbon::parse('2026-10-01T12:30:00Z'));

    receiveOn($order, ['Beef' => 100], ['received_at' => '2026-10-01T12:15:00.000Z'])->assertCreated();

    expect(DB::table('deliveries')->value('received_at'))->toBe('2026-10-01 12:15:00');
});

// ---------------------------------------------------------------------------
// The ledger link, atomicity, audit, logs
// ---------------------------------------------------------------------------

it('writes exactly one movement per delivery line, tied to it and stamped with received_at', function () {
    $this->travelTo(Carbon::parse('2026-10-01T12:00:00Z'));
    $order = sentOrder(['Beef' => 1000, 'Bun' => 10]);
    $this->travelTo(Carbon::parse('2026-10-01T12:30:00Z'));

    receiveOn($order, ['Beef' => 600, 'Bun' => 10], ['received_at' => '2026-10-01T12:20:00Z'])->assertCreated();

    $lines = DeliveryLine::query()->orderBy('id')->get();
    expect($lines)->toHaveCount(2)
        ->and(StockMovement::count())->toBe(2);

    foreach ($lines as $line) {
        $movement = StockMovement::query()->where('reference_type', 'delivery_line')->where('reference_id', $line->id)->sole();

        expect($movement->quantity_delta)->toBe($line->quantity_received)
            ->and($movement->reason)->toBe(MovementReason::Delivery)
            ->and($movement->occurred_at->toIso8601ZuluString())->toBe('2026-10-01T12:20:00Z');
    }
});

it('defaults received_at to now when omitted', function () {
    $this->travelTo(Carbon::parse('2026-10-01T12:00:00Z'));
    $order = sentOrder(['Beef' => 1000]);

    $response = receiveOn($order, ['Beef' => 100])->assertCreated();

    expect($response->json('data.delivery.received_at'))->toBe('2026-10-01T12:00:00Z');
});

it('rolls back the delivery, movements and status when the audit write fails (atomic)', function () {
    $order = sentOrder(['Beef' => 1000, 'Bun' => 10]);
    Activity::creating(fn (Activity $activity) => $activity->event === 'delivery.recorded' ? throw new RuntimeException('audit down') : null);

    $po = PurchaseOrder::query()->where('ulid', $order['id'])->firstOrFail();
    $lines = $po->lines()->get()->map(fn ($line) => ['purchase_order_line' => $line, 'quantity' => 5])->all();

    expect(fn () => app(ReceivingService::class)->receive($po, $lines))->toThrow(RuntimeException::class);

    // Without DB::transaction the delivery, its lines, the movements and the status move would already be committed.
    expect(Delivery::count())->toBe(0)
        ->and(DeliveryLine::count())->toBe(0)
        ->and(StockMovement::count())->toBe(0)
        ->and($po->fresh()->status)->toBe(PurchaseOrderStatus::Sent)
        ->and(DB::table('document_sequences')->where('type', 'GRN')->count())->toBe(0);
});

it('records delivery.recorded with the lines, and purchase_order.closed only when it closes', function () {
    $order = sentOrder(['Beef' => 1000, 'Bun' => 10]);

    receiveOn($order, ['Beef' => 600])->assertCreated();

    $recorded = Activity::query()->where('event', 'delivery.recorded')->sole();
    expect($recorded->description)->toBe('GRN-'.now('UTC')->year.'-0001 recorded for PO-'.now('UTC')->year.'-0001')
        ->and($recorded->properties['lines'])->toBe([[
            'ingredient' => 'Beef',
            'purchase_order_line_id' => $order['lines']['Beef'],
            'quantity' => 600,
        ]])
        ->and(Activity::query()->where('event', 'purchase_order.closed')->count())->toBe(0);

    receiveOn($order, ['Beef' => 430, 'Bun' => 10])->assertCreated();

    expect(Activity::query()->where('event', 'delivery.recorded')->count())->toBe(2)
        ->and(Activity::query()->where('event', 'purchase_order.closed')->count())->toBe(1);
    expect(json_encode(Activity::query()->whereIn('event', ['delivery.recorded', 'purchase_order.closed'])->pluck('properties')))
        ->not->toMatch('/"[a-z_]*id":\s*\d+/');
});

it('logs a rejected over-delivery at notice and an accepted one at info', function () {
    config(['logging.channels.purchasing' => ['driver' => 'monolog', 'handler' => TestHandler::class]]);
    $order = sentOrder(['Beef' => 1000]);

    receiveOn($order, ['Beef' => 1100])->assertStatus(422);
    receiveOn($order, ['Beef' => 1000])->assertCreated();

    $records = collect(Log::channel('purchasing')->getLogger()->getHandlers()[0]->getRecords());

    expect($records->filter(fn ($r) => $r->level->name === 'Notice' && str_contains($r->message, '1,100 g, above the 1,050 g limit')))->toHaveCount(1)
        ->and($records->filter(fn ($r) => $r->level->name === 'Info' && str_contains($r->message, 'recorded on')))->toHaveCount(1);
});

// ---------------------------------------------------------------------------
// E24
// ---------------------------------------------------------------------------

it('E24: lists the deliveries of an order newest first and paginates', function () {
    $this->travelTo(Carbon::parse('2026-10-01T12:00:00Z'));
    $order = sentOrder(['Beef' => 1000]);

    foreach ([100, 200, 300] as $i => $qty) {
        $this->travelTo(Carbon::parse('2026-10-01T12:00:00Z')->addMinutes(10 * ($i + 1)));
        receiveOn($order, ['Beef' => $qty])->assertCreated();
    }

    $first = api('getJson', RCV_URL.'/'.$order['id'].'/deliveries?per_page=2')->assertOk();

    expect($first->json())->assertNoIntegerIds();
    expect(collect($first->json('data'))->pluck('lines.0.quantity')->all())->toBe([300, 200])
        ->and($first->json('meta.pagination.total'))->toBe(3)
        ->and($first->json('meta.pagination.has_more'))->toBeTrue();

    $second = api('getJson', RCV_URL.'/'.$order['id'].'/deliveries?per_page=2&page=2')->assertOk();
    expect(collect($second->json('data'))->pluck('lines.0.quantity')->all())->toBe([100]);
});

it('E24: shows only the deliveries of that order, and 404s for an unknown order', function () {
    $a = sentOrder(['Beef' => 1000]);
    $b = sentOrder(['Cheese' => 500]);
    receiveOn($a, ['Beef' => 100])->assertCreated();
    receiveOn($b, ['Cheese' => 50])->assertCreated();

    $response = api('getJson', RCV_URL.'/'.$a['id'].'/deliveries')->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.lines.0.ingredient.name'))->toBe('Beef');

    api('getJson', RCV_URL.'/01jzzzzzzzzzzzzzzzzzzzzzzz/deliveries')->assertNotFound();
});
