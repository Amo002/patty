<?php

namespace App\Http\Requests\V1;

use App\Http\Requests\V1\Concerns\IngredientRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * E5: change name, unit or tolerance overrides. `sometimes` per G10: a field
 * that is not sent is left alone, and a tolerance field sent as null resets to the default (D-035).
 */
class UpdateIngredientRequest extends FormRequest
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
        // Ignore the ingredient itself, or re-sending its own name (or only changing its case) would collide with itself.
        return $this->ingredientRules(
            ['sometimes', 'required'],
            Rule::unique('ingredients', 'name')->ignore($this->route('ingredient')),
        );
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->ingredientMessages();
    }
}
