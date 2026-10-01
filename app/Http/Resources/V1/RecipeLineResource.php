<?php

namespace App\Http\Resources\V1;

use App\Models\RecipeLine;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One recipe line: the ingredient (ULID, name, unit) and the quantity in that unit.
 * A recipe line has no id of its own (D-031); it is read and replaced through its menu item.
 *
 * @mixin RecipeLine
 */
class RecipeLineResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'ingredient' => [
                'id' => $this->ingredient->ulid,
                'name' => $this->ingredient->name,
                'unit' => $this->ingredient->unit->value,
            ],
            'quantity' => $this->quantity,
        ];
    }
}
