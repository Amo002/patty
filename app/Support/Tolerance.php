<?php

namespace App\Support;

/**
 * Delivery tolerance for one purchase order line (D-035).
 *
 * Pure integer arithmetic: percentages are basis points (500 = 5.00%), so no
 * float ever touches a quantity. The same three numbers are stored on an
 * ingredient (as optional overrides) and snapshotted onto each PO line.
 */
final readonly class Tolerance
{
    public const SOURCE_DEFAULT = 'default';

    public const SOURCE_INGREDIENT = 'ingredient';

    // A PO line's own stored copy, as opposed to a live ingredient/unit lookup.
    public const SOURCE_SNAPSHOT = 'snapshot';

    /**
     * @param  int  $overBps  Over-delivery allowance in basis points.
     * @param  int  $underBps  Shortfall that still counts as complete, in basis points.
     * @param  int|null  $overCap  Absolute cap on the over-allowance, in the ingredient unit; null means no cap.
     * @param  string  $source  Where the values came from: 'default', 'ingredient' or 'snapshot'.
     */
    public function __construct(
        public int $overBps,
        public int $underBps,
        public ?int $overCap,
        public string $source = self::SOURCE_DEFAULT,
    ) {}

    /**
     * Largest total quantity a line may receive: ordered plus the allowance.
     *
     * The allowance is the percentage rounded down, then clamped by the cap
     * when one is set. Rounding down means a small count (10 buns) never gets
     * any allowance at all. Beyond this number a delivery is rejected.
     */
    public function maxReceivable(int $ordered): int
    {
        $allowance = intdiv($ordered * $this->overBps, 10000);

        if ($this->overCap !== null) {
            $allowance = min($allowance, $this->overCap);
        }

        return $ordered + $allowance;
    }

    /**
     * Smallest received total at which a line counts as complete.
     *
     * The shortfall percentage is rounded down, so the threshold rounds up in
     * the supplier's disfavour: a line is never completed by less than the
     * tolerance allows. The floor of 1 matters: an under tolerance of 100%
     * would otherwise give 0, a line "complete" with nothing delivered, and
     * the order would close on the first delivery against any other line.
     */
    public function minToComplete(int $ordered): int
    {
        return max(1, $ordered - intdiv($ordered * $this->underBps, 10000));
    }
}
