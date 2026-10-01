<?php

namespace App\Http\Requests\V1\Concerns;

use App\Models\Ingredient;
use Illuminate\Validation\Rule;

/**
 * The line rules shared by E12 (key `recipe`) and E15 (key `lines`), so the two
 * endpoints can never drift apart (validation.md, Menu items).
 *
 * The using request names its array key with linesKey(). The trait turns the
 * validated ULIDs into Ingredient models once, in resolveLines(), so the service
 * never sees a string or an integer id from input (G4).
 */
trait RecipeLineRules
{
    /**
     * @var array<int, array{ingredient: Ingredient, quantity: int}>
     */
    private array $resolvedLines = [];

    /**
     * The request key that holds the array of lines.
     */
    abstract protected function linesKey(): string;

    /**
     * Stored ULIDs are lowercase, but the `ulid` rule also accepts uppercase.
     * Lowercase before validation so `exists` and `distinct` compare like with
     * like (an uppercase and a lowercase copy of one ingredient are a duplicate).
     */
    protected function lowercaseLineIngredientIds(): void
    {
        $lines = $this->input($this->linesKey());

        if (! is_array($lines)) {
            return;
        }

        foreach ($lines as $index => $line) {
            if (is_array($line) && is_string($line['ingredient_id'] ?? null)) {
                $lines[$index]['ingredient_id'] = strtolower($line['ingredient_id']);
            }
        }

        $this->merge([$this->linesKey() => $lines]);
    }

    /**
     * G3, G4, G5 rules for each line. Array-level rules are added by the request
     * because E12 makes the array optional and E15 requires it.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function lineRules(): array
    {
        $key = $this->linesKey();

        return [
            "{$key}.*.ingredient_id" => ['required', 'string', 'ulid', Rule::exists('ingredients', 'ulid'), 'distinct'],
            "{$key}.*.quantity" => ['required', 'integer', 'min:1', 'max:1000000'],
        ];
    }

    /**
     * G7: "Line 2 (Beef): quantity must be at least 1." One message per line and
     * rule, built from the submitted lines, because the line number and the
     * ingredient name differ for every line and Laravel's own placeholders cannot
     * carry them.
     *
     * @return array<string, string>
     */
    protected function lineMessages(): array
    {
        $key = $this->linesKey();
        $lines = $this->input($key);
        $messages = [
            "{$key}.array" => 'Recipe lines must be a list.',
            "{$key}.min" => 'A recipe needs at least one ingredient line.',
            "{$key}.max" => 'A recipe can have at most 50 ingredient lines.',
            "{$key}.required" => 'A recipe needs at least one ingredient line.',
        ];

        if (! is_array($lines)) {
            return $messages;
        }

        $names = $this->ingredientNames($lines);

        foreach (array_keys($lines) as $index) {
            $line = $lines[$index];
            $label = 'Line '.((int) $index + 1);
            $name = is_array($line) && is_string($line['ingredient_id'] ?? null) ? ($names[$line['ingredient_id']] ?? null) : null;
            $prefix = $name === null ? "{$label}:" : "{$label} ({$name}):";

            $messages["{$key}.{$index}.ingredient_id.required"] = "{$prefix} choose an ingredient.";
            $messages["{$key}.{$index}.ingredient_id.string"] = "{$prefix} ingredient must be an id.";
            $messages["{$key}.{$index}.ingredient_id.ulid"] = "{$prefix} ingredient is not a valid id.";
            $messages["{$key}.{$index}.ingredient_id.exists"] = "{$prefix} that ingredient does not exist.";
            $messages["{$key}.{$index}.ingredient_id.distinct"] = "{$prefix} ingredient is listed more than once.";
            $messages["{$key}.{$index}.quantity.required"] = "{$prefix} quantity is required.";
            $messages["{$key}.{$index}.quantity.integer"] = "{$prefix} quantity must be a whole number.";
            $messages["{$key}.{$index}.quantity.min"] = "{$prefix} quantity must be at least 1.";
            $messages["{$key}.{$index}.quantity.max"] = "{$prefix} quantity must be at most 1,000,000.";
        }

        return $messages;
    }

    /**
     * Ingredient names for the ULIDs the client sent, for error messages only.
     *
     * @param  array<int|string, mixed>  $lines
     * @return array<string, string> ulid => name
     */
    private function ingredientNames(array $lines): array
    {
        $ulids = collect($lines)
            ->map(fn ($line) => is_array($line) ? ($line['ingredient_id'] ?? null) : null)
            ->filter(fn ($value) => is_string($value))
            ->all();

        return Ingredient::query()->whereIn('ulid', $ulids)->pluck('name', 'ulid')->all();
    }

    /**
     * Swap the validated ULIDs for models (G4). Call from passedValidation().
     */
    protected function resolveLines(): void
    {
        $lines = $this->validated($this->linesKey());

        if ($lines === null) {
            $this->resolvedLines = [];

            return;
        }

        $ingredients = Ingredient::query()
            ->whereIn('ulid', array_column($lines, 'ingredient_id'))
            ->get()
            ->keyBy('ulid');

        $this->resolvedLines = array_map(fn (array $line) => [
            'ingredient' => $ingredients[$line['ingredient_id']],
            'quantity' => (int) $line['quantity'],
        ], array_values($lines));
    }

    /**
     * The validated lines with ingredient models, in the order the client sent them.
     *
     * @return array<int, array{ingredient: Ingredient, quantity: int}>
     */
    public function lines(): array
    {
        return $this->resolvedLines;
    }
}
