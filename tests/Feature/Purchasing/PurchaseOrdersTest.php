<?php

use App\Enums\PurchaseOrderStatus;
use App\Enums\Unit;
use App\Models\Delivery;
use App\Models\DeliveryLine;
use App\Models\Ingredient;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Services\PurchaseOrderService;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Spatie\Activitylog\Models\Activity;

const PO_URL = '/api/v1/purchase-orders';

function beef(): Ingredient
{
    return Ingredient::query()->where('name', 'Beef')->first() ?? Ingredient::factory()->create(['name' => 'Beef']);
}

function bun(): Ingredient
{
    return Ingredient::query()->where('name', 'Bun')->first() ?? Ingredient::factory()->unit(Unit::Piece)->create(['name' => 'Bun']);
}

/** The request body for Beef 1000 and Bun 10, the ticket's test data. */
function poPayload(?Supplier $supplier = null): array
{
    return [
        'supplier_id' => ($supplier ?? Supplier::query()->where('name', 'Al-Mashreq Meats')->first() ?? Supplier::factory()->create(['name' => 'Al-Mashreq Meats']))->ulid,
        'lines' => [
            ['ingredient_id' => beef()->ulid, 'quantity_ordered' => 1000],
            ['ingredient_id' => bun()->ulid, 'quantity_ordered' => 10],
        ],
    ];
}

function createPo(?array $payload = null): string
{
    return test()->postJson(PO_URL, $payload ?? poPayload())->assertCreated()->json('data.id');
}

function receivedOrder(): PurchaseOrder
{
    $order = PurchaseOrder::query()->where('ulid', createPo())->firstOrFail();
    $order->transitionTo(PurchaseOrderStatus::Sent);
    $order->transitionTo(PurchaseOrderStatus::Received);

    return $order;
}

it('creates a draft with a number, allowed actions and the tolerance snapshot', function () {
    $response = $this->postJson(PO_URL, poPayload())->assertCreated();

    expect($response->json())->assertNoIntegerIds();
    expect($response->json('data.number'))->toBe('PO-'.now('UTC')->year.'-0001')
        ->and($response->json('data.status'))->toBe('draft')
        ->and($response->json('data.allowed_actions'))->toBe(['edit_lines', 'send', 'delete'])
        ->and($response->json('data.progress_percent'))->toBe(0)
        ->and($response->json('data.supplier.name'))->toBe('Al-Mashreq Meats');

    $beefLine = $response->json('data.lines.0');
    expect($beefLine['quantity_ordered'])->toBe(1000)
        ->and($beefLine['max_receivable'])->toBe(1050)
        ->and($beefLine['min_to_complete'])->toBe(950)
        ->and($beefLine['tolerance'])->toBe(['over_bps' => 500, 'under_bps' => 500, 'over_cap' => 2000])
        ->and($beefLine['quantity_outstanding'])->toBe(1000)
        ->and($beefLine['quantity_received'])->toBe(0);
});

it('ignores a status passed through mass assignment and stays a draft', function () {
    $order = PurchaseOrder::create(['number' => 'PO-X-1', 'supplier_id' => Supplier::factory()->create()->id, 'status' => 'closed']);

    expect($order->refresh()->status)->toBe(PurchaseOrderStatus::Draft);
});

it('sends a draft, and sending again is rejected naming both states', function () {
    $id = createPo();

    $sent = $this->postJson(PO_URL."/$id/send")->assertOk();
    expect($sent->json())->assertNoIntegerIds();
    expect($sent->json('data.status'))->toBe('sent')
        ->and($sent->json('data.sent_at'))->not->toBeNull()
        ->and($sent->json('data.allowed_actions'))->toBe(['receive']);

    $again = $this->postJson(PO_URL."/$id/send")->assertStatus(409);
    expect($again->json('code'))->toBe('invalid_transition')
        ->and($again->json('message'))->toContain('from sent to sent');
    expect($again->json())->assertNoIntegerIds();
});

