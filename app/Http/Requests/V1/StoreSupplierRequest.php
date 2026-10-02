<?php

namespace App\Http\Requests\V1;

use App\Http\Requests\V1\Concerns\SupplierRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * E8: create a supplier (validation.md, Suppliers).
 */
class StoreSupplierRequest extends FormRequest
{
    use SupplierRules;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        // G6: the column is NOCASE, so plain equality is case-insensitive.
        return $this->supplierRules(['required'], Rule::unique('suppliers', 'name'));
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->supplierMessages();
    }
}
