<?php

namespace App\Http\Resources\V1;

use App\Models\Delivery;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Expects `lines.purchaseOrderLine.ingredient` to be loaded.
 *
 * @mixin Delivery
 */
class DeliveryResource extends JsonResource
{
    /**
     * Fields listed on purpose, so no integer key leaks (D-031).
     * A line is identified by the order line it fulfils, since delivery lines have no ulid.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'number' => $this->number,
            'received_at' => $this->received_at->toIso8601ZuluString(),
            'note' => $this->note,
            'lines' => $this->lines->map(fn ($line) => [
                'purchase_order_line_id' => $line->purchaseOrderLine->ulid,
                'ingredient' => [
                    'id' => $line->purchaseOrderLine->ingredient->ulid,
                    'name' => $line->purchaseOrderLine->ingredient->name,
                    'unit' => $line->purchaseOrderLine->ingredient->unit->value,
                ],
                'quantity' => $line->quantity_received,
            ])->all(),
        ];
    }
}
