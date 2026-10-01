# PTY-16 API foundation: envelope, errors, audit, logging, HTTP hardening

| Field | Value |
|---|---|
| Type | Story |
| Phase | 3a Build backend |
| Status | To Do |
| Weight | M |
| Builder | Sonnet 5.5 |
| Reviewer | Opus 5.5 (+ `/security-review`) |
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
- [ ] `app/Http/Controllers/Api/ApiController.php`, the abstract base that uses the trait (V1 controllers extend it)
- [ ] `app/Exceptions/Domain/DomainException.php`, abstract, with `status(): int` and `errorCode(): string`
- [ ] `bootstrap/app.php` `withExceptions`: for `api/*`, render the following into the envelope. This is the only place.
  - DomainException: its status and code
  - ValidationException: 422 `validation_failed` with `errors`
  - ModelNotFound / NotFoundHttp: 404 `not_found`
  - MethodNotAllowed: 405 `method_not_allowed`
  - ThrottleRequests: 429 `too_many_requests`
  - any other Throwable: 500 `server_error`, generic message, no trace when `APP_DEBUG=false`, logged to `laravel.log`
- [ ] `JsonResource::withoutWrapping()` in `AppServiceProvider`, so `data` is never `data.data`
- [ ] Versioning (D-031):
  - `routes/api/v1.php` mounted at `/api/v1` from `bootstrap/app.php`. It only `require`s every file in `routes/api/v1/` (catalog.php, purchasing.php, sales.php, visibility.php, demo.php, created empty here), so parallel tickets never edit the same routes file (D-040);
  - controllers in `App\Http\Controllers\Api\V1`, requests in `App\Http\Requests\V1`, resources in `App\Http\Resources\V1`;
  - `ApiVersion` middleware adds `X-API-Version: 1`.
- [ ] `GET /api/v1/health` (E1) returns the envelope (used by tests and Postman)
- [ ] Pagination helper: `ApiResponse::paginated(LengthAwarePaginator, ResourceClass)` returns `data` plus `meta.pagination = { page, per_page, total, last_page, has_more }`. A shared `PaginationRequest` rule set (validation.md G8: `per_page` max 100, else 422).
- [ ] `PosKey` middleware (D-028): if `config('patty.pos_api_key')` is set, require `X-POS-Key` (`hash_equals`), else 401 `unauthorized`. Applied to E25 in PTY-9. `.env.example` has `POS_API_KEY=` (empty).
- [ ] 401 rendered by `withExceptions` (`AuthenticationException` or a domain `Unauthorized`) as 401 `unauthorized`

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
- [ ] Test helper `assertNoIntegerIds()` (in `tests/Pest.php`, a custom expectation): walks the JSON recursively and fails on any key `id` or `*_id` whose value is an integer. Used by every feature test from PTY-5 onwards (T25).
- [ ] `X-API-Version: 1` is present on every API response
- [ ] `per_page=101` gives 422. `meta.pagination` is correct on the last page (`has_more: false`).
- [ ] PosKey: no key configured, passes; key configured with a missing or wrong header, 401; correct header, passes (T22)

### CI additions (this ticket)
- [ ] `composer audit` step (S14)
- [ ] "Journal" step: on pull requests, fails if the diff touches `app/`, `routes/`, `database/` or `resources/` but changes neither `docs/progress.md` nor any `docs/tickets/PTY-*.md` file (D-033, amended by D-040 so parallel PRs do not all fight over progress.md). Uses `git diff --name-only origin/${{ github.base_ref }}...HEAD`.

Test-only routes are registered inside the test file, never in `routes/`.

## Out of scope

Feature endpoints (PTY-5 onwards).

## Contract

[api.md](../api.md): conventions, status and error codes, versioning policy, E1. [validation.md](../validation.md): G1, G2, G8.

## Flows

Cross-cutting for F1 to F20: envelope, errors, request id, channel, audit and logs.

## Done means