it('logs a rejected transition at notice on the purchasing channel', function () {
    config(['logging.channels.purchasing' => ['driver' => 'monolog', 'handler' => TestHandler::class]]);
    $id = createPo();
    $this->postJson(PO_URL."/$id/send")->assertOk();
    $this->postJson(PO_URL."/$id/send")->assertStatus(409);

    $notices = collect(Log::channel('purchasing')->getLogger()->getHandlers()[0]->getRecords())
        ->filter(fn ($record) => $record->level->name === 'Notice');

    expect($notices)->toHaveCount(1)
        ->and($notices->first()->message)->toContain('from sent to sent');
});

it('refuses to send an order that has no lines', function () {
    $order = PurchaseOrder::factory()->create();

    $response = $this->postJson(PO_URL.'/'.$order->ulid.'/send')->assertStatus(409);

    expect($response->json('code'))->toBe('invalid_transition')
        ->and($order->refresh()->status)->toBe(PurchaseOrderStatus::Draft);
});

it('rejects a short-close on a draft, a sent and a closed order', function () {
    $draft = createPo();
    $sent = createPo();
    $this->postJson(PO_URL."/$sent/send")->assertOk();
    $closed = receivedOrder();
    $closed->transitionTo(PurchaseOrderStatus::Closed);

    foreach ([[$draft, 'draft'], [$sent, 'sent'], [$closed->ulid, 'closed']] as [$id, $from]) {
        $response = $this->postJson(PO_URL."/$id/close")->assertStatus(409);
        expect($response->json('code'))->toBe('invalid_transition')
            ->and($response->json('message'))->toContain("from $from to closed");
        expect($response->json())->assertNoIntegerIds();
    }

    expect(PurchaseOrder::query()->where('ulid', $draft)->first()->status)->toBe(PurchaseOrderStatus::Draft)
        ->and(PurchaseOrder::query()->where('ulid', $sent)->first()->status)->toBe(PurchaseOrderStatus::Sent)
        ->and($closed->refresh()->short_closed)->toBeFalse();
});

it('short-closes a received order and records the audit event', function () {
    $order = receivedOrder();

    $before = $this->getJson(PO_URL.'/'.$order->ulid)->assertOk();
    expect($before->json('data.allowed_actions'))->toBe(['receive', 'short_close'])
        ->and($before->json('data.status_label'))->toBe('Partially received');

    $response = $this->postJson(PO_URL.'/'.$order->ulid.'/close')->assertOk();
    expect($response->json())->assertNoIntegerIds();
    expect($response->json('data.status'))->toBe('closed')
        ->and($response->json('data.short_closed'))->toBeTrue()
        ->and($response->json('data.closed_at'))->not->toBeNull()
        ->and($response->json('data.allowed_actions'))->toBe([]);

    $event = Activity::query()->where('event', 'purchase_order.short_closed')->first();
    expect($event)->not->toBeNull()
        ->and($event->subject_id)->toBe($order->id)
        ->and($event->description)->toContain($order->number);

    // D-013, F11: closing by hand moves no stock.
    expect(StockMovement::query()->count())->toBe(0);
});

it('keeps integer ids out of every audit event of a purchase order', function () {
    $id = createPo();
    $this->postJson(PO_URL."/$id/send")->assertOk();

    $events = Activity::query()->whereIn('event', ['purchase_order.created', 'purchase_order.sent'])->get();

    expect($events)->toHaveCount(2);
    foreach ($events as $event) {
        expect($event->properties->toArray())->assertNoIntegerIds();
    }
});

it('rejects editing the lines of a sent order', function () {
    $id = createPo();
    $this->postJson(PO_URL."/$id/send")->assertOk();

    $response = $this->putJson(PO_URL."/$id/lines", ['lines' => [['ingredient_id' => beef()->ulid, 'quantity_ordered' => 5]]])
        ->assertStatus(409);

    expect($response->json('code'))->toBe('order_not_editable');
    expect($response->json())->assertNoIntegerIds();
    expect(PurchaseOrderLine::query()->count())->toBe(2);
});

