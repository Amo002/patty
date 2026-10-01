<?php

namespace Database\Seeders;

use App\Enums\Unit;
use App\Models\Ingredient;
use App\Models\MenuItem;
use App\Models\Supplier;
use Illuminate\Database\Seeder;

/**
 * Minimal catalogue only: no purchase orders, sales or stock movements.
 * The realistic history, built through the real services, is PTY-22.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $ingredients = collect([
            'Beef patty mince' => Unit::Gram,
            'Burger bun' => Unit::Piece,
            'Cheddar cheese' => Unit::Gram,
            'Lettuce' => Unit::Gram,
            'Tomato' => Unit::Gram,
            'Frying oil' => Unit::Millilitre,
        ])->map(fn (Unit $unit, string $name) => Ingredient::firstOrCreate(['name' => $name], ['unit' => $unit]));

        // Clearly fictional suppliers: .example is a reserved domain.
        foreach ([
            ['Levant Meats', 'orders@levantmeats.example', '+962 6 555 0101'],
            ['Amman Bakery Supply', 'sales@ammanbakery.example', '+962 6 555 0102'],
            ['Jordan Valley Produce', 'orders@jvproduce.example', '+962 6 555 0103'],
        ] as [$name, $email, $phone]) {
            Supplier::firstOrCreate(['name' => $name], ['email' => $email, 'phone' => $phone]);
        }

        // Recipe quantities per one menu item. The Classic Burger is exactly the brief's:
        // 150 g beef, 1 bun, 20 g cheese.
        $menu = [
            'Classic Burger' => ['Beef patty mince' => 150, 'Burger bun' => 1, 'Cheddar cheese' => 20],
            'Cheeseburger Deluxe' => ['Beef patty mince' => 150, 'Burger bun' => 1, 'Cheddar cheese' => 40, 'Lettuce' => 15, 'Tomato' => 30],
            'Fries' => ['Frying oil' => 40],
        ];

        foreach ($menu as $name => $recipe) {
            $item = MenuItem::firstOrCreate(['name' => $name]);

            foreach ($recipe as $ingredientName => $quantity) {
                $item->recipeLines()->firstOrCreate(
                    ['ingredient_id' => $ingredients[$ingredientName]->id],
                    ['quantity' => $quantity],
                );
            }
        }
    }
}
