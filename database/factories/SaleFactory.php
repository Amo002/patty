<?php

namespace Database\Factories;

use App\Models\Sale;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A bare ticket with no lines; add them with SaleLine::factory()->for($sale). Enough for
 * tests that only need something for a movement to reference.
 *
 * @extends Factory<Sale>
 */
class SaleFactory extends Factory
{
    protected $model = Sale::class;

    public function definition(): array
    {
        return [
            'number' => 'SALE-TEST-'.Str::upper(Str::random(8)),
            'pos_reference' => null,
            'sold_at' => now(),
        ];
    }
}
