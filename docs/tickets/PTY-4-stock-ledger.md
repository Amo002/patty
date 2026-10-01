# PTY-4 Stock ledger

| Field | Value |
|---|---|
| Type | Story |
| Phase | 3a Build backend |
| Status | To Do |
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

- [ ] `App\Services\StockLedger` with:
  - `record(Ingredient, int $delta, MovementReason, Model $reference, ?CarbonInterface $occurredAt): StockMovement`. Rejects `$delta === 0`.
  - `onHand(Ingredient): int`, which is `SUM(quantity_delta)`.
  - `onHandForAll(): Collection` keyed by ingredient id, one grouped query (no N+1).
- [ ] No other class creates `StockMovement` rows (the reviewer checks with a search)
- [ ] `StockMovement` model blocks update and delete: overriding `save` on existing rows and `delete` to throw `ImmutableMovement`. This is the application layer; the database triggers from PTY-3 are the layer below it.
- [ ] `record()` writes one `info` line to the `stock` log channel, with ingredient, delta, reason and reference
- [ ] `record()` writes a `warning` to `stock` when the resulting on-hand is below zero
- [ ] Docblocks on `record`, `onHand`, `onHandForAll`, per D-023
- [ ] `Ingredient::$appends` is not used for stock. On-hand comes from the ledger explicitly.

## Worked example (Mohamad checks by hand)

Movements for beef: +1000, -150, -150, +400, -300. On-hand = 800.

## Tests required

- [ ] On-hand is the sum of signed deltas (the example above)
- [ ] An ingredient with no movements has on-hand 0
- [ ] `onHandForAll` matches `onHand` for each ingredient
- [ ] Updating or deleting a movement throws
- [ ] A zero delta is rejected
- [ ] A movement that takes on-hand below zero logs a warning to the `stock` channel

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
