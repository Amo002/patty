<?php

namespace App\Exceptions\Domain;

use App\Models\Ingredient;

/**
 * An ingredient's unit cannot change once it is used anywhere (D-044, D-019).
 *
 * Stock movements, recipe lines and purchase order lines are plain integers
 * in the ingredient's unit. Relabelling them from g to ml would silently turn
 * 500 g into 500 ml. Hence 409: the state forbids it, the input is fine.
 */
class UnitLocked extends DomainException
{
    /**
     * @param  Ingredient  $ingredient  with its stored (old) unit
     * @param  string  $usedBy  which use holds the lock: `stock history`, `recipes` or `purchase orders`
     */
    public static function for(Ingredient $ingredient, string $usedBy): self
    {
        return new self("{$ingredient->name} is already used in {$usedBy}, so its unit ({$ingredient->unit->value}) can't change.");
    }

    public function status(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'unit_locked';
    }
}
