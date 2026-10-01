<?php

namespace Database\Factories;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PurchaseOrder>
 */
class PurchaseOrderFactory extends Factory
{
    protected $model = PurchaseOrder::class;

    public function definition(): array
    {
        return [
            // Factories bypass DocumentNumber, so they build the same shape from a unique counter.
            'number' => sprintf('PO-%d-%04d', now('UTC')->year, fake()->unique()->numberBetween(1, 9999)),
            'supplier_id' => Supplier::factory(),
            'status' => PurchaseOrderStatus::Draft,
        ];
    }

    public function status(PurchaseOrderStatus $status): static
    {
        return $this->state(['status' => $status]);
    }
}
