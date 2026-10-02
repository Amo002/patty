<?php

namespace App\Http\Resources\V1;

use App\Models\PurchaseOrder;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Expects `supplier`, `lines.ingredient` and the `received_sum` on each line to be
 * loaded (PurchaseOrder::detailRelations()), so a list costs a fixed number of queries.
 *
 * @mixin PurchaseOrder
 */
class PurchaseOrderResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'number' => $this->number,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'short_closed' => $this->short_closed,
            'supplier' => [
                'id' => $this->supplier->ulid,
                'name' => $this->supplier->name,
            ],
            'lines' => PurchaseOrderLineResource::collection($this->lines)->resolve($request),
            'progress_percent' => $this->progressPercent(),
            'allowed_actions' => $this->allowedActions(),
            'sent_at' => $this->sent_at?->toIso8601ZuluString(),
            'closed_at' => $this->closed_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
