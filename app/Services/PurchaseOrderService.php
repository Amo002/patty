<?php

namespace App\Services;

use App\Enums\PurchaseOrderStatus;
use App\Exceptions\Domain\InvalidTransition;
use App\Exceptions\Domain\OrderNotEditable;
use App\Models\Ingredient;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Support\Audit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Purchase order life before and after delivery: create, edit, send, short-close, delete.
 *
 * Receiving a delivery (sent to received, and the automatic close) is PTY-8.
 * Every method runs in one transaction and re-reads the order with
 * lockForUpdate before checking its status. lockForUpdate is a no-op on
 * SQLite: there the database's single-writer lock serialises writers, so a
 * racing writer fails (database is locked) instead of double-transitioning.
 * On MySQL and Postgres the row lock does the work, so the status checked is
 * the status changed (same approach as DocumentNumber). Each writes its
 * audit event inside the same transaction, so the trail cannot disagree with
 * the data, and one line to the `purchasing` log channel (D-021, D-022).
 *
 * `$lines` everywhere is the FormRequest's output: a list of
 * ['ingredient' => Ingredient, 'quantity_ordered' => int] (validation.md G4).
 */
class PurchaseOrderService
{
    public function __construct(private readonly DocumentNumber $numbers) {}

    /**
     * Create a draft order with its lines.
     *
     * Invariants: the number comes from DocumentNumber in this transaction (so a
     * rollback does not burn it, and a later delete never frees it); status is
     * draft; each line snapshots the ingredient's tolerance now (D-035).
     *
     * @param  array<int, array{ingredient: Ingredient, quantity_ordered: int}>  $lines
     */
    public function create(Supplier $supplier, array $lines): PurchaseOrder
    {
        return DB::transaction(function () use ($supplier, $lines) {
            $order = PurchaseOrder::create([
                'number' => $this->numbers->next(DocumentNumber::PURCHASE_ORDER),
                'supplier_id' => $supplier->id,
            ]);

            $this->writeLines($order, $lines);

            Audit::record('purchase_order.created', $order, [
                'number' => $order->number,
                'supplier' => $supplier->name,
                'lines' => $this->describeLines($lines),
            ], "Created {$order->number} for {$supplier->name} with ".count($lines).' line(s)');

            $this->log("Created {$order->number}");

            return $this->fresh($order);
        });
    }

    /**
     * Replace every line of a draft order.
     *
     * Invariants: draft only (D-012); tolerances are re-snapshotted from the
     * ingredients as they are now, because a draft has not been promised to a
     * supplier yet (D-035).
     *
     * @param  array<int, array{ingredient: Ingredient, quantity_ordered: int}>  $lines
     *
     * @throws OrderNotEditable when the order is not a draft
     */
    public function replaceLines(PurchaseOrder $order, array $lines): PurchaseOrder
    {
        return DB::transaction(function () use ($order, $lines) {
            $order = $this->lock($order);

            if ($order->status !== PurchaseOrderStatus::Draft) {
                Log::channel('purchasing')->notice("Rejected line edit on {$order->number}: status is {$order->status->value}");

                throw OrderNotEditable::for($order->number, $order->status);
            }

            // F6: the trail keeps the lines as they were, so the change can be read back.
            $oldLines = $this->describeStored($order);

            $order->lines()->delete();
            $this->writeLines($order, $lines);

            Audit::record('purchase_order.lines_updated', $order, [
                'number' => $order->number,
                'old_lines' => $oldLines,
                'lines' => $this->describeLines($lines),
            ], "Changed the lines of {$order->number} ({$this->summary($lines)})");

            $this->log("Replaced lines of {$order->number}");

            return $this->fresh($order);
        });
    }

    /**
     * Send a draft order to the supplier (draft to sent).
     *
     * Invariants: the order must have at least one line. The API cannot create
     * a line-less order, so the guard protects against data made any other way.
     *
     * @throws InvalidTransition when the order is not a draft or has no lines
     */
    public function send(PurchaseOrder $order): PurchaseOrder
    {
        return DB::transaction(function () use ($order) {
            $order = $this->lock($order);

            if ($order->status === PurchaseOrderStatus::Draft && ! $order->lines()->exists()) {
                Log::channel('purchasing')->notice("Rejected send of {$order->number}: no lines");

                throw InvalidTransition::forAction($order->number, 'send', 'it has no lines');
            }

            $order->transitionTo(PurchaseOrderStatus::Sent);

            Audit::record('purchase_order.sent', $order, ['number' => $order->number], "Sent {$order->number} to the supplier");

            $this->log("Sent {$order->number}");

            return $this->fresh($order);
        });
    }

