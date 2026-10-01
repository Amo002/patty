<?php

namespace Database\Factories;

use App\Models\Ingredient;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseOrderLine>
 */
class PurchaseOrderLineFactory extends Factory
{
    protected $model = PurchaseOrderLine::class;

    public function definition(): array
    {
        return [
            'purchase_order_id' => PurchaseOrder::factory(),
            'ingredient_id' => Ingredient::factory(),
            'quantity_ordered' => fake()->numberBetween(1, 5000),
            // D-035: the gram/millilitre defaults from config/patty.php, written out so a
            // test can see exactly what the snapshot is.
            'over_tolerance_bps' => 500,
            'under_tolerance_bps' => 500,
            'over_tolerance_cap' => 2000,
        ];
    }
}
