<?php

namespace App\Http\Requests\V1;

use App\Http\Requests\V1\Concerns\IngredientRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * E3: create an ingredient (validation.md, Ingredients).
 */
class StoreIngredientRequest extends FormRequest
{
    use IngredientRules;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        // G6: the column is NOCASE, so plain equality is case-insensitive. TrimStrings has already removed padding.
        return $this->ingredientRules(['required'], Rule::unique('ingredients', 'name'));
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->ingredientMessages();
    }
}
