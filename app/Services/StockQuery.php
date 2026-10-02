<?php

namespace App\Services;

use App\Enums\PurchaseOrderStatus;
use App\Models\DeliveryLine;
use App\Models\Ingredient;
use App\Models\PurchaseOrderLine;
use App\Models\Sale;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;

/**
 * Read-only views over stock and purchasing (FR-6, F17 to F19).
 *
 * Nothing here writes. On-hand always comes from StockLedger (D-004), so the
 * formula exists once. Everything is computed on request and never cached, so
 * a screen can never show a number older than the last committed delivery or sale (D-006).
 */
class StockQuery
{
    public function __construct(private readonly StockLedger $ledger) {}

    /**
     * E27: a page of ingredients by name, each with `on_hand` and `incoming` attached.
     *
     * Query count is constant in the page size: one page query, one count, ONE grouped
     * on-hand sum (D-004) and one open-lines query for incoming. Display-only attributes,
     * never save() the models afterwards.
     */
    public function stock(int $perPage): LengthAwarePaginator
    {
        $page = Ingredient::query()->orderBy('name')->orderBy('id')->paginate($perPage);

        $this->attachStock($page->items());

        return $page;
    }

    /**
     * Set `on_hand` and `incoming` on each ingredient with a constant number of queries.
     *
     * @param  iterable<Ingredient>  $ingredients
     */
    public function attachStock(iterable $ingredients): void
    {
        $ingredients = collect($ingredients);
        $onHand = $this->ledger->onHandForAll();

        foreach ($ingredients as $ingredient) {
            $ingredient->setAttribute('on_hand', $onHand->get($ingredient->id, 0));
        }

        $this->attachIncoming($ingredients);
    }

    /**
     * Set only `incoming` (one open-lines query), for callers that already attach on_hand.
     *
     * @param  iterable<Ingredient>  $ingredients
     */
    public function attachIncoming(iterable $ingredients): void
    {
        $ingredients = collect($ingredients);
        $incoming = $this->incomingByIngredient($ingredients->pluck('id')->all());

        foreach ($ingredients as $ingredient) {
            $ingredient->setAttribute('incoming', $incoming->get($ingredient->id, 0));
        }
    }

    /**
     * Quantity still expected from suppliers for one ingredient (D-020).
     */
    public function incomingFor(Ingredient $ingredient): int
    {
        return $this->incomingByIngredient([$ingredient->id])->get($ingredient->id, 0);
    }

    /**
     * Incoming per ingredient id: SUM of outstanding over lines of sent or received orders.
     *
     * Computed in PHP, not SQL, on purpose. "Outstanding" depends on the line's
     * tolerance snapshot (D-035): a line is complete at min_to_complete and a
     * complete line contributes 0, even with a within-tolerance shortfall (D-036).
     * That rule already lives in PurchaseOrderLine::outstanding(); repeating it in SQL
     * would give two definitions that could drift. The lines are loaded once with their
     * received total in a subquery (withSum), so the query count stays constant.
     * Draft and closed orders are excluded by openLines().
     *
     * @param  array<int, int>|null  $ingredientIds  limit to these ingredients, or null for all
     * @return Collection<int, int>
     */
    private function incomingByIngredient(?array $ingredientIds = null): Collection
    {
        return $this->openLines($ingredientIds)
            ->groupBy('ingredient_id')
            ->map(fn (Collection $lines) => $lines->sum(fn (PurchaseOrderLine $line) => $line->outstanding()))
            ->filter(fn (int $quantity) => $quantity > 0);
    }

    /**
     * Lines of orders still waiting on a supplier (sent or received), with `received_sum` loaded.
     *
     * @param  array<int, int>|null  $ingredientIds
     * @return Collection<int, PurchaseOrderLine>
     */
    private function openLines(?array $ingredientIds = null): Collection
    {
        return PurchaseOrderLine::query()
            ->whereHas('purchaseOrder', fn ($orders) => $orders->whereIn('status', PurchaseOrderStatus::open()))
            ->when($ingredientIds !== null, fn ($lines) => $lines->whereIn('ingredient_id', $ingredientIds))
            ->withSum('deliveryLines as received_sum', 'quantity_received')
            ->get();
    }

    /**
     * E28: the KPI counts.
     *
     * negative_count reuses onHandForAll() so "negative" means the same thing as on E27;
     * an ingredient with no movements has no row there and is 0, not negative.
     *
     * @return array{ingredients_count: int, negative_count: int, open_orders_count: int, outstanding_lines_count: int}
     */
    public function dashboard(): array
    {
        return [
            'ingredients_count' => Ingredient::query()->count(),
            'negative_count' => $this->ledger->onHandForAll()->filter(fn (int $onHand) => $onHand < 0)->count(),
            'open_orders_count' => DB::table('purchase_orders')->whereIn('status', PurchaseOrderStatus::open())->count(),
            'outstanding_lines_count' => $this->openLines()->filter(fn (PurchaseOrderLine $line) => $line->outstanding() > 0)->count(),
        ];
    }

