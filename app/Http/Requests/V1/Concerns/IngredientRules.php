<?php

namespace App\Http\Requests\V1\Concerns;

use App\Enums\Unit;
use Illuminate\Validation\Rule;

/**
 * Rules shared by E3 (create) and E5 (update) so the two cannot drift apart
 * (validation.md, Ingredients). The two requests differ only in which fields
 * are required and in the uniqueness ignore.
 */
trait IngredientRules
{
    /**
     * @param  array<int, string>  $presence  `['required']` on create, `['sometimes', 'required']` on update (G10)
     * @return array<string, array<int, mixed>>
     */
    protected function ingredientRules(array $presence, mixed $unique): array
    {
        return [
            // S16: no line breaks or control characters, so a name can never forge a log line.
            'name' => [...$presence, 'string', 'min:2', 'max:100', 'not_regex:/[\x00-\x1F\x7F]/', $unique],
            'unit' => [...$presence, Rule::enum(Unit::class)],
            // `integer:strict` rejects `true` and "5": JSON booleans would otherwise pass as 1.
            'over_tolerance_bps' => ['nullable', 'integer:strict', 'min:0', 'max:10000'],
            'under_tolerance_bps' => ['nullable', 'integer:strict', 'min:0', 'max:10000'],
            'over_tolerance_cap' => ['nullable', 'integer:strict', 'min:0', 'max:1000000'],
        ];
    }

    /**
     * Static text only: never interpolate input into a message.
     *
     * @return array<string, string>
     */
    protected function ingredientMessages(): array
    {
        return [
            'name.unique' => 'An ingredient with this name already exists.',
            'name.min' => 'The name must be at least 2 characters.',
            'name.max' => 'The name must be at most 100 characters.',
            'name.not_regex' => 'The name cannot contain line breaks or other control characters.',
            'unit.required' => 'Unit must be one of g, ml, piece.',
            'unit.enum' => 'Unit must be one of g, ml, piece.',
            'over_tolerance_bps.*' => 'Over-delivery tolerance must be between 0% and 100%.',
            'under_tolerance_bps.*' => 'Under-delivery tolerance must be between 0% and 100%.',
            'over_tolerance_cap.*' => 'The over-delivery cap must be a whole number between 0 and 1000000.',
        ];
    }
}
