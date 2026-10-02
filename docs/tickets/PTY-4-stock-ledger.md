# PTY-4 Stock ledger

| Field | Value |
|---|---|
| Type | Story |
| Phase | 3a Build backend |
| Status | Done |
| Weight | L |
| Builder | Sonnet 5.5 |
| Reviewer | Opus 5.5 |
| Branch | `PTY-4-stock-ledger` |
| Release | v0.2.0 |
| Depends on | PTY-3, PTY-16 |

## Goal

The single component through which stock changes, and the single definition of on-hand.

## Covers

data.md invariants 1 and 6. D-004. NFR-1, NFR-2.

## Acceptance criteria

- [x] `App\Services\StockLedger` with:
  - `record(Ingredient, int $delta, MovementReason, Model $reference, ?CarbonInterface $occurredAt): StockMovement`. Rejects `$delta === 0`.
  - `onHand(Ingredient): int`, which is `SUM(quantity_delta)`.
  - `onHandForAll(): Collection` keyed by ingredient id, one grouped query (no N+1).
- [x] No other class creates `StockMovement` rows (the reviewer checks with a search)
- [x] `StockMovement` model blocks update and delete: overriding `save` on existing rows and `delete` to throw `ImmutableMovement`. This is the application layer; the database triggers from PTY-3 are the layer below it.
- [x] `record()` writes one `info` line to the `stock` log channel, with ingredient, delta, reason and reference
- [x] `record()` writes a `warning` to `stock` when the resulting on-hand is below zero
- [x] Docblocks on `record`, `onHand`, `onHandForAll`, per D-023
- [x] `Ingredient::$appends` is not used for stock. On-hand comes from the ledger explicitly.
- [x] Morph map (deferred from PTY-3 review): `Relation::enforceMorphMap` in `AppServiceProvider::boot()` for ingredient, supplier, menu_item, recipe_line, purchase_order, purchase_order_line, delivery, delivery_line, sale, stock_movement. `stock_movements.reference_type` and `activity_log.subject_type` store the short name (`delivery_line`), never a class name.

## Worked example (Mohamad checks by hand)

Movements for beef: +1000, -150, -150, +400, -300. On-hand = 800.

## Tests required

- [x] On-hand is the sum of signed deltas (the example above)
- [x] An ingredient with no movements has on-hand 0
- [x] `onHandForAll` matches `onHand` for each ingredient
- [x] Updating or deleting a movement throws
- [x] A zero delta is rejected
- [x] A movement that takes on-hand below zero logs a warning to the `stock` channel
- [x] A recorded movement stores the short morph name in `reference_type`

## Out of scope

Deliveries and sales (they call the ledger in PTY-8 and PTY-9).

## Contract

No endpoints. Read by E2, E6, E27 and E28 later.

## Flows

The `StockLedger::record` step in F8, F9 and F13 to F15.

## Test data

- Ingredient beef (g) from the factory.
- Movements written through `StockLedger::record` with stand-in references (a delivery line and a sale from factories): +1000, -150, -150, +400, -300.
- Expected on-hand 800.
- For the negative warning: cheese 30, then -40, which logs a warning with on-hand -10.

## Done means

1. `php artisan test --filter=StockLedger` is green.
2. In tinker, record +100 and -250 on an ingredient. `onHand()` returns -150, and `storage/logs/stock-*.log` shows a warning.
3. Try `$movement->update([...])` in tinker. It throws `ImmutableMovement`.

## Builder notes

### What was built, in order
1. `Relation::enforceMorphMap` for 10 models in `AppServiceProvider`; the PTY-16 audit tests register their test-only widget models in `beforeEach` (merged) and assert the short names.
2. `ImmutableMovement` (LogicException) and the `StockMovement` guards: `save()` throws when the row exists, `delete()` always throws.
3. `StockLedger` with `record`, `onHand`, `onHandForAll`.
4. `tests/Feature/Ledger/StockLedgerTest.php` (12 tests).

