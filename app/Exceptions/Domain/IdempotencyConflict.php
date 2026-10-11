<?php

namespace App\Exceptions\Domain;

/**
 * D-029: a POS reference was reused with a different set of menu items or quantities.
 *
 * Replaying the original answer would hide that the till and Patty now disagree
 * about what that sale was, so the request is refused and nothing is written.
 */
class IdempotencyConflict extends DomainException
{
    public function __construct(string $posReference, string $what = 'sale (other items or quantities)')
    {
        parent::__construct("POS reference {$posReference} was already used for a different {$what}.");
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
