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
| CI | GitHub Actions: Pint + Pest | Runs on every PR and push to `main` and `develop`. |
| API docs | Postman collection + `local` environment (`docs/postman`) | The POS endpoint is meant to be called by another system. |
| Containers | Optional `compose.yaml`, single PHP service | Runs the app without PHP installed. Never required. |

## Conventions

- **Quantities are integers** in the ingredient's unit (g, ml, piece). Never floats.
- **Domain logic lives in `app/Services`.** Controllers validate (FormRequest), call one service method, and return a Resource.
- **State rules live in enums.** `PurchaseOrderStatus` owns the transition map.
- **Domain errors are exceptions** in `app/Exceptions/Domain`, rendered as 422 JSON in `bootstrap/app.php`.
- **Every write that touches stock** runs inside `DB::transaction`.

## What we will never use here

| Never | Reason |
|---|---|
| Floats or `decimal` cast to float for quantities | Floating-point drift is exactly "stock numbers that aren't right". |
| A mutable `quantity_on_hand` column as the source of truth | It drifts from its history. Stock is derived from movements. |
| Model events, observers or listeners for domain side effects | They hide control flow. Every stock change is one explicit call you can point at. |
| Repositories over Eloquent | An extra layer with no second implementation to justify it. |
| Queues, Redis, cache for stock | Must be current on every read. |
| npm, Vite, Tailwind build, React/Vue SPA | The reviewer runs PHP only. |
| Auth packages, spatie/permission, spatie/medialibrary | Out of scope, see [scope.md](scope.md). |
| Laravel Boost or other AI tooling packages in `composer.json` | Not needed by the app. AI tooling stays outside the dependency tree. |
| Any package not listed above | Must be raised and recorded in [decisions.md](decisions.md) first. |
