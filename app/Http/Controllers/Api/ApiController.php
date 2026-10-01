<?php

namespace App\Http\Controllers\Api;

use App\Http\Concerns\ApiResponse;
use App\Http\Controllers\Controller;

/**
 * Base for every V1 controller. It exists so no controller can answer in a
 * shape other than the envelope (D-019). Controllers validate, call one
 * service method and respond; they never catch exceptions.
 */
abstract class ApiController extends Controller
{
    use ApiResponse;
}
