<?php

namespace Database\Factories;

use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Supplier>
 */
class SupplierFactory extends Factory
{
    protected $model = Supplier::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            // The reserved .example domain can never reach a real mailbox.
            'email' => fake()->unique()->userName().'@supplier.example',
            'phone' => '+962 6 '.fake()->numerify('### ####'),
        ];
    }
}
