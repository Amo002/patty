<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\Domain\DemoNotEmpty;
use App\Http\Controllers\Api\ApiController;
use App\Support\DemoTools;
use Illuminate\Http\JsonResponse;

/**
 * E30 to E32, local only (D-037, S13). The routes are not registered in any other environment.
 */
class DemoController extends ApiController
{
    /** E30: clear, then seed. */
    public function reset(): JsonResponse
    {
        return $this->success(['counts' => DemoTools::reset()], 'Demo data reset.');
    }

    /** E31: empty the system completely. */
    public function clear(): JsonResponse
    {
        DemoTools::clear();

        return $this->success(null, 'All data cleared.');
    }

    /**
     * E32: load the demo data into an empty system.
     *
     * @throws DemoNotEmpty
     */
    public function seed(): JsonResponse
    {
        return $this->success(['counts' => DemoTools::seed()], 'Demo data loaded.');
    }
}
