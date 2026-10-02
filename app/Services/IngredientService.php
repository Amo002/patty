<?php

namespace App\Services;

use App\Exceptions\Domain\UnitLocked;
use App\Models\Ingredient;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Ingredients (FR-1, F1, F2).
 *
 * Quantities elsewhere in the system are plain integers in the ingredient's
 * unit, so the unit is the one field that can corrupt stock history if it
 * changes late. The lock lives here, not in the controller (Q-007).
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
     * @throws UnitLocked when the unit would change and the ingredient already has movements
     */
    public function update(Ingredient $ingredient, array $data): Ingredient
    {
        DB::transaction(function () use ($ingredient, $data) {
            // The existence check runs inside the transaction so a movement committed
            // between the check and the save cannot slip past the lock.
            if (isset($data['unit']) && $ingredient->unit->value !== $data['unit'] && $ingredient->stockMovements()->exists()) {
                // $ingredient still holds the stored unit here, which is the one the message names.
                throw UnitLocked::for($ingredient);
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
     * Attach `on_hand` and `has_movements` for one ingredient.
     */
    public function withStock(Ingredient $ingredient): Ingredient
    {
        $this->attachStock([$ingredient]);

        return $ingredient;
    }

    /**
     * Set `on_hand` and `has_movements` on each ingredient from ONE grouped query.
     *
     * onHandForAll() has a row for every ingredient that ever had a movement,
     * even when the sum is 0, so "has a row" is exactly "has stock history".
     * That gives the unit-lock flag for free, with no per-row query.
     *
     * @param  iterable<Ingredient>  $ingredients
     */
    private function attachStock(iterable $ingredients): void
    {
        $onHand = $this->ledger->onHandForAll();

        foreach ($ingredients as $ingredient) {
            $ingredient->setAttribute('on_hand', $onHand->get($ingredient->id, 0));
            $ingredient->setAttribute('has_movements', $onHand->has($ingredient->id));
        }
    }
}
