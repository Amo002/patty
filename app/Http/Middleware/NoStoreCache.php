<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class NoStoreCache
{
    /**
     * Forbid caching of every response: stock must never be shown from a stale
     * copy ("without having to guess whether it is up to date").
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $response->headers->set('Cache-Control', 'no-store, max-age=0');

        return $response;
    }
}
