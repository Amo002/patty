<?php

namespace App\Exceptions\Domain;

use RuntimeException;

/**
 * Base class for every business-rule violation.
 *
 * D-019: state conflicts are 409, bad input is 422. Subclasses only declare
 * which, plus a stable machine code. Nothing else decides the HTTP shape:
 * bootstrap/app.php reads status() and errorCode() and renders the envelope.
 */
abstract class DomainException extends RuntimeException
{
    /**
     * HTTP status for this violation (409 when state forbids it, 422 when input is wrong).
     */
    abstract public function status(): int;

    /**
     * Stable machine-readable code from the api.md error table, for example `invalid_transition`.
     */
    abstract public function errorCode(): string;

    /**
     * Field-keyed messages for the envelope's `errors` object. Empty unless a rule can name the field at fault.
     *
     * @return array<string, array<int, string>>
     */
    public function errors(): array
    {
        return [];
    }
}
