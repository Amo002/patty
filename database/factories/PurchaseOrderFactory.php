<?php

namespace Database\Factories;

use App\Enums\PurchaseOrderStatus;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PurchaseOrder>
 */
class PurchaseOrderFactory extends Factory
{
    protected $model = PurchaseOrder::class;

    public function definition(): array
    {
        return [
            // A test-only shape that DocumentNumber can never produce, so mixing factory
            // documents with real numbers cannot hit unique(number).
            'number' => 'PO-TEST-'.Str::upper(Str::random(8)),
            'supplier_id' => Supplier::factory(),
            'status' => PurchaseOrderStatus::Draft,
        ];
    }

    public function status(PurchaseOrderStatus $status): static
    {
        return $this->state(['status' => $status]);
    }
}