it('replaces the lines of a draft', function () {
    $id = createPo();

    $response = $this->putJson(PO_URL."/$id/lines", ['lines' => [['ingredient_id' => bun()->ulid, 'quantity_ordered' => 40]]])
        ->assertOk();

    expect($response->json())->assertNoIntegerIds();
    expect($response->json('data.lines'))->toHaveCount(1)
        ->and($response->json('data.lines.0.ingredient.name'))->toBe('Bun')
        ->and($response->json('data.lines.0.quantity_ordered'))->toBe(40);

    // F6: the audit event holds the old and the new lines, by name, ULID and quantity.
    $event = Activity::query()->where('event', 'purchase_order.lines_updated')->sole();
    expect($event->properties['old_lines'])->toBe([
        ['ingredient' => 'Beef', 'ingredient_id' => beef()->ulid, 'quantity_ordered' => 1000],
        ['ingredient' => 'Bun', 'ingredient_id' => bun()->ulid, 'quantity_ordered' => 10],
    ])->and($event->properties['lines'])->toBe([
        ['ingredient' => 'Bun', 'quantity_ordered' => 40],
    ]);
    expect($event->properties->toArray())->assertNoIntegerIds();
});

it('deletes a draft and never reuses its number', function () {
    $first = createPo();
    $number = $this->getJson(PO_URL."/$first")->json('data.number');

    $this->deleteJson(PO_URL."/$first")->assertOk();
    $this->getJson(PO_URL."/$first")->assertNotFound();
    expect(PurchaseOrderLine::query()->count())->toBe(0);

    $next = $this->postJson(PO_URL, poPayload())->assertCreated()->json('data.number');
    expect($next)->not->toBe($number)->and($next)->toBe('PO-'.now('UTC')->year.'-0002');
    expect(Activity::query()->where('event', 'purchase_order.deleted')->count())->toBe(1);
});

it('rejects deleting an order that is not a draft', function () {
    $id = createPo();
    $this->postJson(PO_URL."/$id/send")->assertOk();

    $response = $this->deleteJson(PO_URL."/$id")->assertStatus(409);

    expect($response->json('code'))->toBe('invalid_transition');
    expect($response->json())->assertNoIntegerIds();
    expect(PurchaseOrder::query()->where('ulid', $id)->exists())->toBeTrue();
});

it('keeps the tolerance snapshot after the ingredient changes, until a draft is re-saved (T26)', function () {
    $id = createPo();
    beef()->update(['over_tolerance_bps' => 1000]);

    // Still a draft: replacing the lines picks up the new tolerance (1000 + 10% = 1100).
    $replaced = $this->putJson(PO_URL."/$id/lines", ['lines' => [['ingredient_id' => beef()->ulid, 'quantity_ordered' => 1000]]])->assertOk();
    expect($replaced->json('data.lines.0.max_receivable'))->toBe(1100)
        ->and($replaced->json('data.lines.0.tolerance.over_bps'))->toBe(1000);

    $this->postJson(PO_URL."/$id/send")->assertOk();
    beef()->update(['over_tolerance_bps' => 0]);

    $shown = $this->getJson(PO_URL."/$id")->assertOk();
    expect($shown->json('data.lines.0.max_receivable'))->toBe(1100)
        ->and($shown->json('data.lines.0.tolerance.over_bps'))->toBe(1000);
});

it('filters by status, with open meaning sent or received', function () {
    $draft = createPo();
    $sent = createPo();
    $this->postJson(PO_URL."/$sent/send")->assertOk();
    $received = receivedOrder();
    $closed = receivedOrder();
    $closed->transitionTo(PurchaseOrderStatus::Closed);

    $open = $this->getJson(PO_URL.'?status=open')->assertOk();
    expect($open->json())->assertNoIntegerIds();
    expect(collect($open->json('data'))->pluck('id')->sort()->values()->all())
        ->toBe(collect([$sent, $received->ulid])->sort()->values()->all());

    expect(collect($this->getJson(PO_URL.'?status=draft')->json('data'))->pluck('id')->all())->toBe([$draft])
        ->and(collect($this->getJson(PO_URL.'?status=closed')->json('data'))->pluck('id')->all())->toBe([$closed->ulid]);

    $this->getJson(PO_URL.'?status=nonsense')->assertStatus(422);
});

