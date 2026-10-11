<?php

namespace App\Services;

use App\Enums\MovementReason;
use App\Exceptions\Domain\IdempotencyConflict;
use App\Exceptions\Domain\ItemNotOnSale;
use App\Exceptions\Domain\OverRefund;
use App\Models\MenuItem;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Models\SaleReturn;
use App\Models\SaleReturnLine;
use App\Support\Audit;
use Carbon\CarbonInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Refunds against a sale (FR-5 extension, D-049).
 *
 * A sale is never edited: a refund is a new document (SaleReturn with SaleReturnLine rows). Whether
 * stock comes back is the manager's answer to "was the food already made?", so it is an explicit
 * flag and never inferred. What is left to refund is derived, never stored (D-004): the sale line's
 * quantity minus the sum of its earlier refund lines.
 */
class SaleReturnService
{
    public function __construct(
        private readonly StockLedger $ledger,
        private readonly DocumentNumber $numbers,
    ) {}

    /**
     * Record a refund, or recognise a retry of one already recorded.
     *
     * Everything runs in one transaction with the sale row locked, so two refunds racing for the last
     * unit of a line are serialised and the second sees the first one's rows (on SQLite the single
     * writer lock does the same job; the lock is a no-op there, as in ReceivingService). Order inside:
     * idempotency first (D-029), then every line is checked against what is left, and only then does
     * the RET number get taken and anything get written, so a refused refund leaves no trace and burns
     * no number (data.md invariant 3).
     *
     * D-049: with `restock` true each refunded line writes, for each movement the sale line made,
     * one positive movement of `-(movement delta) / line quantity x refunded quantity`. It reads the
     * sale's own movements, never today's recipe, so a recipe changed after the sale changes nothing.
     * With `restock` false no movement is written at all.
     *
     * D-010: no stock check anywhere. A restock cannot fail on stock and a refund does not care
     * whether stock is negative.
     *
     * D-029: `pos_reference` is the idempotency key. The same reference for the same sale with the same
     * merged lines and the same `restock` is a retry: nothing is written but an audit row. Anything
     * else is a conflict.
     *
     * @param  list<array{item: MenuItem, quantity: int}>  $lines  one entry per request row, in row order. Rows of the same item are
     *                                                             merged here; errors are keyed on that item's first row.
     *
     * @throws IdempotencyConflict when the reference was used for a different refund
     * @throws ItemNotOnSale when an item is not a line of this sale
     * @throws OverRefund when any line asks for more than is left (all breaches reported)
     * @throws \InvalidArgumentException when there are no lines
     */
    public function record(Sale $sale, array $lines, bool $restock, string $reason, ?string $posReference = null): SaleReturnResult
    {
        $merged = $this->merge($lines);

        if ($merged === []) {
            throw new \InvalidArgumentException('A refund needs at least one line.');
        }

        try {
            $result = DB::transaction(fn () => $this->recordLocked($sale, $merged, $restock, $reason, $posReference));
        } catch (UniqueConstraintViolationException $e) {
            // R4: two identical retries raced past the lookup and the unique pos_reference index stopped the
            // loser (its transaction is rolled back). Re-read the winner and answer as the lookup would have.
            $winner = $posReference === null ? null : $this->findByReference($posReference);

            if ($winner === null) {
                throw $e; // some other unique index (number, ulid): not ours to explain
            }

            return DB::transaction(fn () => $this->replay($winner, $sale, $merged, $restock));
        }

        if (! $result->replayed) {
            Log::channel('pos')->info('Sale refunded', [
                'sale' => $result->return->sale->ulid,
                'return' => $result->return->number,
                'lines' => $this->logLines($merged),
                'restock' => $restock,
                'pos_reference' => $posReference,
            ]);
        }

        return $result;
    }

    /**
     * Attach what the refund put back, for the resource to render.
     *
     * Read back from the ledger, not recomputed, so the answer is the same whenever it is asked
     * (a replay returns exactly what the first response did). Grouped per ingredient in order of first
     * movement; `on_hand_after` is the balance AT the ingredient's last movement of this refund
     * (StockLedger::balancesAfter), so it does not move when later sales or refunds happen.
     * Empty for a refund that did not restock.
     */
    public function withRestocked(SaleReturn $return): SaleReturn
    {
        $return->load([
            'sale',
            'lines' => fn ($q) => $q->orderBy('id'),
            'lines.saleLine.menuItem',
            'lines.stockMovements' => fn ($q) => $q->orderBy('id'),
            'lines.stockMovements.ingredient',
        ]);

        $movements = $return->lines->flatMap->stockMovements->sortBy('id')->values();
        $balances = $this->ledger->balancesAfter($movements->pluck('id')->all());

        $return->setRelation('restocked', $movements->groupBy('ingredient_id')->map(fn ($group) => [
            'ingredient' => $group->first()->ingredient,
            'quantity' => $group->sum('quantity_delta'),
            'on_hand_after' => (int) $balances->get($group->last()->id, 0),
        ])->values());

        return $return;
    }

