<?php

namespace Database\Factories;

use App\Models\Delivery;
use App\Models\PurchaseOrder;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Delivery>
 */
class DeliveryFactory extends Factory
{
    protected $model = Delivery::class;

    public function definition(): array
    {
        return [
            'number' => sprintf('GRN-%d-%04d', now('UTC')->year, fake()->unique()->numberBetween(1, 9999)),
            'purchase_order_id' => PurchaseOrder::factory(),
            'received_at' => now(),
        ];
    }
}