it('paginates newest first', function () {
    $ids = [createPo(), createPo(), createPo()];

    $page = $this->getJson(PO_URL.'?per_page=2&page=1')->assertOk();
    expect($page->json())->assertNoIntegerIds();
    expect($page->json('data'))->toHaveCount(2)
        ->and($page->json('data.0.id'))->toBe($ids[2])
        ->and($page->json('meta.pagination.total'))->toBe(3)
        ->and($page->json('meta.pagination.has_more'))->toBeTrue();

    $second = $this->getJson(PO_URL.'?per_page=2&page=2')->assertOk();
    expect($second->json('data'))->toHaveCount(1)
        ->and($second->json('data.0.id'))->toBe($ids[0]);
});

it('names the line when an ingredient is repeated', function () {
    $payload = poPayload();
    $payload['lines'][] = ['ingredient_id' => beef()->ulid, 'quantity_ordered' => 5];

    $response = $this->postJson(PO_URL, $payload)->assertStatus(422);

    expect($response->json('code'))->toBe('validation_failed')
        ->and(json_encode($response->json('errors')))->toContain('Line 1 (Beef)');
    expect(PurchaseOrder::query()->count())->toBe(0);
});

it('rejects every non-integer or out-of-range quantity on create and on replace (G3)', function (mixed $quantity) {
    $payload = poPayload();
    $payload['lines'] = [['ingredient_id' => beef()->ulid, 'quantity_ordered' => $quantity]];

    $create = $this->postJson(PO_URL, $payload)->assertStatus(422);
    expect($create->json())->assertNoIntegerIds();
    expect(json_encode($create->json('errors')))->toContain('Line 1 (Beef): quantity');

    $draft = createPo();
    $replace = $this->putJson(PO_URL."/$draft/lines", ['lines' => $payload['lines']])->assertStatus(422);
    expect($replace->json())->assertNoIntegerIds();
    expect(PurchaseOrder::query()->count())->toBe(1)
        ->and(PurchaseOrderLine::query()->count())->toBe(2);
})->with([
    'true' => [true],
    'false' => [false],
    'fractional string' => ['1.5'],
    'float' => [1.5],
    'text' => ['abc'],
    'exponent string' => ['1e3'],
    'negative' => [-1],
    'zero' => [0],
    'null' => [null],
    'empty array' => [[]],
    'above the maximum' => [1000001],
]);

it('accepts a quantity of exactly 1,000,000 (one above is rejected in the quantity dataset)', function () {
    $payload = poPayload();
    $payload['lines'] = [['ingredient_id' => beef()->ulid, 'quantity_ordered' => 1000000]];

    $created = $this->postJson(PO_URL, $payload)->assertCreated();
    expect($created->json())->assertNoIntegerIds();
    expect($created->json('data.lines.0.quantity_ordered'))->toBe(1000000)
        ->and($created->json('data.lines.0.max_receivable'))->toBe(1002000);
});

it('accepts 50 lines and rejects 51', function () {
    $ingredients = Ingredient::factory()->count(51)->create();
    $lines = fn (int $count) => $ingredients->take($count)
        ->map(fn (Ingredient $ingredient) => ['ingredient_id' => $ingredient->ulid, 'quantity_ordered' => 1])
        ->values()->all();
    $supplier = Supplier::factory()->create()->ulid;

    $tooMany = $this->postJson(PO_URL, ['supplier_id' => $supplier, 'lines' => $lines(51)])->assertStatus(422);
    expect($tooMany->json())->assertNoIntegerIds();
    expect(PurchaseOrder::query()->count())->toBe(0);

    $fifty = $this->postJson(PO_URL, ['supplier_id' => $supplier, 'lines' => $lines(50)])->assertCreated();
    expect($fifty->json())->assertNoIntegerIds();
    expect($fifty->json('data.lines'))->toHaveCount(50);
});

