# Stack

## What we use

| Layer | Choice | Why |
|---|---|---|
| Language | PHP 8.4 | The author's primary language. Enums, readonly properties and match keep the domain explicit. |
| Framework | Laravel 13 | Where the author writes correct code fastest and can defend every line live. |
| Database | SQLite (file for the app, in-memory for tests) | Explicitly allowed by the brief. Zero setup on a reviewer's machine. |
| Tests | Pest 4 + pest-plugin-laravel | Tests read like a specification, which helps in the walkthrough. |
| Style | Laravel Pint (default preset) | Enforced in CI. |
| UI | Blade page shells + plain `fetch` to our own API + Alpine.js (vendored file in `public/vendor`) | "A web UI that uses the API", with no npm and no build step. |
| CSS | One hand-written stylesheet with design tokens (`public/css/app.css`) | Full control of motion and layout, nothing to compile. See [design.md](design.md). |
| Audit | spatie/laravel-activitylog ^5.1 | Field changes plus named business events, with no custom table to maintain (D-021). |
| Icons | Hugeicons free (MIT), converted once to SVG in `resources/icons` | One consistent stroke set with no npm (D-025). |
| Browser tests (opt-in) | Pest 4 browser plugin + Playwright, `composer test:browser` | End-to-end UI proof. Never needed to run the app or the core suite (D-026). |
| CI | GitHub Actions: Pint + Pest | Runs on every PR and push to `main` and `develop`. |
| API docs | Postman collection + `local` environment (`docs/postman`) | The POS endpoint is meant to be called by another system. |
| Containers | Optional `compose.yaml`, single PHP service | Runs the app without PHP installed. Never required. |

## Conventions

- **Quantities are integers** in the ingredient's unit (g, ml, piece). Never floats.
- **Domain logic lives in `app/Services`.** Controllers validate (FormRequest), call one service method, and return a Resource.
- **State rules live in enums.** `PurchaseOrderStatus` owns the transition map.
- **API controllers extend `Api\ApiController`** and answer only through the `ApiResponse` trait (`success`, `created`, `error`). One envelope everywhere (D-019).
- **Domain errors are exceptions** in `app/Exceptions/Domain`. Each declares its HTTP status (409 when state forbids, 422 when input is wrong) and a stable `code`. They are rendered in one place, `bootstrap/app.php`. Controllers never try/catch.
- **Every write that touches stock** runs inside `DB::transaction`, together with its audit entry.
- **Audit:** services call `activity()` with a dotted event name (`purchase_order.sent`). Model events are for observation only, never for changing state.
- **Logs:** `Log::channel('stock' | 'purchasing' | 'pos' | 'catalog')`, one line per business event, with a structured context array. `laravel.log` is for errors.
- **Comments** explain why, not what. Every public service method has a docblock (intent, invariants, throws). Rule-driven code cites its decision (`// D-011: ...`). No commented-out code. No TODO without a PTY key.

## What we will never use here

| Never | Reason |
|---|---|
| Floats or `decimal` cast to float for quantities | Floating-point drift is exactly "stock numbers that aren't right". |
| A mutable `quantity_on_hand` column as the source of truth | It drifts from its history. Stock is derived from movements. |
| Model events, observers or listeners that change state | They hide control flow. Every stock change is one explicit call you can point at. (Observing for the audit trail is allowed, D-021.) |
| Repositories over Eloquent | An extra layer with no second implementation to justify it. |
| Queues, Redis, scheduler, cache for stock | Must be current on every read. Triggers that would change this are listed in D-017. |
| npm or Node for the app, Vite, Tailwind build, React/Vue SPA | The reviewer runs PHP only. Node exists solely for opt-in browser tests (D-026). |
| Auth packages, spatie/permission, spatie/medialibrary, UI kits | Out of scope, see [scope.md](scope.md) and D-025. |
| Laravel Boost or other AI tooling packages in `composer.json` | Not needed by the app. AI tooling stays outside the dependency tree. |
| Any package not listed above | Must be raised and recorded in [decisions.md](decisions.md) first. |
