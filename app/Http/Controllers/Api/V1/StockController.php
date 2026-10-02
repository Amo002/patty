<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\V1\ListStockRequest;
use App\Http\Resources\V1\StockRowResource;
use App\Services\StockQuery;
use Illuminate\Http\JsonResponse;

/**
 * E27. Validates, calls one StockQuery method and responds.
 */
class StockController extends ApiController
{
    public function __construct(private readonly StockQuery $query) {}

    public function index(ListStockRequest $request): JsonResponse
    {
        return $this->paginated(
            $this->query->stock($request->perPage()),
            StockRowResource::class,
            meta: ['generated_at' => now()->utc()->toIso8601ZuluString()],
        );
    }
}
