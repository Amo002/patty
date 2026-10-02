<?php

namespace App\Services;

use App\Enums\MovementReason;
use App\Exceptions\Domain\IdempotencyConflict;
use App\Exceptions\Domain\MenuItemNotSellable;
use App\Models\MenuItem;
use App\Models\Sale;
use App\Support\Audit;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * POS sales (FR-5, F13 to F16).
 *
 * A sale is a fact that already happened at the till. Patty records it and
 * lets the recipe drive the stock movements; it never argues with the till.
 */
class SaleService
{
    public function __construct(
        private readonly StockLedger $ledger,
        private readonly DocumentNumber $numbers,
    ) {}

    /**
     * Record a sale and deduct its recipe from stock, or recognise a retry of one already recorded.
     *
     * D-029: `pos_reference` is the idempotency key. The same reference with the
     * same item and quantity is a retry (the POS never saw our answer), so we
     * return the original and move no stock. The same reference with anything
     * different is a conflict and is refused.
     *
     * D-010: stock is never checked before deducting. Going below zero is
     * information (a missed delivery, a wrong count), and refusing the sale
     * would erase a burger that was really sold. The ledger logs the warning.
     *
     * @throws IdempotencyConflict when the reference was used for a different sale
     * @throws MenuItemNotSellable when the item has no recipe lines
     */
    public function record(MenuItem $item, int $quantity, ?string $posReference = null, ?CarbonInterface $soldAt = null): SaleResult
    {
        if ($posReference !== null && ($existing = $this->findByReference($posReference)) !== null) {
            return $this->replay($existing, $item, $quantity);
        }

        try {
            // The transaction wraps the number, the sale and every movement, so a failure part-way
            // leaves no sale, no movements and no burned SALE number (data.md invariant 3).
            $sale = DB::transaction(fn () => $this->createSale($item, $quantity, $posReference, $soldAt));
        } catch (UniqueConstraintViolationException $e) {
            // R4: two identical retries raced past the lookup above and the unique pos_reference index
            // stopped the loser. Its transaction is rolled back, so re-read the winner and answer as
            // step 1 would have. Without this the loser would get the generic 409 `conflict`, not D-029's answer.
            $winner = $posReference === null ? null : $this->findByReference($posReference);

            if ($winner === null) {
                throw $e; // some other unique index (number, ulid): not ours to explain
            }

            return $this->replay($winner, $item, $quantity);
        }

        Log::channel('pos')->info('Sale recorded', [
            'sale' => $sale->ulid,
            'number' => $sale->number,
            'menu_item' => $item->ulid,
            'quantity' => $quantity,
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
            ->with('menuItem')
            ->orderByDesc('sold_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /**
     * Attach the deductions each sale made, for the resource to render.
     *
     * Movements are read back from the ledger rather than recomputed from the
     * recipe, so a recipe changed after the sale cannot change what is shown
     * (Q-007). `on_hand_after` is the CURRENT on-hand, from one grouped query:
     * stock after this sale and everything since. For a sale just recorded that
     * is exactly "after this sale".
     *
     * @param  Collection<int, Sale>|iterable<Sale>  $sales
     * @return Collection<int, Sale>
     */
    public function withDeductions(iterable $sales): Collection
    {
        $sales = collect($sales)->each->load(['menuItem', 'stockMovements' => fn ($q) => $q->orderBy('id'), 'stockMovements.ingredient']);
        $onHand = $this->ledger->onHandForAll();

        foreach ($sales as $sale) {
            $sale->setRelation('deductions', $sale->stockMovements->map(function ($movement) use ($onHand) {
                $after = $onHand->get($movement->ingredient_id, 0);

                return [
                    'ingredient' => $movement->ingredient,
                    // The movement is signed negative; the API reports how much was taken.
                    'quantity' => -$movement->quantity_delta,
                    'on_hand_after' => $after,
                    'is_negative' => $after < 0,
                ];
            })->values());
        }

        return $sales;
    }

    /**
     * Everything that must commit together: number, sale, movements, audit.
     */
    private function createSale(MenuItem $item, int $quantity, ?string $posReference, ?CarbonInterface $soldAt): Sale
    {
        $lines = $item->recipeLines()->with('ingredient')->orderBy('id')->get();

        if ($lines->isEmpty()) {
            throw new MenuItemNotSellable($item);
        }

        $soldAt ??= now();

        $sale = Sale::create([
            'number' => $this->numbers->next(DocumentNumber::SALE),
            'menu_item_id' => $item->id,
            'quantity' => $quantity,
            'pos_reference' => $posReference,
            'sold_at' => $soldAt,
        ]);

        foreach ($lines as $line) {
            // quantity per portion times portions sold, negative because stock goes out. The ledger is
            // the only writer of movements and does not check the balance (D-010).
            $this->ledger->record($line->ingredient, -($line->quantity * $quantity), MovementReason::Sale, $sale, $soldAt);
        }

        Audit::record('sale.recorded', $sale, [
            'menu_item' => $item->ulid,
            'quantity' => $quantity,
            'pos_reference' => $posReference,
        ], "{$sale->number} {$item->name} x{$quantity}");

        return $sale;
    }

    /**
     * Answer a retry: same payload returns the original untouched, a different one is a conflict (D-029).
     */
    private function replay(Sale $existing, MenuItem $item, int $quantity): SaleResult
    {
        if ($existing->menu_item_id !== $item->id || $existing->quantity !== $quantity) {
            Log::channel('pos')->warning('POS reference reused with a different payload', [
                'pos_reference' => $existing->pos_reference,
                'sale' => $existing->ulid,
                'original_menu_item' => $existing->menuItem->ulid,
                'original_quantity' => $existing->quantity,
                'attempted_menu_item' => $item->ulid,
                'attempted_quantity' => $quantity,
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
     * Protected so a test can simulate the race (R4) by making the first lookup miss.
     */
    protected function findByReference(string $posReference): ?Sale
    {
        return Sale::query()->with('menuItem')->where('pos_reference', $posReference)->first();
    }
}
