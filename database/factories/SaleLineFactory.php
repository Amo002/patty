<?php

namespace Database\Factories;

use App\Models\MenuItem;
use App\Models\Sale;
use App\Models\SaleLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SaleLine>
 */
class SaleLineFactory extends Factory
{
    protected $model = SaleLine::class;

    public function definition(): array
    {
        return [
            'sale_id' => Sale::factory(),
            'menu_item_id' => MenuItem::factory(),
            'quantity' => fake()->numberBetween(1, 5),
        ];
    }
}
