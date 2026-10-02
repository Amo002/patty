<?php

namespace App\Http\Requests\V1;

use App\Http\Requests\V1\Concerns\SupplierRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * E10: update a supplier. `sometimes` per G10; null clears email or phone.
 */
class UpdateSupplierRequest extends FormRequest
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
        return $this->supplierRules(
            ['sometimes', 'required'],
            Rule::unique('suppliers', 'name')->ignore($this->route('supplier')),
        );
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return $this->supplierMessages();
    }
}
