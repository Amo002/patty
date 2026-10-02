<?php

namespace App\Services;

use App\Exceptions\Domain\UnitLocked;
use App\Models\Ingredient;
use App\Models\PurchaseOrderLine;
use App\Models\RecipeLine;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Ingredients (FR-1, F1, F2).
 *
 * Quantities elsewhere in the system are plain integers in the ingredient's
 * unit, so the unit is the one field that can corrupt stock, recipes and
 * orders if it changes late. The lock lives here, not in the controller (D-044).
 */
class IngredientService
{
    public function __construct(private readonly StockLedger $ledger) {}

    /**
     * A page of ingredients by name (D-032), each carrying its stock figures.
     */
    public function paginate(int $perPage): LengthAwarePaginator
    {
        $page = Ingredient::query()->orderBy('name')->orderBy('id')->paginate($perPage);

        $this->attachStock($page->items());

        return $page;
    }

    /**
     * Create an ingredient (F1). Tolerance fields are optional overrides (D-035);
     * a missing field means "use the unit default".
     *
     * @param  array<string, mixed>  $data  validated name, unit and optional tolerance fields
     */
    public function create(array $data): Ingredient
    {
        $ingredient = DB::transaction(fn () => Ingredient::create($data));

        Log::channel('catalog')->info('Ingredient created', [
            'ingredient' => $ingredient->ulid,
            'name' => $ingredient->name,
            'unit' => $ingredient->unit->value,
        ]);

        return $this->withStock($ingredient);
    }

    /**
     * Update name, unit or tolerance overrides (F2).
     *
     * Keys absent from $data are left alone; a key present with null resets a
     * tolerance field to the default (D-035). A no-op update writes no audit
     * row (LogsActivity logs dirty fields only) and, by the wasChanged() check,
     * no log line either.
     *
     * @param  array<string, mixed>  $data  only the validated keys the client sent
     *
     * @throws UnitLocked when the unit would change and the ingredient is already used (D-044)
     */
    public function update(Ingredient $ingredient, array $data): Ingredient
    {
        DB::transaction(function () use ($ingredient, $data) {
            // Re-read with lockForUpdate so the unit checked is the unit changed. This is a
            // no-op on SQLite: there the single-writer lock makes a racing write fail
            // ("database is locked") instead of slipping past the check. On MySQL and
            // Postgres it is a real row lock (same approach as PurchaseOrderService).
            $stored = Ingredient::query()->whereKey($ingredient->getKey())->lockForUpdate()->firstOrFail();

            // A unit sent unchanged ("g" to "g") is not a change, so it must not trip the lock.
            if (isset($data['unit']) && $stored->unit->value !== $data['unit']) {
                $usedBy = $this->usedBy($stored);

                if ($usedBy !== null) {
                    // $stored holds the stored unit, which is the one the message names.
                    throw UnitLocked::for($stored, $usedBy);
                }
            }

            $ingredient->update($data);
        });

        if ($ingredient->wasChanged()) {
            Log::channel('catalog')->info('Ingredient updated', [
                'ingredient' => $ingredient->ulid,
                'changed' => array_keys($ingredient->getChanges()),
            ]);
        }

        return $this->withStock($ingredient);
    }

    /**
     * Attach `on_hand` and `unit_locked` to one ingredient.
     *
     * The attributes are unsaved and display-only: never save() an instance
     * after this, or Eloquent would try to write columns that do not exist.
     *
     * One ingredient uses StockLedger::onHand() (its own sum) plus the usage
     * checks, rather than summing every ingredient for one row.
     */
    public function withStock(Ingredient $ingredient): Ingredient
    {
        $ingredient->setAttribute('on_hand', $this->ledger->onHand($ingredient));
        $ingredient->setAttribute('unit_locked', $this->usedBy($ingredient) !== null);

        return $ingredient;
    }

    /**
     * Why the unit is locked, or null when the ingredient is unused (D-044).
     *
     * Stock movements, recipe lines and PO lines all hold integers in the
     * ingredient's unit, so changing it would reinterpret every one of them
     * (a recipe's 150 g would become 150 ml). Three cheap EXISTS checks, the
     * first that hits names the reason.
     */
    public function usedBy(Ingredient $ingredient): ?string
    {
        return match (true) {
            $ingredient->stockMovements()->exists() => 'stock history',
            $ingredient->recipeLines()->exists() => 'recipes',
            $ingredient->purchaseOrderLines()->exists() => 'purchase orders',
            default => null,
        };
    }

    /**
     * Set `on_hand` and `unit_locked` on each page row with two queries in total.
     *
     * onHandForAll() has a row for every ingredient that ever had a movement,
     * even when the sum is 0, so "has a row" means "has stock history". One
     * UNION query lists every ingredient used by a recipe or a PO line. Neither
     * query depends on the number of rows, so the list never goes N+1 (D-032).
     * Display-only attributes, see withStock().
     *
     * @param  iterable<Ingredient>  $ingredients
     */
    private function attachStock(iterable $ingredients): void
    {
        $onHand = $this->ledger->onHandForAll();

        $inLines = RecipeLine::query()->select('ingredient_id')
            ->union(PurchaseOrderLine::query()->select('ingredient_id'))
            ->pluck('ingredient_id')
            ->flip();

        foreach ($ingredients as $ingredient) {
            $ingredient->setAttribute('on_hand', $onHand->get($ingredient->id, 0));
            $ingredient->setAttribute('unit_locked', $onHand->has($ingredient->id) || $inLines->has($ingredient->id));
        }
    }
}