    /**
     * E6: movements of one ingredient, newest first, each with `balance_after` and `reference_info`.
     *
     * balance_after is a window function: SUM(quantity_delta) OVER (ORDER BY occurred_at, id).
     * No PARTITION BY is needed: the WHERE already limits the set to one ingredient.
     * Ordering by occurred_at (business time) matters because deliveries and sales can be
     * backdated, so insert order is not history order. SQL evaluates the window over the whole
     * filtered set before LIMIT/OFFSET, so page 2 continues the running balance from page 1
     * instead of restarting.
     * The id tie-break keeps rows with the same occurred_at in a stable order. The newest row's
     * balance_after therefore equals on-hand, by the same arithmetic.
     * The reference label comes from eager-loaded relations: a constant number of queries.
     */
    public function movements(Ingredient $ingredient, int $perPage): LengthAwarePaginator
    {
        $page = StockMovement::query()
            ->select('stock_movements.*')
            ->selectRaw('SUM(quantity_delta) OVER (ORDER BY occurred_at, id) as balance_after')
            ->where('ingredient_id', $ingredient->getKey())
            ->with(['reference' => fn (MorphTo $reference) => $reference->morphWith([
                DeliveryLine::class => ['delivery.purchaseOrder'],
                Sale::class => ['menuItem'],
            ])])
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate($perPage);

        foreach ($page->items() as $movement) {
            $movement->setAttribute('reference_info', $this->describeReference($movement));
        }

        return $page;
    }

    /**
     * The source document of a movement as {type, number, label} plus the public id (ULID) of the
     * document a manager can open: the purchase order for a delivery, the sale for a sale (F18).
     * Never an integer id (D-031).
     *
     * @return array<string, string|null>
     */
    private function describeReference(StockMovement $movement): array
    {
        $reference = $movement->reference;

        if ($reference instanceof DeliveryLine) {
            $delivery = $reference->delivery;

            return [
                'type' => 'delivery',
                'number' => $delivery->number,
                'label' => "{$delivery->number} for {$delivery->purchaseOrder->number}",
                'purchase_order_id' => $delivery->purchaseOrder->ulid,
            ];
        }

        if ($reference instanceof Sale) {
            return [
                'type' => 'sale',
                'number' => $reference->number,
                'label' => "{$reference->number} {$reference->menuItem->name} x{$reference->quantity}",
                'sale_id' => $reference->ulid,
            ];
        }

        // A future movement reason (waste, adjustment) with no document yet.
        return ['type' => $movement->reference_type, 'number' => null, 'label' => null];
    }

    /**
     * E29: audit entries, newest first, optionally for one subject.
     *
     * @param  string|null  $subjectType  morph alias (purchase_order, ingredient, ...)
     * @param  string|null  $subjectUlid  public id of the subject; an unknown one yields an empty page
     */
    public function activity(?string $subjectType, ?string $subjectUlid, int $perPage): LengthAwarePaginator
    {
        $query = Activity::query()->orderByDesc('created_at')->orderByDesc('id');

        if ($subjectType !== null && $subjectUlid !== null) {
            $class = Relation::getMorphedModel($subjectType);
            $subjectKey = $class::query()->where('ulid', $subjectUlid)->value('id');

            // An unknown ULID matches nothing; 0 is never a key.
            $query->where('subject_type', $subjectType)->where('subject_id', $subjectKey ?? 0);
        }

        $page = $query->with('subject')->paginate($perPage);

        return $page->through(fn (Activity $activity) => $this->describeActivity($activity));
    }

    /**
     * One audit row shaped for the API, with no integer ids (D-031).
     *
     * @return array<string, mixed>
     */
    private function describeActivity(Activity $activity): array
    {
        $subject = $activity->subject;
        $properties = $activity->properties;

        return [
            'event' => $activity->event,
            'description' => $activity->description,
            'subject' => [
                'type' => $activity->subject_type,
                // A deleted draft has no row any more: keep the label from the event, drop the id.
                'id' => $subject?->ulid,
                // A deleted subject keeps the number its own events recorded, else a generic label.
                // Filtering by a deleted subject's ULID returns an empty page: the ULID no longer resolves.
                'label' => ($subject?->number ?? $subject?->name)
                    ?? $properties->get('number')
                    ?? 'Deleted '.str_replace('_', ' ', (string) $activity->subject_type),
            ],
            'channel' => $properties->get('channel'),
            'request_id' => $properties->get('request_id'),
            'changes' => self::reshapeChanges(
                ($activity->attribute_changes ?? collect())->get('attributes', []),
                ($activity->attribute_changes ?? collect())->get('old', []),
            ),
            'created_at' => $activity->created_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * spatie v5 stores {attributes: {field: new}, old: {field: old}}; the API shows {field: [old, new]}.
     * Dropped: `id` and `*_id` (internal keys must never leak, D-031) and `image_path`
     * (an internal storage path; the API exposes only image_url). No model logs an `*_id`
     * field today, so that guard is defence in depth for a future `logOnly` change.
     *
     * @param  array<string, mixed>  $new
     * @param  array<string, mixed>  $old
     * @return array<string, array{0: mixed, 1: mixed}>
     */
    public static function reshapeChanges(array $new, array $old): array
    {
        $result = [];
        foreach ($new as $field => $value) {
            if ($field === 'id' || str_ends_with($field, '_id') || $field === 'image_path') {
                continue;
            }

            $result[$field] = [$old[$field] ?? null, $value];
        }

        return $result;
    }
}