    /**
     * Add the quantities of repeated menu items, remember each item's first request row, and sort by
     * menu item id (as D-048 does for sales) so the same refund always makes the same lines and
     * movements whatever order it arrived in.
     *
     * @param  list<array{item: MenuItem, quantity: int}>  $rows
     * @return list<array{item: MenuItem, quantity: int, row: int}>
     */
    private function merge(array $rows): array
    {
        $merged = [];

        foreach (array_values($rows) as $row => $line) {
            $id = $line['item']->id;
            $merged[$id] ??= ['item' => $line['item'], 'quantity' => 0, 'row' => $row];
            $merged[$id]['quantity'] += $line['quantity'];
        }

        ksort($merged);

        return array_values($merged);
    }

    /**
     * @param  list<array{item: MenuItem, quantity: int, row: int}>  $merged
     */
    private function recordLocked(Sale $sale, array $merged, bool $restock, string $reason, ?string $posReference): SaleReturnResult
    {
        // Re-read with the lock so the refunded totals below are the ones this transaction will add to.
        $sale = Sale::query()->whereKey($sale->getKey())->lockForUpdate()->firstOrFail();

        if ($posReference !== null && ($existing = $this->findByReference($posReference)) !== null) {
            return $this->replay($existing, $sale, $merged, $restock);
        }

        // "Left" is derived under the lock: the line's quantity minus the sum of its refund lines.
        $saleLines = $sale->lines()
            ->with('menuItem')
            ->withSum('returnLines as refunded_sum', 'quantity')
            ->orderBy('id')
            ->get()
            ->keyBy('menu_item_id');

        $resolved = $this->check($sale, $saleLines, $merged);

        $returnedAt = now();

        $return = SaleReturn::create([
            'number' => $this->numbers->next(DocumentNumber::SALE_RETURN),
            'sale_id' => $sale->id,
            'restock' => $restock,
            'reason' => $reason,
            'pos_reference' => $posReference,
            'returned_at' => $returnedAt,
        ]);

        foreach ($resolved as $item) {
            $returnLine = $return->lines()->create(['sale_line_id' => $item['line']->id, 'quantity' => $item['quantity']]);

            if ($restock) {
                $this->restock($sale, $saleLines->count(), $item['line'], $returnLine, $returnedAt);
            }
        }

        $summary = collect($merged)->map(fn (array $line) => "{$line['item']->name} x{$line['quantity']}")->implode(', ');

        Audit::record('sale.returned', $sale, [
            'return' => $return->number,
            'lines' => $this->logLines($merged),
            'restock' => $restock,
            'reason' => $reason,
            'pos_reference' => $posReference,
        ], "{$return->number} for {$sale->number}: {$summary} (".($restock ? 'restocked' : 'not restocked').')');

        $return->setRelation('sale', $sale);

        return new SaleReturnResult($return, replayed: false);
    }

    /**
     * Check every line before anything is written, collecting all breaches so one response names every
     * row the manager must fix. Items not on the sale are reported first (they cannot be counted at all).
     *
     * @param  Collection<int, SaleLine>  $saleLines  keyed by menu item id, with refunded_sum
     * @param  list<array{item: MenuItem, quantity: int, row: int}>  $merged
     * @return list<array{line: SaleLine, quantity: int}>
     *
     * @throws ItemNotOnSale
     * @throws OverRefund
     */
    private function check(Sale $sale, Collection $saleLines, array $merged): array
    {
        $missing = [];
        $over = [];
        $resolved = [];

        foreach ($merged as $line) {
            $saleLine = $saleLines->get($line['item']->id);

            if ($saleLine === null) {
                $missing[] = ['index' => $line['row'], 'item' => $line['item']->name];

                continue;
            }

            $left = $saleLine->quantity - (int) ($saleLine->refunded_sum ?? 0);

            if ($line['quantity'] > $left) {
                $over[] = ['index' => $line['row'], 'item' => $line['item']->name, 'left' => $left];
            }

            $resolved[] = ['line' => $saleLine, 'quantity' => $line['quantity']];
        }

        // Reported in the order the till sent the rows, whatever the id sort did to the lines.
        $byRow = fn (array $a, array $b) => $a['index'] <=> $b['index'];

        if ($missing !== []) {
            usort($missing, $byRow);
            $exception = ItemNotOnSale::forLines($sale->number, $missing);
            Log::channel('pos')->notice("Rejected refund on {$sale->number}: ".$exception->getMessage());

            throw $exception;
        }

        if ($over !== []) {
            usort($over, $byRow);
            $exception = OverRefund::forLines($sale->number, $over);
            Log::channel('pos')->notice("Rejected refund on {$sale->number}: ".$exception->getMessage());

            throw $exception;
        }

        return $resolved;
    }

