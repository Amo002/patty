<?php

namespace App\Http\Requests\V1;

use App\Http\Requests\V1\Concerns\RecipeLineRules;
use App\Models\Ingredient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * E12: create a menu item, optionally with its recipe (validation.md, Menu items).
 */
class StoreMenuItemRequest extends FormRequest
{
    use RecipeLineRules;

    public function authorize(): bool
    {
        return true;
    }

    protected function linesKey(): string
    {
        return 'recipe';
    }

    protected function prepareForValidation(): void
    {
        $this->lowercaseLineIngredientIds();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            // G6: unique case-insensitively. The column is NOCASE, so the plain equality check here is too.
            'name' => ['required', 'string', 'min:2', 'max:100', Rule::unique('menu_items', 'name')],
            'recipe' => ['sometimes', 'array', 'min:1', 'max:50'],
            ...$this->lineRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.unique' => 'A menu item called '.$this->input('name').' already exists.',
            'name.min' => 'The name must be at least 2 characters.',
            'name.max' => 'The name must be at most 100 characters.',
            ...$this->lineMessages(),
        ];
    }

    protected function passedValidation(): void
    {
        $this->resolveLines();
    }

    /**
     * Null when the client sent no recipe, so the service can tell "no recipe" from "empty".
     *
     * @return array<int, array{ingredient: Ingredient, quantity: int}>|null
     */
    public function recipeLines(): ?array
    {
        return $this->has('recipe') ? $this->lines() : null;
    }
}
