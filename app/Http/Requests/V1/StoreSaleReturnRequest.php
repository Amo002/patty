<?php

namespace App\Http\Requests\V1;

use App\Http\Requests\V1\Concerns\LowercasesMenuItemIds;
use App\Models\MenuItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * E27: a refund against one sale (validation.md, Sale refunds).
 *
 * Whether the item is on the sale and whether enough is left are not shape questions: they depend on
 * the sale's current refunds, so SaleReturnService checks them under the sale's lock (D-049).
 */
class StoreSaleReturnRequest extends FormRequest
{
    use LowercasesMenuItemIds;

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
            // D-049: no default. `boolean:strict` accepts only true or false, not "true", 1 or "0", because a
            // wrong guess about the stock is the one mistake this field exists to prevent.
            'restock' => ['required', 'boolean:strict'],
            // S16: no line breaks or control characters, so a reason can never forge a log line.
            'reason' => ['required', 'string', 'max:200', 'not_regex:/[\x00-\x1F\x7F]/'],
            // Same rules as on sales; PCRE `$` also matches before a trailing newline, so `\z` is the true end.
            'pos_reference' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._:-]+\z/'],
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
        $item = 'Line :position: choose the menu item to refund.';
        $restock = 'Choose whether the ingredients go back to stock.';
        $reason = 'Give a reason for the refund.';

        return [
            'lines.required' => 'Add at least one item to refund.',
            'lines.array' => 'Add at least one item to refund.',
            'lines.list' => 'Add at least one item to refund.',
            'lines.min' => 'Add at least one item to refund.',
            'lines.max' => 'A refund can hold at most 50 lines.',
            'lines.*.menu_item_id.required' => $item,
            'lines.*.menu_item_id.string' => $item,
            'lines.*.menu_item_id.ulid' => $item,
            'lines.*.menu_item_id.exists' => 'Line :position: that menu item does not exist.',
            'lines.*.quantity.required' => $quantity,
            'lines.*.quantity.integer' => $quantity,
            'lines.*.quantity.min' => $quantity,
            'lines.*.quantity.max' => $quantity,
            'restock.required' => $restock,
            'restock.boolean' => $restock,
            'reason.required' => $reason,
            'reason.string' => $reason,
            'reason.max' => 'The reason must be at most 200 characters.',
            'reason.not_regex' => 'The reason cannot contain line breaks or other control characters.',
            'pos_reference.string' => 'The POS reference must be text.',
            'pos_reference.max' => 'The POS reference must be at most 64 characters.',
            'pos_reference.regex' => 'The POS reference may only contain letters, digits and . _ : -',
        ];
    }

    /**
     * The refund rows exactly as sent, one entry per request row with the menu items fetched in ONE query.
     * Not merged here: the service merges duplicates and keeps each item's first row for its error keys.
     *
     * @return list<array{item: MenuItem, quantity: int}>
     */
    public function lines(): array
    {
        $rows = $this->validated('lines');
        $items = MenuItem::query()->whereIn('ulid', array_column($rows, 'menu_item_id'))->get()->keyBy('ulid');

        return array_map(
            fn (array $row) => ['item' => $items[$row['menu_item_id']], 'quantity' => (int) $row['quantity']],
            $rows,
        );
    }
}
