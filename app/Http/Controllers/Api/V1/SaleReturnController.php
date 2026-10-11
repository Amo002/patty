<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\V1\StoreSaleReturnRequest;
use App\Http\Resources\V1\SaleReturnResource;
use App\Models\Sale;
use App\Services\SaleReturnService;
use Illuminate\Http\JsonResponse;

/**
 * E33. Validates, calls one SaleReturnService method and responds.
 */
class SaleReturnController extends ApiController
{
    public function __construct(private readonly SaleReturnService $returns) {}

    public function store(StoreSaleReturnRequest $request, Sale $sale): JsonResponse
    {
        $result = $this->returns->record(
            $sale,
            $request->lines(),
            $request->boolean('restock'),
            $request->validated('reason'),
            $request->validated('pos_reference'),
        );

        $resource = new SaleReturnResource($result->return, $result->replayed);

        // 200 for a replay: nothing was created, the POS just gets the original answer again.
        return $result->replayed
            ? $this->success($resource, 'Refund already recorded. No stock moved.')
            : $this->created($resource, 'Refund recorded.');
    }
}
