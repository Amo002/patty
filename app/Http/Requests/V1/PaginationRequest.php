<?php

namespace App\Http\Requests\V1;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Shared page and per_page rules for every list endpoint (validation.md G8).
 *
 * per_page above the maximum is a 422, not a silent clamp, so a client that
 * asks for 500 rows learns about the limit instead of guessing.
 */
class PaginationRequest extends FormRequest
{
    public const DEFAULT_PER_PAGE = 25;

    public const MAX_PER_PAGE = 100;

    /**
     * No authentication in this system (brief), so every request is allowed.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Subclasses add their own filters with array_merge(parent::rules(), [...]).
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:'.self::MAX_PER_PAGE],
        ];
    }

    /**
     * The validated page size, defaulted, ready for ->paginate().
     */
    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? self::DEFAULT_PER_PAGE);
    }
}
