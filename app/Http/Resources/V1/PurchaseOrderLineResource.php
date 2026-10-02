<?php

namespace App\Http\Resources\V1;

use App\Models\PurchaseOrderLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin PurchaseOrderLine
 */
class PurchaseOrderLineResource extends JsonResource
{
    /**
     * Every field listed on purpose, so a new column is never exposed by accident (D-031).
     * The tolerance shown is the line's snapshot (D-035), not the ingredient's current setting.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $tolerance = $this->tolerance();

        return [
            'id' => $this->ulid,
            'ingredient' => [
                'id' => $this->ingredient->ulid,
                'name' => $this->ingredient->name,
                'unit' => $this->ingredient->unit->value,
            ],
            'quantity_ordered' => $this->quantity_ordered,
            'quantity_received' => $this->received(),
            'quantity_outstanding' => $this->outstanding(),
            'quantity_over_received' => $this->overReceived(),
            'quantity_under_delivered' => $this->underDelivered(),
            'is_complete' => $this->isComplete(),
            'max_receivable' => $this->maxReceivable(),
            'min_to_complete' => $this->minToComplete(),
            'tolerance' => [
                'over_bps' => $tolerance->overBps,
                'under_bps' => $tolerance->underBps,
                'over_cap' => $tolerance->overCap,
            ],
        ];
    }
}
