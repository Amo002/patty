<?php

namespace App\Exceptions\Domain;

use App\Models\Ingredient;

/**
 * An ingredient's unit cannot change once stock has moved (Q-007, D-019).
 *
 * Existing movements are plain integers in the old unit. Relabelling them
 * from g to ml would silently turn 500 g into 500 ml, so the stock history
 * would stop being true. Hence 409: the state forbids it, the input is fine.
 */
class UnitLocked extends DomainException
{
    /**
     * @param  Ingredient  $ingredient  with its stored (old) unit, which the message names
     */
    public static function for(Ingredient $ingredient): self
    {
        return new self("{$ingredient->name} already has stock history in {$ingredient->unit->value}, so its unit can't change.");
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
