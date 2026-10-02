<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\V1\ListActivityRequest;
use App\Http\Resources\V1\ActivityResource;
use App\Services\StockQuery;
use Illuminate\Http\JsonResponse;

/**
 * E29.
 */
class ActivityController extends ApiController
{
    public function __invoke(ListActivityRequest $request, StockQuery $query): JsonResponse
    {
        $page = $query->activity($request->validated('subject_type'), $request->validated('subject_id'), $request->perPage());

        return $this->paginated($page, ActivityResource::class);
    }
}
