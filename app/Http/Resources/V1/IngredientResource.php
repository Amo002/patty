<?php

namespace App\Http\Resources\V1;

use App\Models\Ingredient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * api.md "Ingredient". Expects `on_hand` and `unit_locked` to be attached by
 * IngredientService, so a list costs one grouped stock query, not one per row.
 *
 * `incoming` (open-PO quantity) is added by PTY-10, which owns that maths.
 *
 * @mixin Ingredient
 */
class IngredientResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $tolerance = $this->effectiveTolerance();

        return [
            'id' => $this->ulid,
            'name' => $this->name,
            'unit' => $this->unit->value,
            'unit_label' => $this->unit->label(),
            'on_hand' => $this->on_hand,
            // Negative stock is information, not an error (D-007): the manager needs to see it.
            'is_negative' => $this->on_hand < 0,
            'unit_locked' => $this->unit_locked,
            'tolerance' => [
                'over_bps' => $tolerance->overBps,
                'under_bps' => $tolerance->underBps,
                'over_cap' => $tolerance->overCap,
                'source' => $tolerance->source,
            ],
            'image_url' => $this->image_path === null ? null : asset($this->image_path),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
        ];
    }
}
