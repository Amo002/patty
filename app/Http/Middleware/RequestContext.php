<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class RequestContext
{
    public const REQUEST_ID_BINDING = 'patty.request_id';

    public const CHANNEL_BINDING = 'patty.channel';

    private const CHANNELS = ['ui', 'pos', 'api'];

    /**
     * D-021 / D-022: give every request an id and a channel, attach both to
     * every log line, and bind them so Audit::record can stamp the trail.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $this->requestId($request);
        $channel = $this->channel($request);

        app()->instance(self::REQUEST_ID_BINDING, $requestId);
        app()->instance(self::CHANNEL_BINDING, $channel);

        Log::withContext(['request_id' => $requestId, 'channel' => $channel]);

        $response = $next($request);
        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }

    /**
     * Echo a client-supplied id so a caller can correlate its own logs, but only
     * if it is a short safe token: a free-form header must not reach log lines (S16).
     */
    private function requestId(Request $request): string
    {
        $sent = (string) $request->header('X-Request-Id', '');

        return preg_match('/^[A-Za-z0-9._-]{1,64}$/', $sent) === 1 ? $sent : (string) Str::uuid();
    }

    /**
     * Unknown channel values fall back to `api` so the audit trail only ever holds the three known values.
     */
    private function channel(Request $request): string
    {
        $sent = strtolower((string) $request->header('X-Patty-Channel', ''));

        return in_array($sent, self::CHANNELS, true) ? $sent : 'api';
    }
}
