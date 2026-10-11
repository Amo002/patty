<?php

namespace App\Services;

use App\Enums\MovementReason;
use App\Exceptions\Domain\IdempotencyConflict;
use App\Exceptions\Domain\MenuItemNotSellable;
use App\Models\MenuItem;
use App\Models\RecipeLine;
use App\Models\Sale;
use App\Models\SaleLine;
use App\Support\Audit;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * POS sales (FR-5, F13 to F16).
 *
 * A sale is a fact that already happened at the till: a ticket of one or more menu items.
 * Patty records it and lets the recipes drive the stock movements; it never argues with the till.
 */
class SaleService
{
    public function __construct(
        private readonly StockLedger $ledger,
        private readonly DocumentNumber $numbers,
    ) {}

    /**
     * Record a ticket and deduct every line's recipe from stock, or recognise a retry of one already recorded.
     *
     * D-029: `pos_reference` is the idempotency key. The same reference with the
     * same lines (compared as menu item and quantity pairs, in any order) is a retry
     * (the POS never saw our answer), so we return the original and move no stock. The
     * same reference with anything different is a conflict and is refused.
     *
     * D-010: stock is never checked before deducting. Going below zero is
     * information (a missed delivery, a wrong count), and refusing the sale
     * would erase a burger that was really sold. The ledger logs the warning.
     *
     * D-048: duplicates of one menu item are merged and lines are sorted by menu item id, so
     * the same ticket always produces the same lines and movements whatever order it arrived in.
     *
     * @param  list<array{item: MenuItem, quantity: int}>  $lines
     *
     * @throws IdempotencyConflict when the reference was used for a different sale
     * @throws MenuItemNotSellable when any item has no recipe lines (the whole ticket is refused)
     * @throws \InvalidArgumentException when there are no lines (a sale with nothing sold explains no stock change)
     */
    public function record(array $lines, ?string $posReference = null, ?CarbonInterface $soldAt = null): SaleResult
    {
        $lines = $this->merge($lines);

        if ($lines === []) {
            throw new \InvalidArgumentException('A sale needs at least one line.');
        }

        if ($posReference !== null && ($existing = $this->findByReference($posReference)) !== null) {
            return $this->replay($existing, $lines);
        }

        try {
            // The transaction wraps the number, the sale, its lines and every movement, so a failure
            // part-way leaves nothing behind and burns no SALE number (data.md invariant 3).
            $sale = DB::transaction(fn () => $this->createSale($lines, $posReference, $soldAt));
        } catch (UniqueConstraintViolationException $e) {
            // R4: two identical retries raced past the lookup above and the unique pos_reference index
            // stopped the loser. Its transaction is rolled back, so re-read the winner and answer as
            // step 1 would have. Without this the loser would get the generic 409 `conflict`, not D-029's answer.
            $winner = $posReference === null ? null : $this->findByReference($posReference);

            if ($winner === null) {
                throw $e; // some other unique index (number, ulid): not ours to explain
            }

            return $this->replay($winner, $lines);
        }

        Log::channel('pos')->info('Sale recorded', [
            'sale' => $sale->ulid,
            'number' => $sale->number,
            'lines' => $this->logLines($lines),
            'pos_reference' => $posReference,
        ]);

        return new SaleResult($sale, replayed: false);
    }

