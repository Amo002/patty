<?php

namespace App\Http\Requests\V1;

use App\Http\Requests\V1\Concerns\RecipeLineRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * E15: replace the whole recipe. Unlike E12 the lines are required, so a recipe
 * can be replaced but never emptied through the API.
 */
class ReplaceRecipeRequest extends FormRequest
{
    use RecipeLineRules;

    public function authorize(): bool
    {
        return true;
    }

    protected function linesKey(): string
    {
        return 'lines';
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
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            ...$this->lineRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->lineMessages();
    }

    protected function passedValidation(): void
    {
        $this->resolveLines();
    }
}
