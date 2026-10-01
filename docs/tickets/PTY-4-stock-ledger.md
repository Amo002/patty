# PTY-4 Stock ledger

| Field | Value |
|---|---|
| Type | Story |
| Phase | 3a Build backend |
| Status | In Review |
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