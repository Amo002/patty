<?php

namespace App\Exceptions\Domain;

use App\Enums\PurchaseOrderStatus;

/**
 * A delivery was recorded against an order that cannot take one (D-019: 409).
 *
 * Goods can only arrive for an order the supplier has been sent, and a closed
 * order is finished for good, so only sent and received orders accept a delivery.
 */
class CannotReceive extends DomainException
{
    public function status(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'cannot_receive';
    }

    public static function for(string $number, PurchaseOrderStatus $status): self
    {
        $why = $status === PurchaseOrderStatus::Draft
            ? 'it has not been sent to the supplier yet'
            : 'it is closed';

        return new self("Cannot receive a delivery on {$number}: {$why}. Only a sent or partially received order can take a delivery.");
    }
}
