<?php

namespace App\Enums;

/**
 * Why stock moved. New stock events (waste, adjustment) are new cases here,
 * which is what keeps the ledger additive.
 */
enum MovementReason: string
{
    case Delivery = 'delivery';
    case Sale = 'sale';

    public function label(): string
    {
        return match ($this) {
            self::Delivery => 'Delivery',
            self::Sale => 'Sale',
        };
    }
}
