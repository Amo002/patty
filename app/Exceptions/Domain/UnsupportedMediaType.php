<?php

namespace App\Exceptions\Domain;

/**
 * An API write that does not declare a JSON body (S6, 415). Not a business rule like the
 * other domain exceptions, but it reuses their rendering so the envelope stays the same.
 */
class UnsupportedMediaType extends DomainException
{
    public function __construct()
    {
        parent::__construct('Send the request as JSON, with the header Content-Type: application/json.');
    }

    public function status(): int
    {
        return 415;
    }

    public function errorCode(): string
    {
        return 'unsupported_media_type';
    }
}
