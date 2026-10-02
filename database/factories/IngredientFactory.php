<?php

namespace Database\Factories;

use App\Enums\Unit;
use App\Models\Ingredient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ingredient>
 */
class IngredientFactory extends Factory
{
    protected $model = Ingredient::class;

    public function definition(): array
    {
        return [
            // unique(): names are unique case-insensitively in the database (G6).
            'name' => fake()->unique()->words(2, true),
            'unit' => Unit::Gram,
        ];
    }

    public function unit(Unit $unit): static
    {
        return $this->state(['unit' => $unit]);
    }

    /**
     * Per-ingredient tolerance overrides (D-035); any field left out stays null (unit default).
     */
    public function withTolerance(?int $overBps = null, ?int $underBps = null, ?int $overCap = null): static
    {
        return $this->state([
            'over_tolerance_bps' => $overBps,
            'under_tolerance_bps' => $underBps,
            'over_tolerance_cap' => $overCap,
        ]);
    }
}
