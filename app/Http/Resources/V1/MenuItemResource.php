<?php

namespace App\Http\Resources\V1;

use App\Models\MenuItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * api.md "Menu item". Expects recipeLines.ingredient to be loaded (MenuService
 * does it), so listing many items costs a fixed number of queries.
 *
 * @mixin MenuItem
 */
class MenuItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'name' => $this->name,
            // An item with no recipe cannot be sold: a sale would deduct nothing (E25 rejects it).
            'is_sellable' => $this->recipeLines->isNotEmpty(),
            'image_url' => $this->image_path === null ? null : asset($this->image_path),
            'recipe' => RecipeLineResource::collection($this->recipeLines),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
