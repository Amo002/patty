<?php

namespace App\Http\Resources\V1;

use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * api.md "Sale". Expects menuItem and the `deductions` relation, which
 * SaleService::withDeductions() sets for the whole page at once, so a list of
 * sales costs the same number of queries whether it holds one sale or a hundred.
 *
 * @mixin Sale
 */
class SaleResource extends JsonResource
{
    public function __construct(Sale $sale, private readonly bool $replayed = false)
    {
        parent::__construct($sale);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'number' => $this->number,
            'menu_item' => ['id' => $this->menuItem->ulid, 'name' => $this->menuItem->name],
            'quantity' => $this->quantity,
            'pos_reference' => $this->pos_reference,
            'sold_at' => $this->sold_at->toIso8601ZuluString(),
            'replayed' => $this->replayed,
            'deductions' => $this->deductions->map(fn (array $d) => [
                'ingredient' => [
                    'id' => $d['ingredient']->ulid,
                    'name' => $d['ingredient']->name,
                    'unit' => $d['ingredient']->unit->value,
                ],
                'quantity' => $d['quantity'],
                'on_hand_after' => $d['on_hand_after'],
                'is_negative' => $d['is_negative'],
            ])->all(),
        ];
    }
}
