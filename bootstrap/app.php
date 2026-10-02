<?php

use App\Exceptions\Domain\DomainException;
use App\Http\Concerns\ApiResponse;
use App\Http\Middleware\ApiVersion;
use App\Http\Middleware\ForceJsonResponse;
use App\Http\Middleware\NoStoreCache;
use App\Http\Middleware\RequestContext;
use App\Http\Middleware\RequireJsonWrites;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Psr\Log\LogLevel;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // D-031: the version lives in the path. routes/api/v1.php only requires the per-area files (D-040).
        api: __DIR__.'/../routes/api/v1.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [ForceJsonResponse::class, RequireJsonWrites::class]);

        // Global, so the headers also cover web pages, /up and unmatched URLs.
        $middleware->append([
            RequestContext::class,
            NoStoreCache::class,
            SecurityHeaders::class,
            ApiVersion::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // A lost unique race is expected, not a fault: report it once, at warning level, with full detail (D-022
        // keeps laravel.log errors-only in meaning, so an error line there always means something broke).
        $exceptions->level(UniqueConstraintViolationException::class, LogLevel::WARNING);

        // D-019: the one place where an exception becomes an HTTP response for the API.
        // Returning null for non-API requests leaves web pages on Laravel's default rendering.
        // Reporting (laravel.log) is separate and still runs for every exception.
        $exceptions->render(function (Throwable $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            // A response someone built on purpose (abort_if with a response, a redirect) is already final.
            if ($e instanceof HttpResponseException) {
                return $e->getResponse();
            }

            $response = match (true) {
                $e instanceof DomainException => ApiResponse::error($e->getMessage(), $e->errorCode(), $e->status(), $e->errors()),
                $e instanceof ValidationException => ApiResponse::error('The given data was invalid.', 'validation_failed', 422, $e->errors()),
                // G6 / D-024: Rule::unique gives the friendly 422 and the NOCASE unique index is the backstop.
                // Reaching the index means another request won the race after this one passed validation. The input
                // was valid but the current state forbids it, so 409 (not 422). Never echo the SQL or constraint name.
                $e instanceof UniqueConstraintViolationException => ApiResponse::error(
                    'This record already exists or was just created by another request. Refresh and try again.',
                    'conflict',
                    409,
                ),
                $e instanceof AuthenticationException => ApiResponse::error('Unauthorized.', 'unauthorized', 401),
                $e instanceof ModelNotFoundException,
                $e instanceof NotFoundHttpException => ApiResponse::error('Resource not found.', 'not_found', 404),
                $e instanceof MethodNotAllowedHttpException => ApiResponse::error('Method not allowed.', 'method_not_allowed', 405),
                $e instanceof ThrottleRequestsException => ApiResponse::error('Too many requests.', 'too_many_requests', 429),
                // Any other HTTP exception (413, 419, 503...) keeps its own status. Laravel does not report these, so
                // turning them into 500 would leave a false error with nothing in the log.
                $e instanceof HttpExceptionInterface => ApiResponse::error(
                    Response::$statusTexts[$e->getStatusCode()] ?? 'Request failed.',
                    match ($e->getStatusCode()) {
                        403 => 'forbidden',
                        413 => 'payload_too_large',
                        419 => 'page_expired',
                        503 => 'service_unavailable',
                        default => 'http_error',
                    },
                    $e->getStatusCode(),
                ),
                // S8: never echo the exception text, trace or SQL. The log has it, keyed by request id.
                default => ApiResponse::error('Something went wrong on our side.', 'server_error', 500),
            };

            // Keep Retry-After, X-RateLimit-* (429) and Allow (405) so clients can act on them.
            if ($e instanceof HttpExceptionInterface) {
                $response->withHeaders($e->getHeaders());
            }

            return $response;
        });
    })->create();