    /**
     * Recent sales, newest first (E26). The id tie-break keeps order stable when two share a sold_at.
     */
    public function paginate(int $perPage): LengthAwarePaginator
    {
        return Sale::query()
            ->with('lines.menuItem')
            ->orderByDesc('sold_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * Attach the deductions each sale made, for the resource to render.
     *
     * Movements are read back from the ledger rather than recomputed from the
     * recipe, so a recipe changed after the sale cannot change what is shown
     * (Q-007). They are grouped per ingredient across the ticket: `quantity` is the
     * sum taken, `on_hand_after` is the balance AT the ingredient's last movement in
     * this sale (the sum of its movements up to and including it). It is therefore the
     * same figure whenever the sale is read (a replay answers exactly as the first
     * response did) and equals the current on-hand only while nothing newer exists.
     *
     * Costs a fixed number of queries however many sales are passed: one eager
     * load per relation plus one balance query for every movement.
     *
     * @param  iterable<Sale>  $sales
     * @return Collection<int, Sale>
     */
    public function withDeductions(iterable $sales): Collection
    {
        // Collection::load() eager-loads once for the whole set. Iterating and calling load() per model
        // would repeat every query per sale. `stockMovements` on the sale itself is only for sales
        // recorded before D-048, whose movements point at the sale and cannot be rewritten.
        $sales = (new Collection($sales))->load([
            // D-049: refunded units per line in the same query (withSum), so the list stays constant-query.
            'lines' => fn ($q) => $q->orderBy('id')->withSum('returnLines as refunded_sum', 'quantity'),
            'lines.menuItem',
            'lines.stockMovements' => fn ($q) => $q->orderBy('id'),
            'lines.stockMovements.ingredient',
            'stockMovements' => fn ($q) => $q->orderBy('id'),
            'stockMovements.ingredient',
        ]);

        $perSale = $sales->mapWithKeys(fn (Sale $sale) => [
            $sale->id => $sale->lines->flatMap->stockMovements->concat($sale->stockMovements)->sortBy('id')->values(),
        ]);
        $balances = $this->ledger->balancesAfter($perSale->flatten()->pluck('id')->all());

        foreach ($sales as $sale) {
            // groupBy keeps the order each ingredient first appeared in the sale; the movements are
            // already in id order, so ->last() of a group is that ingredient's final movement.
            $sale->setRelation('deductions', $perSale[$sale->id]->groupBy('ingredient_id')->map(function ($movements) use ($balances) {
                $after = (int) $balances->get($movements->last()->id, 0);

                return [
                    'ingredient' => $movements->first()->ingredient,
                    // The movements are signed negative; the API reports how much was taken.
                    'quantity' => -$movements->sum('quantity_delta'),
                    'on_hand_after' => $after,
                    'is_negative' => $after < 0,
                ];
            })->values());
        }

        return $sales;
    }

    /**
     * Add the quantities of repeated menu items and sort by menu item id (D-048).
     *
     * Sorted so line order, and with it movement order, does not depend on the order the till
     * listed the items in. The request merges too (it must validate the merged total); doing it
     * here keeps the service correct for any caller, and merging twice changes nothing.
     *
     * @param  list<array{item: MenuItem, quantity: int}>  $lines
     * @return list<array{item: MenuItem, quantity: int}>
     */
    private function merge(array $lines): array
    {
        $merged = [];

        foreach ($lines as $line) {
            $id = $line['item']->id;
            $merged[$id] = ['item' => $line['item'], 'quantity' => ($merged[$id]['quantity'] ?? 0) + $line['quantity']];
        }

        ksort($merged);

        return array_values($merged);
    }

    /**
     * Everything that must commit together: number, sale, lines, movements, audit.
     *
     * @param  list<array{item: MenuItem, quantity: int}>  $lines  merged and sorted
     */
    private function createSale(array $lines, ?string $posReference, ?CarbonInterface $soldAt): Sale
    {
        // One query for every item's recipe, in recipe-line id order within each item.
        $recipes = RecipeLine::query()
            ->whereIn('menu_item_id', array_map(fn (array $line) => $line['item']->id, $lines))
            ->with('ingredient')
            ->orderBy('id')
            ->get()
            ->groupBy('menu_item_id');

        // Checked before the number is taken, so a refused ticket does not even touch the counter.
        // The first item without a recipe names the error; the whole ticket is refused.
        foreach ($lines as $line) {
            if (! $recipes->has($line['item']->id)) {
                throw new MenuItemNotSellable($line['item']);
            }
        }

        $soldAt ??= now();

        $sale = Sale::create([
            'number' => $this->numbers->next(DocumentNumber::SALE),
            'pos_reference' => $posReference,
            'sold_at' => $soldAt,
        ]);

        $saleLines = [];

        foreach ($lines as $line) {
            $saleLine = $sale->lines()->create(['menu_item_id' => $line['item']->id, 'quantity' => $line['quantity']]);
            $saleLine->setRelation('menuItem', $line['item']);
            $saleLines[] = $saleLine;

            foreach ($recipes[$line['item']->id] as $recipeLine) {
                // D-048: one movement per (sale line, recipe line), never summed, so a refund of k of
                // n units can put back exactly delta / n x k. Quantity per portion times portions
                // sold, negative because stock goes out. The ledger is the only writer of movements
                // and does not check the balance (D-010).
                $this->ledger->record($recipeLine->ingredient, -($recipeLine->quantity * $line['quantity']), MovementReason::Sale, $saleLine, $soldAt);
            }
        }

        $sale->setRelation('lines', new Collection($saleLines));

        Audit::record('sale.recorded', $sale, [
            'lines' => $this->logLines($lines),
            'pos_reference' => $posReference,
        ], "{$sale->number} {$sale->summary()}");

        return $sale;
    }

    /**
     * Answer a retry: the same lines return the original untouched, different ones are a conflict (D-029).
     *
     * @param  list<array{item: MenuItem, quantity: int}>  $lines  merged and sorted
     */
    private function replay(Sale $existing, array $lines): SaleResult
    {
        // Both sides are menu item id => quantity sorted by id, so the comparison ignores the order
        // the till sent the lines in.
        $stored = $existing->lines->mapWithKeys(fn (SaleLine $line) => [$line->menu_item_id => $line->quantity])->sortKeys()->all();
        $attempted = collect($lines)->mapWithKeys(fn (array $line) => [$line['item']->id => $line['quantity']])->sortKeys()->all();

        if ($stored !== $attempted) {
            Log::channel('pos')->warning('POS reference reused with a different payload', [
                'pos_reference' => $existing->pos_reference,
                'sale' => $existing->ulid,
                'original_lines' => $existing->lines->map(fn (SaleLine $line) => ['menu_item' => $line->menuItem->ulid, 'quantity' => $line->quantity])->all(),
                'attempted_lines' => $this->logLines($lines),
            ]);

            throw new IdempotencyConflict($existing->pos_reference);
        }

        // Notice, not info: a retry is worth noticing (the POS may have a flaky link) but is not an error.
        Log::channel('pos')->notice('Sale replayed', ['sale' => $existing->ulid, 'pos_reference' => $existing->pos_reference]);

        DB::transaction(fn () => Audit::record('sale.replayed', $existing, [
            'pos_reference' => $existing->pos_reference,
        ], "{$existing->number} replayed (no stock moved)"));

        return new SaleResult($existing, replayed: true);
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
    protected function findByReference(string $posReference): ?Sale
    {
        return Sale::query()->with('lines.menuItem')->where('pos_reference', $posReference)->first();
    }
}
