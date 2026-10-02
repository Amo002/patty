<?php

namespace App\Http\Resources\V1;

use App\Models\StockMovement;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * api.md "Movement" (E6). A movement is not addressable, so there is no id at all (D-031).
 * Expects `balance_after` and `reference_info` attached by StockQuery::movements().
 *
 * @mixin StockMovement
 */
class MovementResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'reason' => $this->reason->value,
            'reason_label' => $this->reason->label(),
            'quantity_delta' => $this->quantity_delta,
            'balance_after' => (int) $this->balance_after,
            'reference' => $this->reference_info,
            'occurred_at' => $this->occurred_at->toIso8601ZuluString(),
        ];
    }
}
