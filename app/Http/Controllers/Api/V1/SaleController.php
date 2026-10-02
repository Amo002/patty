<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\V1\ListSalesRequest;
use App\Http\Requests\V1\StoreSaleRequest;
use App\Http\Resources\V1\SaleResource;
use App\Services\SaleService;
use Illuminate\Http\JsonResponse;

/**
 * E25 and E26. Validates, calls one SaleService method and responds.
 */
class SaleController extends ApiController
{
    public function __construct(private readonly SaleService $sales) {}

    public function index(ListSalesRequest $request): JsonResponse
    {
        $page = $this->sales->paginate($request->perPage());
        $this->sales->withDeductions($page->items());

        return $this->paginated($page, SaleResource::class);
    }

    public function store(StoreSaleRequest $request): JsonResponse
    {
        $result = $this->sales->record(
            $request->menuItem(),
            (int) $request->validated('quantity'),
            $request->validated('pos_reference'),
            $request->soldAt(),
        );

        $this->sales->withDeductions([$result->sale]);
        $resource = new SaleResource($result->sale, $result->replayed);

        // 200 for a replay: nothing was created, the POS just gets the original answer again.
        return $result->replayed
            ? $this->success($resource, 'Sale already recorded. No stock moved.')
            : $this->created($resource, 'Sale recorded.');
    }
}
