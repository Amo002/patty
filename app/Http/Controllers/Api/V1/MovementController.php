<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\V1\ListMovementsRequest;
use App\Http\Resources\V1\MovementResource;
use App\Models\Ingredient;
use App\Services\StockQuery;
use Illuminate\Http\JsonResponse;

/**
 * E6.
 */
class MovementController extends ApiController
{
    public function __invoke(ListMovementsRequest $request, Ingredient $ingredient, StockQuery $query): JsonResponse
    {
        return $this->paginated($query->movements($ingredient, $request->perPage()), MovementResource::class);
    }
}
