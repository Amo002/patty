<?php

namespace App\Enums;

/**
 * The purchase order lifecycle, and the one place its rules are written down.
 *
 * Nothing else in the codebase may decide whether a move is legal:
 * PurchaseOrder::transitionTo() asks canTransitionTo(), and the API's
 * `allowed_actions` is derived from the same states. To add a state (for
 * example `cancelled`), add a case and its row in allowedTransitions().
 */
enum PurchaseOrderStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Received = 'received';
    case Closed = 'closed';

    /**
     * The statuses reachable from this one in a single move (D-012, D-013).
     *
     * Closed is terminal: it maps to nothing, so a closed order can never reopen.
     *
     * @return array<int, self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Sent],
            self::Sent => [self::Received],
            self::Received => [self::Closed],
            self::Closed => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

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
