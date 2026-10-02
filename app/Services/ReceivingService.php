<?php

namespace App\Services;

use App\Enums\MovementReason;
use App\Enums\PurchaseOrderStatus;
use App\Exceptions\Domain\CannotReceive;
use App\Exceptions\Domain\OverDelivery;
use App\Models\Delivery;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderLine;
use App\Support\Audit;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Recording what actually arrived against a purchase order (FR-4, D-035).
 *
 * The one path by which stock goes up. A delivery may cover all or part of an
 * order. Per line, received so far plus this delivery must stay within the
 * line's max_receivable, and the order closes itself the moment every line is
 * complete. Nothing here stores a balance or an outstanding figure: both are
 * derived from delivery lines and movements (D-004).
 */
class ReceivingService
{
    public function __construct(private readonly DocumentNumber $numbers, private readonly StockLedger $ledger) {}

    /**
     * Record a delivery: the delivery and its lines, one stock movement per
     * line, and the order's move to received and (when complete) closed.
     *
     * Everything runs in one DB::transaction, so a rejected line saves nothing:
     * no delivery, no movement, no audit row, and the GRN number is not burned.
     * The order is re-read with lockForUpdate so the status checked is the
     * status changed. That lock is a no-op on SQLite, where the single-writer
     * lock already serialises writes (a racing writer fails rather than
     * double-receiving); it is a real row lock on MySQL and Postgres (D-024).
     *
     * Invariants: status is sent or received; for each line, received so far +
     * quantity <= max_receivable of the line's SNAPSHOT tolerance, never the
     * ingredient's current settings (D-035). A line that reaches min_to_complete
     * is complete; when every line is, the order closes. Closing by
     * under-tolerance is a normal close, not short_closed (that flag is only
     * for a manual close with quantity still outstanding, D-013).
     *
     * @param  array<int, array{purchase_order_line: PurchaseOrderLine, quantity: int}>  $lines  the request's output; `index` in errors is the position in this list
     * @param  CarbonInterface|null  $receivedAt  business time (UTC); defaults to now
     *
     * @throws CannotReceive when the order is not sent or received
     * @throws OverDelivery when any line would exceed its max_receivable
     */
    public function receive(PurchaseOrder $po, array $lines, ?CarbonInterface $receivedAt = null, ?string $note = null): Delivery
    {
        return DB::transaction(function () use ($po, $lines, $receivedAt, $note) {
            $po = PurchaseOrder::query()->whereKey($po->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($po->status, [PurchaseOrderStatus::Sent, PurchaseOrderStatus::Received], true)) {
                Log::channel('purchasing')->notice("Rejected delivery on {$po->number}: status is {$po->status->value}");

                throw CannotReceive::for($po->number, $po->status);
            }

            $receivedAt ??= now();
            $resolved = $this->checkTolerance($po, $lines);

            $delivery = Delivery::create([
                'number' => $this->numbers->next(DocumentNumber::DELIVERY),
                'purchase_order_id' => $po->id,
                'received_at' => $receivedAt,
                'note' => $note,
            ]);

            $audited = [];

            foreach ($resolved as $item) {
                $deliveryLine = $delivery->lines()->create([
                    'purchase_order_line_id' => $item['line']->id,
                    'quantity_received' => $item['quantity'],
                ]);

                // The only way stock rises: one positive movement per delivery line.
                $this->ledger->record($item['line']->ingredient, $item['quantity'], MovementReason::Delivery, $deliveryLine, $receivedAt);

                $audited[] = [
                    'ingredient' => $item['line']->ingredient->name,
                    'purchase_order_line_id' => $item['line']->ulid,
                    'quantity' => $item['quantity'],
                ];
            }

            if ($po->status === PurchaseOrderStatus::Sent) {
                $po->transitionTo(PurchaseOrderStatus::Received);
            }

            Audit::record('delivery.recorded', $po, [
                'number' => $delivery->number,
                'purchase_order' => $po->number,
                'lines' => $audited,
            ], "{$delivery->number} recorded for {$po->number}");

            if ($this->everyLineComplete($po)) {
                $po->transitionTo(PurchaseOrderStatus::Closed);

                Audit::record('purchase_order.closed', $po, [
                    'number' => $po->number,
                    'delivery' => $delivery->number,
                ], "{$po->number} closed: every line complete");
            }

            Log::channel('purchasing')->info("{$delivery->number} recorded on {$po->number}".($po->status === PurchaseOrderStatus::Closed ? ' (order closed)' : ''));

            return $delivery->load('lines.purchaseOrderLine.ingredient');
        });
    }

    /**
     * Check every line against its limit, collecting all breaches so one
     * response names every row the manager must fix.
     *
     * Lines are re-read from the database inside the lock: the models the
     * request resolved were loaded before it, so their received totals could be stale.
     *
     * @param  array<int, array{purchase_order_line: PurchaseOrderLine, quantity: int}>  $lines
     * @return array<int, array{line: PurchaseOrderLine, quantity: int}>
     *
     * @throws OverDelivery
     */
    private function checkTolerance(PurchaseOrder $po, array $lines): array
    {
        $stored = $po->lines()->with('ingredient')->get()->keyBy('id');
        $resolved = [];
        $breaches = [];
        $pending = [];

        foreach (array_values($lines) as $index => $item) {
            // A line of another order cannot reach here (the request checks), but the key lookup makes it impossible.
            $line = $stored->get($item['purchase_order_line']->id) ?? throw new \LogicException('Delivery line does not belong to this order.');
            $quantity = $item['quantity'];
            // Counts earlier lines of this same request, so a repeated line cannot slip past the limit.
            $received = $line->received() + ($pending[$line->id] ?? 0);
            $pending[$line->id] = ($pending[$line->id] ?? 0) + $quantity;
            $limit = $line->maxReceivable();

            if ($received + $quantity > $limit) {
                $breaches[] = [
                    'index' => $index,
                    'ingredient' => $line->ingredient->name,
                    'unit' => $line->ingredient->unit->value,
                    'attempted' => $received + $quantity,
                    'received' => $received,
                    'limit' => $limit,
                ];
            }

            $resolved[] = ['line' => $line, 'quantity' => $quantity];
        }

        if ($breaches !== []) {
            $exception = OverDelivery::forLines($breaches);
            Log::channel('purchasing')->notice("Rejected delivery on {$po->number}: ".$exception->getMessage());

            throw $exception;
        }

        return $resolved;
    }

    /**
     * The close rule, in one place: every line has reached its min_to_complete.
     * Reads fresh lines so it sees the delivery lines just written in this transaction.
     */
    private function everyLineComplete(PurchaseOrder $po): bool
    {
        return $po->lines()->get()->every(fn (PurchaseOrderLine $line) => $line->isComplete());
    }
}
