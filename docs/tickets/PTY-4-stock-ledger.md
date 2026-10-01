# PTY-4 Stock ledger

| Field | Value |
|---|---|
| Type | Story |
| Status | To Do |
| Weight | L |
| Builder | Opus 5.5 |
| Reviewer | Fable 5.1 |
| Branch | `PTY-4-stock-ledger` |
| Release | v0.2.0 |
| Depends on | PTY-3 |

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
- [ ] `StockMovement` model blocks update and delete: overriding `save` on existing rows and `delete` to throw `ImmutableMovement`
- [ ] `Ingredient::$appends` is not used for stock. On-hand comes from the ledger explicitly.

## Worked example (Mohamad checks by hand)

Movements for beef: +1000, -150, -150, +400, -300. On-hand = 800.

## Tests required

- [ ] On-hand is the sum of signed deltas (the example above)
- [ ] An ingredient with no movements has on-hand 0
- [ ] `onHandForAll` matches `onHand` for each ingredient
- [ ] Updating or deleting a movement throws
- [ ] A zero delta is rejected

## Out of scope

Deliveries and sales (they call the ledger in PTY-8 and PTY-9).
