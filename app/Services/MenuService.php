<?php

namespace App\Services;

use App\Models\Ingredient;
use App\Models\MenuItem;
use App\Support\Audit;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Menu items and their recipes (FR-2, F3, F4).
 *
 * A recipe is only ever replaced as a whole (E15), never edited line by line, so
 * a sale always reads one consistent recipe. Past sales are untouched by a
 * change: their stock movements were written at sale time (Q-007).
 *
 * Lines arrive as [['ingredient' => Ingredient, 'quantity' => int], ...], already
 * validated and resolved by the FormRequest (G1, G4).
 */
class MenuService
{
    /**
     * A page of menu items by name, with recipes loaded in two extra queries in
     * total (not one per item), so the list stays flat as the menu grows (D-032).
     */
    public function paginate(int $perPage): LengthAwarePaginator
    {
        return MenuItem::query()
            ->with($this->recipeRelations())
            ->orderBy('name')
            ->paginate($perPage);
    }

    /**
     * Load the recipe with ingredient names and units, in the order the lines were saved.
     */
    public function withRecipe(MenuItem $item): MenuItem
    {
        return $item->load($this->recipeRelations());
    }

    /**
     * Create a menu item, with its recipe when one is given (F3).
     *
     * Both writes and the audit entry share one transaction, so a failure leaves
     * neither a nameless half-created item nor a trail entry for a recipe that
     * was never saved. A null or empty $lines creates an item that cannot be
     * sold yet (`is_sellable: false`).
     *
     * @param  array<int, array{ingredient: Ingredient, quantity: int}>|null  $lines
     */
    public function create(string $name, ?array $lines = null): MenuItem
    {
        $item = DB::transaction(function () use ($name, $lines) {
            $item = MenuItem::create(['name' => $name]);

            if ($lines !== null && $lines !== []) {
                $this->writeRecipe($item, $lines);
            }

            return $item;
        });

        Log::channel('catalog')->info('Menu item created', ['menu_item' => $item->ulid, 'name' => $item->name]);

        return $this->withRecipe($item);
    }

    /**
     * Rename a menu item (E14). The LogsActivity trait records the name change.
     */
    public function rename(MenuItem $item, string $name): MenuItem
    {
        $oldName = $item->name;

        DB::transaction(fn () => $item->update(['name' => $name]));

        Log::channel('catalog')->info('Menu item renamed', ['menu_item' => $item->ulid, 'old' => $oldName, 'new' => $name]);

        return $this->withRecipe($item);
    }

    /**
     * Replace the whole recipe in one transaction (F4).
     *
     * Reads the old lines, deletes them, inserts the new ones and writes the
     * `recipe.replaced` audit entry with both. In one transaction a reader never
     * sees a recipe half-way between old and new, and the entry always matches
     * what was stored.
     *
     * @param  array<int, array{ingredient: Ingredient, quantity: int}>  $lines
     */
    public function replaceRecipe(MenuItem $item, array $lines): MenuItem
    {
        DB::transaction(fn () => $this->writeRecipe($item, $lines));

        Log::channel('catalog')->info('Recipe replaced', ['menu_item' => $item->ulid, 'lines' => count($lines)]);

        return $this->withRecipe($item);
    }

    /**
     * The delete-insert-audit step shared by create (F3) and replace (F4).
     * Must run inside a transaction.
     *
     * @param  array<int, array{ingredient: Ingredient, quantity: int}>  $lines
     */
    private function writeRecipe(MenuItem $item, array $lines): void
    {
        $old = $item->recipeLines()->with('ingredient')->orderBy('id')->get()
            ->map(fn ($line) => $this->describe($line->ingredient, $line->quantity))
            ->all();

        $item->recipeLines()->delete();

        foreach ($lines as $line) {
            $item->recipeLines()->create([
                'ingredient_id' => $line['ingredient']->id,
                'quantity' => $line['quantity'],
            ]);
        }

        $new = array_map(fn ($line) => $this->describe($line['ingredient'], $line['quantity']), $lines);

        // Names and ULIDs, never integer ids (D-031): the entry is shown to the manager.
        Audit::record('recipe.replaced', $item, ['old' => $old, 'new' => $new], "Recipe for {$item->name} updated");
    }

    /**
     * @return array{ingredient: string, ingredient_id: string, quantity: int}
     */
    private function describe(Ingredient $ingredient, int $quantity): array
    {
        return ['ingredient' => $ingredient->name, 'ingredient_id' => $ingredient->ulid, 'quantity' => $quantity];
    }

    /**
     * @return array<int|string, mixed>
     */
    private function recipeRelations(): array
    {
        return ['recipeLines' => fn ($query) => $query->orderBy('id'), 'recipeLines.ingredient'];
    }
}
