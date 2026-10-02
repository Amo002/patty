<?php

namespace App\Http\Requests\V1;

/**
 * E29: pagination plus an optional subject filter. The two filter fields go together:
 * a type without an id (or the reverse) is a client mistake, not "show everything".
 */
class ListActivityRequest extends PaginationRequest
{
    /** Morph aliases a client may filter by (the models that write audit events). */
    public const SUBJECT_TYPES = ['purchase_order', 'ingredient', 'supplier', 'menu_item', 'sale'];

    /**
     * Stored ULIDs are lowercase but the `ulid` rule accepts uppercase, so lowercase first (G4).
     */
    protected function prepareForValidation(): void
    {
        foreach (['subject_type', 'subject_id'] as $field) {
            if (is_string($this->input($field))) {
                $this->merge([$field => strtolower($this->input($field))]);
            }
        }
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'subject_type' => ['nullable', 'required_with:subject_id', 'string', 'in:'.implode(',', self::SUBJECT_TYPES)],
            // `ulid` rejects a numeric id, so an internal key can never be used to probe the trail (D-031).
            'subject_id' => ['nullable', 'required_with:subject_type', 'string', 'ulid'],
        ];
    }
}
