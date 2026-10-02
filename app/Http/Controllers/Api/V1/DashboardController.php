<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Services\StockQuery;
use Illuminate\Http\JsonResponse;

/**
 * E28.
 */
class DashboardController extends ApiController
{
    public function __invoke(StockQuery $query): JsonResponse
    {
        return $this->success($query->dashboard(), meta: ['generated_at' => now()->utc()->toIso8601ZuluString()]);
    }
}
