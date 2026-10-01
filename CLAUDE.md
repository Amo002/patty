# Patty: instructions for AI agents

Inventory and purchasing service for a single-branch burger restaurant. Laravel 13, SQLite, Pest, Blade plus vanilla `fetch` and Alpine (no build step).

## Read before working

1. `docs/progress.md`: where work stopped, what is next
2. `docs/architecture.md`: the big picture (layers, flows, protection)
3. The ticket you are working on: `docs/tickets/PTY-N-*.md`
4. `docs/requirements.md`, `docs/data.md`, `docs/scope.md`
5. `docs/workflow.md`, `docs/agents.md`, `docs/tools.md`: how we work and what you may not touch

The full index is in `docs/README.md`.

## Hard rules

- Stock is changed **only** through `App\Services\StockLedger`. `stock_movements` is append-only. On-hand and outstanding quantities are derived, never stored.
- Purchase order status changes **only** through the transition map in `App\Enums\PurchaseOrderStatus`.
- Quantities are integers in the ingredient's unit. Never floats.
- Domain logic lives in `app/Services`. Controllers validate with a FormRequest, call one service method, and return a Resource.
- Every write that touches stock runs in `DB::transaction`.
- No new dependency, table or endpoint that is not in a ticket. Raise it first.
- No emoji in code, UI, docs, commits or PRs.
- Commits: `PTY-N: Imperative sentence`, authored by the repo owner, **no AI co-author trailers**. Never push to `main` or `develop`. Never force-push.
- Tests must fail if the logic they cover is removed. No filler.
- Append to `docs/AI_LOG.md` after each meaningful prompt: what was asked, what came back, what was wrong, what changed.

## Commands

```sh
composer setup              # first-time install
php artisan serve           # run
php artisan test            # tests
vendor/bin/pint             # fix style
php artisan migrate:fresh --seed   # reset local data
```