it('answers 422, never 500, when a string field receives an array or a number (D-031)', function (array $override) {
    $payload = array_replace_recursive(poPayload(), $override);

    $response = $this->postJson(PO_URL, $payload)->assertStatus(422);

    expect($response->json())->assertNoIntegerIds();
    expect($response->json('code'))->toBe('validation_failed');
    expect(PurchaseOrder::query()->count())->toBe(0);
})->with([
    'supplier as array' => [['supplier_id' => ['x']]],
    'supplier as nested array' => [['supplier_id' => [['x']]]],
    'supplier as number' => [['supplier_id' => 7]],
    'ingredient as array' => [['lines' => [['ingredient_id' => ['x']]]]],
    'ingredient as number' => [['lines' => [['ingredient_id' => 1]]]],
]);

it('answers 422 when lines is not a list of objects', function (mixed $lines) {
    $response = $this->postJson(PO_URL, ['supplier_id' => Supplier::factory()->create()->ulid, 'lines' => $lines])->assertStatus(422);

    expect($response->json())->assertNoIntegerIds();
})->with([
    'a string' => ['abc'],
    'strings in a list' => [['abc', 'def']],
    'numbers in a list' => [[1, 2]],
]);

it('rolls the whole creation back when a line cannot be written (atomic)', function () {
    $supplier = Supplier::factory()->create();
    $written = 0;
    PurchaseOrderLine::creating(function () use (&$written) {
        if (++$written === 2) {
            throw new RuntimeException('disk full');
        }
    });

    $lines = [
        ['ingredient' => beef(), 'quantity_ordered' => 1000],
        ['ingredient' => bun(), 'quantity_ordered' => 10],
    ];

    expect(fn () => app(PurchaseOrderService::class)->create($supplier, $lines))->toThrow(RuntimeException::class);

    // Without DB::transaction the order and its first line would already be saved.
    expect(PurchaseOrder::query()->count())->toBe(0)
        ->and(PurchaseOrderLine::query()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'purchase_order.created')->count())->toBe(0);
});

it('keeps the old lines when a replacement fails halfway (atomic)', function () {
    $order = PurchaseOrder::query()->where('ulid', createPo())->firstOrFail();
    PurchaseOrderLine::creating(fn () => throw new RuntimeException('disk full'));

    expect(fn () => app(PurchaseOrderService::class)->replaceLines($order, [['ingredient' => beef(), 'quantity_ordered' => 5]]))
        ->toThrow(RuntimeException::class);

    // Without DB::transaction the delete of the old lines would already be committed.
    expect(PurchaseOrderLine::query()->where('purchase_order_id', $order->id)->count())->toBe(2)
        ->and(Activity::query()->where('event', 'purchase_order.lines_updated')->count())->toBe(0);
});

it('does not move the status when the audit write fails (atomic)', function () {
    $order = PurchaseOrder::query()->where('ulid', createPo())->firstOrFail();
    Activity::creating(fn (Activity $activity) => $activity->event === 'purchase_order.sent' ? throw new RuntimeException('audit down') : null);

    expect(fn () => app(PurchaseOrderService::class)->send($order))->toThrow(RuntimeException::class);

    expect($order->fresh()->status)->toBe(PurchaseOrderStatus::Draft)
        ->and($order->fresh()->sent_at)->toBeNull();
});

it('rejects an unknown ingredient with the line named', function () {
    $payload = poPayload();
    $payload['lines'][1]['ingredient_id'] = '01jzzzzzzzzzzzzzzzzzzzzzzz';

    $response = $this->postJson(PO_URL, $payload)->assertStatus(422);

    expect($response->json())->assertNoIntegerIds();
    expect(json_encode($response->json('errors')))->toContain('Line 2');
});

