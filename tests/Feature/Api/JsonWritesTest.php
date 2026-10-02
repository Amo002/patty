<?php

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Models\Supplier;

/*
| S6: a write that is not declared as JSON is refused with 415 and changes nothing. These are the
| shapes a cross-site page can send without a CORS preflight: a form post, text/plain, no Content-Type.
*/

it('refuses a form post and creates nothing', function () {
    $this->post('/api/v1/suppliers', ['name' => 'Forged Supplier'])
        ->assertStatus(415)
        ->assertJson(['success' => false, 'code' => 'unsupported_media_type']);

    expect(Supplier::query()->count())->toBe(0);
});

it('refuses a text/plain body that happens to contain JSON', function () {
    $this->call('POST', '/api/v1/suppliers', [], [], [], ['CONTENT_TYPE' => 'text/plain'], '{"name":"Forged Supplier"}')
        ->assertStatus(415);

    expect(Supplier::query()->count())->toBe(0);
});

it('refuses a body-less state change with no Content-Type, and the order stays a draft', function () {
    $order = PurchaseOrder::factory()->status(PurchaseOrderStatus::Draft)->create();
    PurchaseOrderLine::factory()->create(['purchase_order_id' => $order->id]);

    $this->call('POST', "/api/v1/purchase-orders/{$order->ulid}/send", server: ['HTTP_ACCEPT' => 'application/json'])
        ->assertStatus(415);

    expect($order->refresh()->status)->toBe(PurchaseOrderStatus::Draft);

    // The same request declared as JSON, with no body, is what the UI sends and it works.
    $this->postJson("/api/v1/purchase-orders/{$order->ulid}/send")->assertOk();
    expect($order->refresh()->status)->toBe(PurchaseOrderStatus::Sent);
});

it('leaves reads alone', function () {
    $this->get('/api/v1/suppliers')->assertOk();
});