    /**
     * D-049: put back the share of each of the sale line's movements that the refunded units represent.
     *
     * Each sale movement is `-(recipe quantity x line quantity)`, so `-delta x refunded / line quantity`
     * is always a whole number (recipe quantity x refunded). The remainder check turns a violation of
     * that into a loud failure instead of a silently rounded stock figure; the transaction then rolls back.
     *
     * Legacy rule: a sale recorded before D-048 has its movements on the Sale, not on the line, and
     * exactly one line. A line with none of its own movements, on a one-line sale, uses the sale's.
     * Any other line with no movements restocks nothing.
     */
    private function restock(Sale $sale, int $lineCount, SaleLine $saleLine, SaleReturnLine $returnLine, CarbonInterface $at): void
    {
        $movements = $saleLine->stockMovements()->with('ingredient')->orderBy('id')->get();

        if ($movements->isEmpty() && $lineCount === 1) {
            $movements = $sale->stockMovements()->with('ingredient')->orderBy('id')->get();
        }

        foreach ($movements as $movement) {
            $scaled = -$movement->quantity_delta * $returnLine->quantity;

            if ($scaled <= 0 || $scaled % $saleLine->quantity !== 0) {
                throw new \LogicException("Sale movement {$movement->id} cannot be refunded in whole units.");
            }

            $this->ledger->record($movement->ingredient, intdiv($scaled, $saleLine->quantity), MovementReason::SaleReturn, $returnLine, $at);
        }
    }

    /**
     * Answer a retry: the same sale, lines and restock return the original, anything else is a conflict (D-029).
     *
     * @param  list<array{item: MenuItem, quantity: int, row: int}>  $merged
     */
    private function replay(SaleReturn $existing, Sale $sale, array $merged, bool $restock): SaleReturnResult
    {
        // Both sides are menu item id => quantity sorted by id, so the order of the rows does not matter.
        $stored = $existing->lines->mapWithKeys(fn (SaleReturnLine $line) => [$line->saleLine->menu_item_id => $line->quantity])->sortKeys()->all();
        $attempted = collect($merged)->mapWithKeys(fn (array $line) => [$line['item']->id => $line['quantity']])->sortKeys()->all();

        // The same reference on another sale is a different refund, whatever the lines say.
        if ($existing->sale_id !== $sale->id || $stored !== $attempted || $existing->restock !== $restock) {
            Log::channel('pos')->warning('POS reference reused with a different refund', [
                'pos_reference' => $existing->pos_reference,
                'return' => $existing->number,
                'original_restock' => $existing->restock,
                'attempted_restock' => $restock,
                'attempted_lines' => $this->logLines($merged),
            ]);

            throw new IdempotencyConflict($existing->pos_reference, 'refund (other sale, items, quantities or stock choice)');
        }

        Log::channel('pos')->notice('Sale refund replayed', ['return' => $existing->number, 'pos_reference' => $existing->pos_reference]);

        Audit::record('sale.return_replayed', $sale, [
            'return' => $existing->number,
            'pos_reference' => $existing->pos_reference,
        ], "{$existing->number} replayed (no stock moved)");

        return new SaleReturnResult($existing, replayed: true);
    }

    /**
     * @param  list<array{item: MenuItem, quantity: int}>  $lines
     * @return list<array{menu_item: string, quantity: int}>
     */
    private function logLines(array $lines): array
    {
        return array_map(fn (array $line) => ['menu_item' => $line['item']->ulid, 'quantity' => $line['quantity']], $lines);
    }

    /**
     * Protected so a test can simulate the race (R4) by making the first lookup miss.
     */
    protected function findByReference(string $posReference): ?SaleReturn
    {
        return SaleReturn::query()->with(['sale', 'lines.saleLine'])->where('pos_reference', $posReference)->first();
    }
}
