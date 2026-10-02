<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\V1\ListIngredientsRequest;
use App\Http\Requests\V1\StoreIngredientRequest;
use App\Http\Requests\V1\UpdateIngredientRequest;
use App\Http\Resources\V1\IngredientResource;
use App\Models\Ingredient;
use App\Services\IngredientService;
use Illuminate\Http\JsonResponse;

/**
 * E2 to E5. Validates, calls one IngredientService method and responds.
 */
class IngredientController extends ApiController
{
    public function __construct(private readonly IngredientService $ingredients) {}

    public function index(ListIngredientsRequest $request): JsonResponse
    {
        return $this->paginated($this->ingredients->paginate($request->perPage()), IngredientResource::class);
    }

    public function store(StoreIngredientRequest $request): JsonResponse
    {
        $ingredient = $this->ingredients->create($request->validated());

        return $this->created(new IngredientResource($ingredient), 'Ingredient created.');
    }

    public function show(Ingredient $ingredient): JsonResponse
    {
        return $this->success(new IngredientResource($this->ingredients->withStock($ingredient)));
    }

    public function update(UpdateIngredientRequest $request, Ingredient $ingredient): JsonResponse
    {
        $updated = $this->ingredients->update($ingredient, $request->validated());

        return $this->success(new IngredientResource($updated), 'Ingredient updated.');
    }
}
