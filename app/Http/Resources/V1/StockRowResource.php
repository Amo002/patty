<?php

namespace App\Http\Resources\V1;

use App\Models\Ingredient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * api.md "Stock row" (E27). Expects `on_hand` and `incoming` attached by StockQuery.
 *
 * @mixin Ingredient
 */
class StockRowResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ingredient' => ['id' => $this->ulid, 'name' => $this->name, 'unit' => $this->unit->value],
            'on_hand' => $this->on_hand,
            'incoming' => $this->incoming,
            // Negative stock is information, not an error (D-007).
            'is_negative' => $this->on_hand < 0,
        ];
    }
}
