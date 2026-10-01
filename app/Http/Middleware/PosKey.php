<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PosKey
{
    /**
     * D-028: optional shared secret for the POS integration. Open when no key is
     * configured, so reviewers and Postman need no setup.
     *
     * @throws AuthenticationException rendered as 401 `unauthorized` by bootstrap/app.php
     */
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.pos.api_key');

        if ($expected !== '' && ! hash_equals($expected, (string) $request->header('X-POS-Key', ''))) {
            throw new AuthenticationException('A valid X-POS-Key header is required.');
        }

        return $next($request);
    }
}
