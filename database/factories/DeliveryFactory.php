<?php

namespace Database\Factories;

use App\Enums\PurchaseOrderStatus;
use App\Models\Delivery;
use App\Models\PurchaseOrder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Delivery>
 */
class DeliveryFactory extends Factory
{
    protected $model = Delivery::class;

    public function definition(): array
    {
        return [
            // Test-only shape that DocumentNumber can never produce.
            'number' => 'GRN-TEST-'.Str::upper(Str::random(8)),
            // Goods can only arrive against an order that was sent.
            'purchase_order_id' => PurchaseOrder::factory()->status(PurchaseOrderStatus::Sent),
            'received_at' => now(),
        ];
    }
}
