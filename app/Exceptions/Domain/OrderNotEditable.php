<?php

namespace App\Exceptions\Domain;

use App\Enums\PurchaseOrderStatus;

/**
 * The lines of a purchase order can only change while it is a draft (D-019: 409).
 */
class OrderNotEditable extends DomainException
{
    public function status(): int
    {
        return 409;
    }

    public function errorCode(): string
    {
        return 'order_not_editable';
    }

    public static function for(string $number, PurchaseOrderStatus $status): self
    {
        return new self("{$number} is {$status->value}, so its lines can no longer be changed. Only a draft can be edited.");
    }
}
