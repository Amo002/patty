<?php

namespace App\Http\Resources\V1;

use App\Models\SaleReturn;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * api.md "Sale return". Expects the relations SaleReturnService::record() loads
 * (`sale`, `lines.saleLine.menuItem` and the `restocked` relation).
 *
 * @mixin SaleReturn
 */
class SaleReturnResource extends JsonResource
{
    public function __construct(SaleReturn $return, private readonly bool $replayed = false)
    {
        parent::__construct($return);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->ulid,
            'number' => $this->number,
            'sale' => ['id' => $this->sale->ulid, 'number' => $this->sale->number],
            'restock' => $this->restock,
            'reason' => $this->reason,
            'pos_reference' => $this->pos_reference,
            'returned_at' => $this->returned_at->toIso8601ZuluString(),
            'replayed' => $this->replayed,
            'lines' => $this->lines->map(fn ($line) => [
                'menu_item' => ['id' => $line->saleLine->menuItem->ulid, 'name' => $line->saleLine->menuItem->name],
                'quantity' => $line->quantity,
            ])->all(),
            'restocked' => $this->restocked->map(fn (array $r) => [
                'ingredient' => [
                    'id' => $r['ingredient']->ulid,
                    'name' => $r['ingredient']->name,
                    'unit' => $r['ingredient']->unit->value,
                ],
                'quantity' => $r['quantity'],
                'on_hand_after' => $r['on_hand_after'],
            ])->all(),
        ];
    }
}
