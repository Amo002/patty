<?php

namespace Database\Factories;

use App\Models\SaleLine;
use App\Models\SaleReturn;
use App\Models\SaleReturnLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SaleReturnLine>
 */
class SaleReturnLineFactory extends Factory
{
    protected $model = SaleReturnLine::class;

    public function definition(): array
    {
        return [
            'sale_return_id' => SaleReturn::factory(),
            'sale_line_id' => SaleLine::factory(),
            'quantity' => 1,
        ];
    }
}
