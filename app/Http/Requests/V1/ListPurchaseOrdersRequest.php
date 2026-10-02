<?php

namespace App\Http\Requests\V1;

use App\Enums\PurchaseOrderStatus;

/**
 * E16: page, per_page and the status filter. `open` is not a status; it is
 * the filter for "still waiting on the supplier" (sent or received).
 */
class ListPurchaseOrdersRequest extends PaginationRequest
{
    public const OPEN = 'open';

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        $allowed = [...array_map(fn (PurchaseOrderStatus $status) => $status->value, PurchaseOrderStatus::cases()), self::OPEN];

        return [
            ...parent::rules(),
            'status' => ['nullable', 'string', 'in:'.implode(',', $allowed)],
        ];
    }
}
