<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * E14: rename a menu item. `sometimes` per G10, with the same rules as create.
 */
class UpdateMenuItemRequest extends FormRequest
{
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
            // Ignore the item itself, or re-sending its own name (or only changing its case) would collide with itself.
            'name' => [
                'sometimes', 'required', 'string', 'min:2', 'max:100', 'not_regex:/[\x00-\x1F\x7F]/', // S16
                Rule::unique('menu_items', 'name')->ignore($this->route('menuItem')),
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'A menu item with this name already exists.',
            'name.min' => 'The name must be at least 2 characters.',
            'name.max' => 'The name must be at most 100 characters.',
            'name.not_regex' => 'The name cannot contain line breaks or other control characters.',
        ];
    }
}
