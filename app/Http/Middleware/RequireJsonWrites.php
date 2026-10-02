<?php

namespace App\Http\Middleware;

use App\Exceptions\Domain\UnsupportedMediaType;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireJsonWrites
{
    /**
     * S6: every API write must say Content-Type: application/json, even with no body.
     *
     * There is no login, so a forged cross-site request needs no credentials to succeed: any page
     * open in the same browser could POST to http://localhost:8000/api/v1/demo/clear. A browser
     * only sends a cross-site request without asking first when it is a "simple" one (a form post,
     * text/plain, or no Content-Type). A JSON Content-Type makes it ask first (a CORS preflight),
     * and the API never answers preflights, so the forged request is never sent.
     *
     * @throws UnsupportedMediaType rendered as 415 by bootstrap/app.php
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethodSafe() && ! $request->isJson()) {
            throw new UnsupportedMediaType;
        }

        return $next($request);
    }
}
