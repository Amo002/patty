<?php

namespace App\Http\Requests\V1\Concerns;

use App\Models\Ingredient;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The `lines` rules shared by E17 (create) and E19 (replace), per validation.md.
 *
 * One definition, so the two endpoints can never accept different line
 * shapes. Also turns errors like "lines.1.quantity_ordered" into
 * "Line 2 (Beef): quantity must be at least 1" (G7), and resolves each
 * ingredient ULID to its model so the service never sees a raw string (G4).
 */
trait PurchaseOrderLineRules
{
    /**
     * @var array<int, array{ingredient: Ingredient, quantity_ordered: int}>
     */
    private array $resolvedLines = [];

    /**
     * @return array<string, array<int, mixed>>
     */
    protected function lineRules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.ingredient_id' => ['required', 'string', 'ulid', Rule::exists('ingredients', 'ulid'), 'distinct'],
            'lines.*.quantity_ordered' => ['required', 'integer', 'min:1', 'max:1000000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function lineAttributes(): array
    {
        return [
            'lines.*.ingredient_id' => 'ingredient',
            'lines.*.quantity_ordered' => 'quantity',
        ];
    }

    /**
     * Stored ULIDs are lowercase; the `ulid` rule also accepts uppercase, which
     * would then fail `exists`. Lowercase first so both spellings work.
     */
    protected function lowercaseLineIngredientIds(): void
    {
        $lines = $this->input('lines');

        if (! is_array($lines)) {
            return;
        }

        foreach ($lines as $index => $line) {
            if (is_array($line) && isset($line['ingredient_id']) && is_string($line['ingredient_id'])) {
                $lines[$index]['ingredient_id'] = strtolower($line['ingredient_id']);
            }
        }

        $this->merge(['lines' => $lines]);
    }

    /**
     * Replace each validated line's ULID with the Ingredient model.
     */
    protected function resolveLines(): void
    {
        $validated = $this->validated('lines');
        $ingredients = Ingredient::query()
            ->whereIn('ulid', array_column($validated, 'ingredient_id'))
            ->get()
            ->keyBy('ulid');

        $this->resolvedLines = array_map(fn (array $line) => [
            'ingredient' => $ingredients[$line['ingredient_id']],
            'quantity_ordered' => (int) $line['quantity_ordered'],
        ], $validated);
    }

    /**
     * @return array<int, array{ingredient: Ingredient, quantity_ordered: int}>
     */
    public function lines(): array
    {
        return $this->resolvedLines;
    }

    /**
     * Prefix line errors with the line number and ingredient name (G7).
     */
    protected function failedValidation(Validator $validator): void
    {
        $messages = [];

        foreach ($validator->errors()->toArray() as $key => $errors) {
            foreach ($errors as $message) {
                $messages[$key][] = $this->prefixLineError($key, $message);
            }
        }

        throw ValidationException::withMessages($messages);
    }

    private function prefixLineError(string $key, string $message): string
    {
        if (! preg_match('/^lines\.(\d+)\./', $key, $match)) {
            return $message;
        }

        $index = (int) $match[1];
        $ulid = $this->input("lines.$index.ingredient_id");
        $name = is_string($ulid) ? Ingredient::query()->where('ulid', $ulid)->value('name') : null;
        $label = 'Line '.($index + 1).($name !== null ? " ($name)" : '');

        // "The quantity field must be at least 1." becomes "quantity field must be at least 1."
        return $label.': '.Str::lcfirst(preg_replace('/^The /', '', $message));
    }
}
