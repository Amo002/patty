<?php

namespace Database\Factories;

use App\Models\MenuItem;
use App\Models\Sale;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Sale>
 */
class SaleFactory extends Factory
{
    protected $model = Sale::class;

    public function definition(): array
    {
        return [
            'number' => sprintf('SALE-%d-%06d', now('UTC')->year, fake()->unique()->numberBetween(1, 999999)),
            'menu_item_id' => MenuItem::factory(),
            'quantity' => fake()->numberBetween(1, 5),
            'pos_reference' => null,
            'sold_at' => now(),
        ];
    }
}
