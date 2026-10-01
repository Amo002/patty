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
            // A delivery line must fulfil a line of the same order its delivery belongs to.
            // $attributes already holds the resolved delivery id at this point.
            'purchase_order_line_id' => fn (array $attributes) => PurchaseOrderLine::factory()->state([
                'purchase_order_id' => Delivery::query()->findOrFail($attributes['delivery_id'])->purchase_order_id,
            ]),
            'quantity_received' => fake()->numberBetween(1, 1000),
        ];
    }
}
