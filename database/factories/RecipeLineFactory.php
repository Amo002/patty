<?php

namespace Database\Factories;

use App\Models\Ingredient;
use App\Models\MenuItem;
use App\Models\RecipeLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecipeLine>
 */
class RecipeLineFactory extends Factory
{
    protected $model = RecipeLine::class;

    public function definition(): array
    {
        return [
            'menu_item_id' => MenuItem::factory(),
            'ingredient_id' => Ingredient::factory(),
            'quantity' => fake()->numberBetween(1, 200),
        ];
    }
}
