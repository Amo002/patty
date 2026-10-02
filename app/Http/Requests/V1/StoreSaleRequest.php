<?php

namespace App\Http\Requests\V1;

use App\Models\MenuItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * E25: a sale event from the POS (validation.md, Sales).
 */
class StoreSaleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Stored ULIDs are lowercase but the `ulid` rule accepts uppercase, so lowercase first (G4).
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->input('menu_item_id'))) {
            $this->merge(['menu_item_id' => strtolower($this->input('menu_item_id'))]);
        }
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'menu_item_id' => ['required', 'string', 'ulid', Rule::exists('menu_items', 'ulid')],
            // G3: `integer:strict` rejects `true` and "2", which plain `integer` would let through.
            'quantity' => ['required', 'integer:strict', 'min:1', 'max:1000'],
            // PCRE `$` also matches before a trailing newline, so "till-1\n" would pass the plain
            // pattern. `\z` anchors at the true end of the string.
            'pos_reference' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9._:-]+\z/'],
            // The 5 minutes of slack cover clock skew between the till and this server.
            'sold_at' => ['nullable', 'date', 'before_or_equal:'.now()->addMinutes(5)->toIso8601String()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'menu_item_id.required' => 'Choose the menu item that was sold.',
            'menu_item_id.string' => 'The menu item must be an id.',
            'menu_item_id.ulid' => 'The menu item is not a valid id.',
            'menu_item_id.exists' => 'That menu item does not exist.',
            'quantity.required' => 'The quantity sold is required.',
            'quantity.integer' => 'The quantity must be a whole number.',
            'quantity.min' => 'The quantity must be at least 1.',
            'quantity.max' => 'The quantity must be at most 1,000 per sale.',
            'pos_reference.string' => 'The POS reference must be text.',
            'pos_reference.max' => 'The POS reference must be at most 64 characters.',
            'pos_reference.regex' => 'The POS reference may only contain letters, digits and . _ : -',
            'sold_at.date' => 'The sale time must be a valid date and time.',
            'sold_at.before_or_equal' => 'The sale time cannot be more than 5 minutes in the future.',
        ];
    }

    public function menuItem(): MenuItem
    {
        return MenuItem::query()->where('ulid', $this->validated('menu_item_id'))->firstOrFail();
    }

    public function soldAt(): ?Carbon
    {
        $value = $this->validated('sold_at');

        return $value === null ? null : Carbon::parse($value);
    }
}
