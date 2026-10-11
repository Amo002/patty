<?php

namespace App\Http\Resources\V1;

use App\Models\Sale;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * api.md "Sale". Expects `lines.menuItem` and the `deductions` relation, which
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
            'lines' => $this->lines->map(fn ($line) => [
                'menu_item' => ['id' => $line->menuItem->ulid, 'name' => $line->menuItem->name],
                'quantity' => $line->quantity,
                // D-049: loaded by withSum in SaleService::withDeductions; null means no refund yet.
                'refunded' => (int) ($line->refunded_sum ?? 0),
            ])->all(),
            'item_count' => $this->lines->count(),
            'units' => $this->lines->sum('quantity'),
            'refunded_units' => (int) $this->lines->sum(fn ($line) => $line->refunded_sum ?? 0),
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
