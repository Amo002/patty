<?php

namespace App\Exceptions\Domain;

/**
 * D-029: a POS reference was reused with a different menu item or quantity.
 *
 * Replaying the original answer would hide that the till and Patty now disagree
 * about what that sale was, so the request is refused and nothing is written.
 */
class IdempotencyConflict extends DomainException
{
    public function __construct(string $posReference)
    {
        parent::__construct("POS reference {$posReference} was already used for a different sale (other item or quantity).");
    }

    public function status(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'idempotency_conflict';
    }
}
