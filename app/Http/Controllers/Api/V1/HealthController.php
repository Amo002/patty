<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use Illuminate\Http\JsonResponse;

class HealthController extends ApiController
{
    /**
     * E1: liveness plus a live demonstration of the envelope, used by tests and Postman.
     */
    public function __invoke(): JsonResponse
    {
        return $this->success(
            ['status' => 'ok', 'app' => config('app.name')],
            'Service is up.',
            meta: ['generated_at' => now()->utc()->format('Y-m-d\TH:i:s\Z')],
        );
    }
}