1. `curl -i http://127.0.0.1:8000/api/v1/health` shows the envelope plus the `X-Request-Id`, `X-API-Version`, `Cache-Control: no-store` and security headers.
2. `curl -i http://127.0.0.1:8000/api/v1/nope` gives the 404 JSON envelope.
3. `storage/logs/` has `laravel.log` only for errors. The domain channel files appear once features write to them.


## Builder notes

### What was built, in order
1. spatie/laravel-activitylog ^5.1 with its published migration and config, `App\Support\Audit::record()` (stamps `channel`, `request_id`, `ip`), and the middleware: `RequestContext`, `NoStoreCache`, `SecurityHeaders`, `ApiVersion`, `ForceJsonResponse`, `PosKey`. POS key lives at `services.pos.api_key` (env `POS_API_KEY`), not in `config/patty.php`.
2. The envelope: `ApiResponse` trait (`success`, `created`, `paginated`, static `error`), `ApiController`, abstract `DomainException` (`status()`, `errorCode()`, optional `errors()`), `PaginationRequest`, `HealthController` (E1), `routes/api/v1.php` requiring `routes/api/v1/*.php`, the `pos` rate limiter and `JsonResource::withoutWrapping()`.
3. Logging: `errors` daily channel (level error, 14 days) as the stack default, plus daily `stock`, `purchasing`, `pos`, `catalog` at info. `.env.example` sets `LOG_STACK=errors` and `POS_API_KEY=`.
4. Tests (`tests/Feature/Api/FoundationTest.php`, 17 tests) and the `assertNoIntegerIds` expectation in `tests/Pest.php`.
5. CI: `composer audit`, and the Journal step on pull requests (job name unchanged, checkout uses `fetch-depth: 0`).

### How an exception becomes an envelope
- Any exception thrown during an `api/*` request (controller, FormRequest, middleware, model lookup, routing) is caught by Laravel's handler. Laravel first reports it (writes to the `errors` log channel), then calls the single `$exceptions->render(...)` closure in `bootstrap/app.php`.
- The closure returns null for non-API paths (web keeps default rendering). For API paths one `match` picks: `DomainException` uses its own `status()` and `errorCode()`; `ValidationException` is 422 `validation_failed` with field `errors`; `AuthenticationException` (thrown by `PosKey`) is 401 `unauthorized`; `ModelNotFoundException` or `NotFoundHttpException` is 404; `MethodNotAllowedHttpException` is 405; `ThrottleRequestsException` is 429; anything else is 500 `server_error` with a fixed message.
- Every branch builds its body through `ApiResponse::error()`, so the failure shape exists in exactly one function. Controllers never catch.
- Global middleware still wraps the response, so error responses also get the security headers, `no-store`, `X-Request-Id` and `X-API-Version`.

### Deviations and notes
- `ApiVersion` is registered globally with an `api/*` path guard instead of only in the `api` group, because an unmatched `/api/v1/nope` never enters the group but must still carry `X-API-Version`. `ForceJsonResponse` is in the api group as specified.
- The 401 path uses Laravel's `AuthenticationException` (the ticket allowed this or a domain `Unauthorized`), so no extra exception class was added.
- `DomainException` has an optional `errors()` method (default empty) so a rule such as `over_delivery` can name its line in `errors` (api.md says it does).
- Incoming `X-Request-Id` is only echoed if it matches `[A-Za-z0-9._-]{1,64}`, otherwise a UUID is generated (log injection, S16). An unknown `X-Patty-Channel` falls back to `api`.
- Daily channels use the `days` key (the one Laravel reads); the stock `daily` channel's `max_files` key is ignored by Laravel and was left untouched.
- Extra tests beyond the ticket: 405, 429 on the `pos` limiter, web pages carry the headers, unsafe request id, unknown channel, and a test proving `assertNoIntegerIds` itself fails on integer ids.
- Mutation check done on T15e: making the 500 message echo the exception text fails the test.
- `docs/AI_LOG.md` was not touched (the orchestrator owns it); known AI-relevant fix: the first `composer require` ran with a Windows ReadOnly directory attribute problem and pinned the version as `5.1`, corrected to `^5.1`.
