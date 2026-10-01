<?php

namespace App\Enums;

/**
 * Cases only. The transition map (and the only code allowed to change a
 * status) is added with the purchase order service in PTY-7.
 */
enum PurchaseOrderStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Received = 'received';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Sent => 'Sent',
            // D-012: received means partially received; the order closes itself once complete.
            self::Received => 'Partially received',
            self::Closed => 'Closed',
        };
    }
}
