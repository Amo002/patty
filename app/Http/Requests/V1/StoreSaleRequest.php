<?php

namespace App\Http\Requests\V1;

use App\Http\Requests\V1\Concerns\LowercasesMenuItemIds;
use App\Models\MenuItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * E25: a sale ticket from the POS (validation.md, Sales).
 */
class StoreSaleRequest extends FormRequest
{
    use LowercasesMenuItemIds;

    /** Seconds, milliseconds (v) or microseconds (u), each with an offset (P) or a literal Z. */
    private const SOLD_AT_FORMATS = [
        'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:s.vP', 'Y-m-d\TH:i:s.uP',
        'Y-m-d\TH:i:s\Z', 'Y-m-d\TH:i:s.v\Z', 'Y-m-d\TH:i:s.u\Z',
    ];

    private const MAX_QUANTITY = 1000;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // `list` rejects an object like {"a": {...}}, so line numbers in messages are always real positions.
            'lines' => ['required', 'array', 'list', 'min:1', 'max:50'],
            'lines.*.menu_item_id' => ['required', 'string', 'ulid', Rule::exists('menu_items', 'ulid')],
            // G3: `integer:strict` rejects `true` and "2", which plain `integer` would let through.
            'lines.*.quantity' => ['required', 'integer:strict', 'min:1', 'max:'.self::MAX_QUANTITY],
            // PCRE `$` also matches before a trailing newline, so "till-1\n" would pass the plain
            // pattern. `\z` anchors at the true end of the string.
            'pos_reference' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._:-]+\z/'],
            // ISO-8601 only (with an offset or Z, whole or fractional seconds). Plain `date` would also
            // take "yesterday" or "+1 hour", which a POS contract should not.
            'sold_at' => [
                'nullable', 'bail',
                'date_format:'.implode(',', self::SOLD_AT_FORMATS),
                // The 5 minutes of slack cover clock skew between the till and this server.
                // Compared as instants, so the offset the till used does not matter.
                fn (string $attribute, mixed $value, \Closure $fail) => Carbon::parse($value)->gt(now()->addMinutes(5))
                    ? $fail('The sale time cannot be more than 5 minutes in the future.')
                    : null,
            ],
        ];
    }

    /**
     * `:position` is Laravel's 1-based index of the `*` in the rule key, so line 0 reads "Line 1".
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $quantity = 'Line :position: the quantity must be a whole number from 1 to 1,000.';

        return [
            'lines.required' => 'Add at least one menu item to the sale.',
            'lines.array' => 'Add at least one menu item to the sale.',
            'lines.list' => 'Add at least one menu item to the sale.',
            'lines.min' => 'Add at least one menu item to the sale.',
            'lines.max' => 'A sale can hold at most 50 lines.',
            'lines.*.menu_item_id.required' => 'Line :position: choose the menu item that was sold.',
            'lines.*.menu_item_id.string' => 'Line :position: the menu item must be an id.',
            'lines.*.menu_item_id.ulid' => 'Line :position: the menu item is not a valid id.',
            'lines.*.menu_item_id.exists' => 'Line :position: that menu item does not exist.',
            'lines.*.quantity.required' => $quantity,
            'lines.*.quantity.integer' => $quantity,
            'lines.*.quantity.min' => $quantity,
            'lines.*.quantity.max' => $quantity,
            'pos_reference.string' => 'The POS reference must be text.',
            'pos_reference.max' => 'The POS reference must be at most 64 characters.',
            'pos_reference.regex' => 'The POS reference may only contain letters, digits and . _ : -',
            'sold_at.date_format' => 'The sale time must be ISO 8601, for example 2026-10-01T14:00:00Z.',
        ];
    }

    /**
     * D-048: duplicates are merged before anything else, so the 1,000 cap applies to the merged
     * quantity. Each line was already within 1,000, but two lines of 600 are not. Runs only when
     * every line is otherwise valid, so the sums are over clean numbers.
     *
     * @return array<int, \Closure>
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $totals = [];
            $firstIndex = [];

            foreach ($this->validatedLines($validator) as $index => $line) {
                $id = $line['menu_item_id'];
                $firstIndex[$id] ??= $index;
                $totals[$id] = ($totals[$id] ?? 0) + $line['quantity'];
            }

            $over = array_filter($totals, fn (int $total) => $total > self::MAX_QUANTITY);
            $names = MenuItem::query()->whereIn('ulid', array_keys($over))->pluck('name', 'ulid');

            foreach (array_keys($over) as $id) {
                // Keyed on the first row of that item. A dedicated message, because the row itself
                // may look fine (600): the problem is the repeat.
                $validator->errors()->add(
                    "lines.{$firstIndex[$id]}.quantity",
                    'Line '.($firstIndex[$id] + 1).": {$names[$id]} appears more than once and adds up to more than 1,000.",
                );
            }
        }];
    }

    /**
     * The ticket as the service expects it: one entry per menu item (duplicates added together),
     * all items fetched in ONE query.
     *
     * @return list<array{item: MenuItem, quantity: int}>
     */
    public function lines(): array
    {
        $quantities = [];

        foreach ($this->validated('lines') as $line) {
            $quantities[$line['menu_item_id']] = ($quantities[$line['menu_item_id']] ?? 0) + (int) $line['quantity'];
        }

        $items = MenuItem::query()->whereIn('ulid', array_keys($quantities))->get()->keyBy('ulid');

        return array_values(array_map(
            fn (string $ulid) => ['item' => $items[$ulid], 'quantity' => $quantities[$ulid]],
            array_keys($quantities),
        ));
    }

    public function soldAt(): ?Carbon
    {
        $value = $this->validated('sold_at');

        // Eloquent stores the wall-clock time without converting it, so a "+03:00" value must become
        // UTC here or it would be saved 3 hours off (the database holds UTC, as DocumentNumber assumes).
        return $value === null ? null : Carbon::parse($value)->utc();
    }

    /**
     * @return array<int, array{menu_item_id: string, quantity: int}>
     */
    private function validatedLines(Validator $validator): array
    {
        return $validator->validated()['lines'];
    }
}