    /**
     * Close a partially received order by hand, accepting what is still outstanding (D-013).
     *
     * Invariants: received orders only (the map has no other way to closed);
     * short_closed marks that quantity was left undelivered; stock is untouched.
     *
     * @throws InvalidTransition when the order is not received
     */
    public function shortClose(PurchaseOrder $order): PurchaseOrder
    {
        return DB::transaction(function () use ($order) {
            $order = $this->lock($order);

            $order->forceFill(['short_closed' => true]);
            $order->transitionTo(PurchaseOrderStatus::Closed);

            Audit::record('purchase_order.short_closed', $order, ['number' => $order->number], "Short-closed {$order->number} with quantity still outstanding");

            $this->log("Short-closed {$order->number}");

            return $this->fresh($order);
        });
    }

    /**
     * Hard-delete a draft. Its number is not reused: the sequence only moves forward.
     *
     * @throws InvalidTransition when the order is not a draft
     */
    public function delete(PurchaseOrder $order): void
    {
        DB::transaction(function () use ($order) {
            $order = $this->lock($order);

            if ($order->status !== PurchaseOrderStatus::Draft) {
                Log::channel('purchasing')->notice("Rejected delete of {$order->number}: status is {$order->status->value}");

                throw InvalidTransition::forAction($order->number, 'delete', "only a draft can be deleted and it is {$order->status->value}");
            }

            // Record before deleting so the subject row still exists to point at.
            Audit::record('purchase_order.deleted', $order, ['number' => $order->number], "Deleted draft {$order->number}");

            $order->delete();

            $this->log("Deleted {$order->number}");
        });
    }

    /**
     * Re-read the order with lockForUpdate so the status checked is the status
     * changed. A no-op on SQLite, whose single-writer lock already serialises
     * writes (a racing writer fails rather than double-transitioning); the row
     * lock matters on MySQL and Postgres.
     */
    private function lock(PurchaseOrder $order): PurchaseOrder
    {
        return PurchaseOrder::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * Load what the resource needs, with received totals in one extra query.
     */
    private function fresh(PurchaseOrder $order): PurchaseOrder
    {
        return $order->refresh()->load(PurchaseOrder::detailRelations());
    }

    /**
     * @param  array<int, array{ingredient: Ingredient, quantity_ordered: int}>  $lines
     */
    private function writeLines(PurchaseOrder $order, array $lines): void
    {
        foreach ($lines as $line) {
            $tolerance = $line['ingredient']->effectiveTolerance();

            $order->lines()->create([
                'ingredient_id' => $line['ingredient']->id,
                'quantity_ordered' => $line['quantity_ordered'],
                'over_tolerance_bps' => $tolerance->overBps,
                'under_tolerance_bps' => $tolerance->underBps,
                'over_tolerance_cap' => $tolerance->overCap,
            ]);
        }
    }

    /**
     * The order's stored lines as name, ULID and quantity (never integer keys).
     *
     * @return array<int, array{ingredient: string, ingredient_id: string, quantity_ordered: int}>
     */
    private function describeStored(PurchaseOrder $order): array
    {
        return $order->lines()->with('ingredient')->get()->map(fn ($line) => [
            'ingredient' => $line->ingredient->name,
            'ingredient_id' => $line->ingredient->ulid,
            'quantity_ordered' => $line->quantity_ordered,
        ])->all();
    }

    /**
     * Names and quantities only, so no integer key reaches the audit trail (D-031).
     *
     * @param  array<int, array{ingredient: Ingredient, quantity_ordered: int}>  $lines
     * @return array<int, array{ingredient: string, quantity_ordered: int}>
     */
    private function describeLines(array $lines): array
    {
        return array_map(fn (array $line) => [
            'ingredient' => $line['ingredient']->name,
            'quantity_ordered' => $line['quantity_ordered'],
        ], $lines);
    }

    /**
     * @param  array<int, array{ingredient: Ingredient, quantity_ordered: int}>  $lines
     */
    private function summary(array $lines): string
    {
        return implode(', ', array_map(
            fn (array $line) => $line['quantity_ordered'].' '.$line['ingredient']->name,
            $lines,
        ));
    }

    private function log(string $message): void
    {
        Log::channel('purchasing')->info($message);
    }
}
