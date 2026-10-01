<?php

namespace App\Exceptions;

use LogicException;

/**
 * Thrown when code tries to update or delete a stock movement (D-024).
 *
 * A LogicException, not a DomainException: no user input can cause this, so it
 * is a programmer error and should surface as a 500, never as a friendly 4xx.
 * Corrections are new movements.
 */
class ImmutableMovement extends LogicException
{
    public static function forAction(string $action): self
    {
        return new self("Stock movements are append-only: cannot {$action} one. Record a correcting movement instead.");
    }
}
