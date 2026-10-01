<?php

namespace App\Http\Requests\V1;

use App\Http\Requests\V1\Concerns\PurchaseOrderLineRules;
use App\Models\Supplier;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * E17: create a draft purchase order (validation.md, Purchase orders).
 */
class StorePurchaseOrderRequest extends FormRequest
{
    use PurchaseOrderLineRules;

    private ?Supplier $resolvedSupplier = null;

    /**
     * No authentication in this system (brief), so every request is allowed.
     */
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('supplier_id'))) {
            $this->merge(['supplier_id' => strtolower($this->input('supplier_id'))]);
        }

        $this->lowercaseLineIngredientIds();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'string', 'ulid', Rule::exists('suppliers', 'ulid')],
            ...$this->lineRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return ['supplier_id' => 'supplier', ...$this->lineAttributes()];
    }

    protected function passedValidation(): void
    {
        $this->resolvedSupplier = Supplier::query()->where('ulid', $this->validated('supplier_id'))->firstOrFail();
        $this->resolveLines();
    }

    public function supplier(): Supplier
    {
        return $this->resolvedSupplier;
    }
}