### Decisions
- The log context carries the reference ULID when the model has one and falls back to the integer key otherwise (delivery lines are not addressable, D-031). Logs are internal, so this does not leak ids to clients.
- `onHandForAll` omits ingredients with no movements; callers use `->get($id, 0)`. That keeps it one grouped query with no join to ingredients.
- `onHand` is read after the insert, so the below-zero warning reflects the movement just written. Callers hold the transaction (CLAUDE.md).
- Exactly zero on-hand does not warn: only negative is flagged (D-010).

### Deviations and things to know
- `StockMovementFactory` (database/**, not mine) sets `reference_type` to `Sale::class`. It still inserts, but resolving `->reference` on a factory movement fails under the enforced map. Nothing does that yet; change it to the `sale` alias when next touched.
- Optional `request_id` on stock log lines is not duplicated here: PTY-16's foundation test already proves it for the stock channel via `Log::shareContext`.
### Review fixes
- Log lines now go through `DB::afterCommit`. Laravel's test transaction manager (used by RefreshDatabase) treats the wrapping test transaction as the root, so callbacks run on commit of a nested `DB::transaction` and immediately outside one. No special handling was needed; a mutation check (logging inline) makes the rollback test fail.
- `StockMovementFactory` uses the `sale` alias; a test proves `->reference` resolves.

## Reviewer findings

Verdict: approve after #1 and #2. Pint and the full suite (86 tests) are green. Each important behaviour was mutation-checked (10 mutations), and every mutation was killed.

| # | Severity | File:line | Finding | Resolution |
|---|---|---|---|---|
| 1 | Medium | database/factories/StockMovementFactory.php:27 | `reference_type => Sale::class` stores a class name. Under the enforced morph map (added by this ticket), `->reference` on a factory movement throws `ClassMorphViolationException` (confirmed with a probe). This ticket caused it, so fix it here: use `'sale'` (or `(new Sale)->getMorphClass()`). | fixed in 78319fa |
| 2 | Medium | app/Services/StockLedger.php:71,77 | Logs are written inside the caller's transaction. If the transaction rolls back (for example, line 3 of a sale fails), `stock.log` still says "Stock movement recorded" and possibly "Stock is below zero" for a movement that does not exist (probe: 0 movements, 2 log lines). Compute `$onHand` inside the transaction, then emit both lines through `DB::afterCommit(...)`. This runs immediately when there is no transaction, and it works under RefreshDatabase. | fixed in a9339f1 |
| 3 | Low | app/Models/StockMovement.php:46 | `$movement->increment()` and `->decrement()` bypass the `save()` override: Eloquent issues the UPDATE directly, and only the SQLite trigger stops it, with a `QueryException` rather than `ImmutableMovement`. The in-memory attribute has already changed when it throws. The same applies to `StockMovement::query()->update()/delete()`. The trigger is the correct backstop, but the docblock should name these paths, so the two layers are explicit. | docblock in 381f304 |
| 4 | Low | app/Services/StockLedger.php:74 | Warning semantics: the warning fires on every movement while stock stays negative, including a positive delivery that leaves it at -5. This is defensible ("still negative"), but state it in the docblock. Concurrency: inside the caller's transaction, SQLite holds the write lock after the insert, so the re-read is exact. Outside a transaction, a concurrent writer could be counted. On MySQL or Postgres, the snapshot could miss a concurrent sale. These are warnings only, and stock correctness is unaffected. | docblock in 7abaf3c |
| 5 | Info | tests/Feature/Api/FoundationTest.php:181 | Correct: the merge is right (`$map + static::$morphMap`), and the assertions moved to short names. The static map is not reset between tests, so `foundation_*` aliases persist for the rest of the process. This is harmless because the names are unique, so no change is needed. | info, no change |
| 6 | Info | app/Providers/AppServiceProvider.php:42 | `DocumentSequence` is unmapped. That is fine today, because it is never a morph target or audit subject. If it ever becomes one, the enforced map throws loudly, which is the intended behaviour. | info, no change |
| 7 | Info | app/Services/StockLedger.php:68 | Integer fallback for DeliveryLine in the log context is acceptable: logs are internal (D-022) and never reach a client. Not reading `->ulid` on a model without that column would break if `Model::preventAccessingMissingAttributes()` is ever turned on for fetched (not just-created) rows. | info, no change |
