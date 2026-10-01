<?php

namespace Database\Factories;

use App\Models\Delivery;
use App\Models\DeliveryLine;
use App\Models\PurchaseOrderLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DeliveryLine>
 */
class DeliveryLineFactory extends Factory
{
    protected $model = DeliveryLine::class;

    public function definition(): array
    {
        return [
            'delivery_id' => Delivery::factory(),
            'purchase_order_line_id' => PurchaseOrderLine::factory(),
            'quantity_received' => fake()->numberBetween(1, 1000),
        ];
    }
}
