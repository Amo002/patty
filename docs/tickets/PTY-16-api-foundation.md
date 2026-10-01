# PTY-16 API foundation: envelope, errors, audit, logging, HTTP hardening

| Field | Value |
|---|---|
| Type | Story |
| Status | To Do |
| Weight | M |
| Builder | Opus 5.5 |
| Reviewer | Fable 5.1 (+ `/security-review`) |
| Branch | `PTY-16-api-foundation` |
| Release | v0.2.0 |
| Depends on | PTY-3 |

## Goal

The plumbing every feature ticket relies on, built once:
- one response envelope;
- one place where errors become HTTP;
- an audit trail;
- per-domain logs;
- the HTTP protection layer.

After this ticket a feature ticket only writes its service, its request, its resource and its tests.

## Covers

NFR-3, NFR-3a, NFR-3b, NFR-3c. D-019, D-021, D-022, D-024. Tests T15, T17, T18.

## Acceptance criteria

### Envelope and errors
- [ ] `app/Http/Concerns/ApiResponse.php` trait:
  - `success($data, string $message = '', int $status = 200, array $meta = [])`
  - `created($data, string $message)`
  - `error(string $message, string $code, int $status, array $errors = [])`
- [ ] `app/Http/Controllers/Api/ApiController.php`, the abstract base that uses the trait
- [ ] `app/Exceptions/Domain/DomainException.php`, abstract, with `status(): int` and `errorCode(): string`
- [ ] `bootstrap/app.php` `withExceptions`: for `api/*`, render the following into the envelope. This is the only place.
  - DomainException: its status and code
  - ValidationException: 422 `validation_failed` with `errors`
  - ModelNotFound / NotFoundHttp: 404 `not_found`
  - MethodNotAllowed: 405 `method_not_allowed`
  - ThrottleRequests: 429 `too_many_requests`
  - any other Throwable: 500 `server_error`, generic message, no trace when `APP_DEBUG=false`, logged to `laravel.log`
- [ ] `JsonResource::withoutWrapping()` in `AppServiceProvider`, so `data` is never `data.data`
- [ ] `routes/api.php` with the `/api/v1` prefix and a `GET /api/v1/health` endpoint returning the envelope (used by tests and Postman)

### Middleware
- [ ] `ForceJsonResponse` (api): sets `Accept: application/json`
- [ ] `NoStoreCache` (api + web): `Cache-Control: no-store, max-age=0`
- [ ] `SecurityHeaders` (api + web): `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: same-origin`, `Permissions-Policy: camera=(), microphone=(), geolocation=()`
- [ ] `RequestContext` (api + web):
  - reads or generates `X-Request-Id`;
  - reads `X-Patty-Channel` (`ui` / `pos`, default `api`);
  - calls `Log::withContext([...])`;
  - binds both for the audit helper;
  - echoes `X-Request-Id` in the response.
- [ ] Named rate limiter `pos` (120/min per IP) defined in `AppServiceProvider` (applied to `POST /sales` in PTY-9)

### Audit
- [ ] `composer require spatie/laravel-activitylog:^5.1`; publish and run its migration; config committed
- [ ] `App\Support\Audit::record(string $event, Model $subject, array $properties = [])`. It wraps `activity()` and always adds `channel`, `request_id` and `ip`, so services never repeat that.

### Logging
- [ ] `config/logging.php`:
  - `stack` uses an `errors` channel: daily `laravel.log`, level `error`, 14 days;
  - daily channels `stock`, `purchasing`, `pos` and `catalog` at level `info`, 14 days.
- [ ] `.env.example` sets `LOG_STACK=errors` (or equivalent) so a fresh clone gets the layout

## Tests required (tests/Feature/Api/FoundationTest.php)
- [ ] T15a: `GET /api/v1/health` returns `{ success: true, data: ... }` with 200
- [ ] T15b: a test-only route that throws a 409 domain exception gives 409 with `success: false` and the `code`
- [ ] T15c: a test-only route with a FormRequest gives 422 `validation_failed` with `errors.field`
- [ ] T15d: an unknown model id gives 404 JSON envelope
- [ ] T15e: a route throwing `RuntimeException` gives 500 with the generic message and no exception text (with `APP_DEBUG=false`)
- [ ] T17: responses carry `no-store`, the security headers and `X-Request-Id`. A sent `X-Request-Id` is echoed back.
- [ ] T18: `Audit::record` stores channel `pos` when the request carried `X-Patty-Channel: pos`

Test-only routes are registered inside the test file, never in `routes/`.

## Out of scope

Feature endpoints (PTY-5 onwards).
