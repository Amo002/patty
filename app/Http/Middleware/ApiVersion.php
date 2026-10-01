<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiVersion
{
    /**
     * D-031: announce the contract version on every API response.
     *
     * Registered globally and guarded by path, not only in the api group, so an
     * unmatched /api/... URL (which never enters the group) still carries it.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->is('api/*')) {
            $response->headers->set('X-API-Version', '1');
        }

        return $response;
    }
}
