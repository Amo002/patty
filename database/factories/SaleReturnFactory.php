<?php

namespace Database\Factories;

use App\Models\Sale;
use App\Models\SaleReturn;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * A bare refund with no lines; add them with SaleReturnLine::factory()->for($return).
 *
 * @extends Factory<SaleReturn>
 */
class SaleReturnFactory extends Factory
{
    protected $model = SaleReturn::class;

    public function definition(): array
    {
        return [
            'number' => 'RET-TEST-'.Str::upper(Str::random(8)),
            'sale_id' => Sale::factory(),
            'restock' => false,
            'reason' => 'Customer complaint',
            'pos_reference' => null,
            'returned_at' => now(),
        ];
    }
}
