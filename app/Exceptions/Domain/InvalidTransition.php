<?php

namespace App\Exceptions\Domain;

use App\Enums\PurchaseOrderStatus;

/**
 * A purchase order was asked to do something its current status forbids (D-019: 409).
 */
class InvalidTransition extends DomainException
{
    public function status(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'invalid_transition';
    }

    /**
     * A status move that is not in the transition map. The message names both states.
     */
    public static function between(string $number, PurchaseOrderStatus $from, PurchaseOrderStatus $to): self
    {
        return new self("Cannot move {$number} from {$from->value} to {$to->value}.");
    }

    /**
     * An operation that is not a status move but is only legal in some statuses (delete, send without lines).
     */
    public static function forAction(string $number, string $action, string $reason): self
    {
        return new self("Cannot {$action} {$number}: {$reason}.");
    }
}
