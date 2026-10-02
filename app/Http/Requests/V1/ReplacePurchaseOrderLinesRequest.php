<?php

namespace App\Http\Requests\V1;

use App\Http\Requests\V1\Concerns\PurchaseOrderLineRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * E19: replace the lines of a draft purchase order. Whether the order is
 * still a draft is a status rule, so the service decides it, not this class.
 */
class ReplacePurchaseOrderLinesRequest extends FormRequest
{
    use PurchaseOrderLineRules;

    /**
     * No authentication in this system (brief), so every request is allowed.
     */
    public function authorize(): bool
    {
        return true;
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
        return $this->lineRules();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return $this->lineAttributes();
    }

    protected function passedValidation(): void
    {
        $this->resolveLines();
    }
}
