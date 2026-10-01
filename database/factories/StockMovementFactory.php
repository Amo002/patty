<?php

namespace Database\Factories;

use App\Enums\MovementReason;
use App\Models\Ingredient;
use App\Models\Sale;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Test-only shortcut for building ledger rows. Production code writes
 * movements through StockLedger, never through this factory.
 *
 * @extends Factory<StockMovement>
 */
class StockMovementFactory extends Factory
{
    protected $model = StockMovement::class;

    public function definition(): array
    {
        return [
            'ingredient_id' => Ingredient::factory(),
            'quantity_delta' => -fake()->numberBetween(1, 500),
            'reason' => MovementReason::Sale,
            'reference_type' => 'sale',
            'reference_id' => Sale::factory(),
            'occurred_at' => now(),
        ];
    }
}