it('accepts an uppercase ULID', function () {
    $payload = poPayload();
    $payload['supplier_id'] = strtoupper($payload['supplier_id']);
    $payload['lines'][0]['ingredient_id'] = strtoupper($payload['lines'][0]['ingredient_id']);

    $this->postJson(PO_URL, $payload)->assertCreated();
});

it('rejects an empty or missing supplier and lines', function () {
    $this->postJson(PO_URL, ['supplier_id' => null, 'lines' => []])->assertStatus(422);
    expect(PurchaseOrder::query()->count())->toBe(0);
});

it('returns 404 for a numeric id in the URL', function () {
    $order = PurchaseOrder::factory()->create();

    $this->getJson(PO_URL.'/'.$order->id)->assertNotFound();
    $this->postJson(PO_URL.'/'.$order->id.'/send')->assertNotFound();
    $this->deleteJson(PO_URL.'/'.$order->id)->assertNotFound();
});

it('logs the status change through the activity trait without the supplier key', function () {
    $id = createPo();
    $this->postJson(PO_URL."/$id/send")->assertOk();

    $change = Activity::query()->where('event', 'updated')->first();

    expect($change)->not->toBeNull()
        ->and($change->attribute_changes->toArray())->not->toHaveKey('attributes.supplier_id');
    expect($change->attribute_changes['attributes']['status'] ?? null)->toBe('sent');
});

it('reports progress as the average of per-line completion (D-030)', function (int $orderedA, int $receivedA, int $orderedB, int $receivedB, int $expected) {
    $order = PurchaseOrder::factory()->status(PurchaseOrderStatus::Received)->create();
    $delivery = Delivery::factory()->create(['purchase_order_id' => $order->id]);

    foreach ([[$orderedA, $receivedA], [$orderedB, $receivedB]] as [$ordered, $received]) {
        $line = PurchaseOrderLine::factory()->create(['purchase_order_id' => $order->id, 'quantity_ordered' => $ordered]);
        DeliveryLine::factory()->create(['delivery_id' => $delivery->id, 'purchase_order_line_id' => $line->id, 'quantity_received' => $received]);
    }

    $response = $this->getJson(PO_URL.'/'.$order->ulid)->assertOk();

    expect($response->json())->assertNoIntegerIds();
    expect($response->json('data.progress_percent'))->toBe($expected);
})->with([
    'half and complete' => [1000, 500, 10, 10, 75],
    'thirds round down per line then on average' => [3, 1, 3, 2, 49],
    'over-received counts as 100 not more' => [10, 15, 10, 0, 50],
    'nothing received' => [10, 0, 10, 0, 0],
]);

it('derives outstanding, under-delivered and over-received from the line snapshot', function () {
    $order = PurchaseOrder::factory()->status(PurchaseOrderStatus::Received)->create();
    $delivery = Delivery::factory()->create(['purchase_order_id' => $order->id]);
    $line = PurchaseOrderLine::factory()->create(['purchase_order_id' => $order->id, 'quantity_ordered' => 1000]);

    $receive = function (int $quantity) use ($delivery, $line) {
        DeliveryLine::factory()->create(['delivery_id' => $delivery->id, 'purchase_order_line_id' => $line->id, 'quantity_received' => $quantity]);

        return PurchaseOrderLine::query()->withSum('deliveryLines as received_sum', 'quantity_received')->find($line->id);
    };

    $partial = $receive(900);
    expect([$partial->received(), $partial->isComplete(), $partial->outstanding(), $partial->underDelivered(), $partial->overReceived()])
        ->toBe([900, false, 100, 0, 0]);

    // 960 total is at or above min_to_complete (950): complete, with a 40 shortfall accepted.
    $within = $receive(60);
    expect([$within->received(), $within->isComplete(), $within->outstanding(), $within->underDelivered(), $within->overReceived()])
        ->toBe([960, true, 0, 40, 0]);

    $over = $receive(80);
    expect([$over->received(), $over->isComplete(), $over->outstanding(), $over->underDelivered(), $over->overReceived()])
        ->toBe([1040, true, 0, 0, 40]);
});
