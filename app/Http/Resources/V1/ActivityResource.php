<?php

namespace App\Http\Resources\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * api.md "Activity entry" (E29). StockQuery::activity() already shaped each row into
 * an array without ids, so this only fixes how `changes` encodes.
 */
class ActivityResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $entry = $this->resource;
        // An empty PHP array would encode as [] and break clients that expect an object.
        $entry['changes'] = $entry['changes'] === [] ? (object) [] : $entry['changes'];

        return $entry;
    }
}
