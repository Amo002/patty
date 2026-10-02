# PTY-9 POS sales endpoint

| Field | Value |
|---|---|
| Type | Story |
| Phase | 3a Build backend |
| Status | To Do |
| Weight | L |
| Builder | Sonnet 5.5 |
| Reviewer | Opus 5.5 |
| Branch | `PTY-9-pos-sales` |
| Release | v0.3.0 |
| Depends on | PTY-6, Q-001, Q-005 |

## Goal

The POS reports a sale and stock falls by exactly what the recipe says, even when that goes below zero, and never twice for the same sale.

## Covers

FR-5 AC1 to AC6. data.md invariants 3 and 6. T1, T7, T10.

## Acceptance criteria

- [ ] `POST /api/v1/sales` `{ menu_item_id, quantity, pos_reference?, sold_at? }`
- [ ] `App\Services\SaleService::record(...)`, in one transaction:
  1. If `pos_reference` exists already, return the existing sale (HTTP 200, `replayed: true`)
  2. Menu item must have recipe lines, else `MenuItemNotSellable` (422 `menu_item_not_sellable`)
  3. Create the sale
  4. For each recipe line: `StockLedger::record(-(line.quantity * sale.quantity), Sale, sale)`
- [ ] New sale returns 201 with the deductions it made and any ingredients now negative
- [ ] Negative stock is allowed (Q-001). StockLedger logs the warning (PTY-4).
- [ ] Race guard: if the insert hits the unique `pos_reference` index (two identical requests at once), catch the unique violation, load the existing sale and answer as a replay
- [ ] Route uses `throttle:pos` (limiter defined in PTY-16)
- [ ] Audit `sale.recorded` or `sale.replayed`, plus a `pos` log line (replays at `notice`)
- [ ] `GET /sales` (recent, for the UI)

## Worked example (Mohamad checks by hand)

Classic Burger = beef 150, bun 1, cheese 20. Stock before: beef 1000, bun 10, cheese 30.
Sell 2: beef 1000 - 300 = 700, bun 10 - 2 = 8, cheese 30 - 40 = **-10** (accepted, flagged negative).

## Tests required

- [ ] T1: 2 Classic Burgers give beef -300, bun -2, cheese -40, as three movements referencing the sale
- [ ] T7: the example above. Cheese ends at -10 and the response lists cheese as negative.
- [ ] T10: the same `pos_reference` twice gives one sale and one set of movements. The second response is 200 with `replayed: true`.
- [ ] Menu item without a recipe gives 422. Quantity 0 gives 422. Unknown menu item gives 422.
- [ ] A recipe change after a sale does not alter that sale's movements
- [ ] Beyond the rate limit, `POST /sales` answers 429 `too_many_requests` in the envelope (limit lowered in the test via config)

## Contract

[api.md](../api.md) E25, E26. [validation.md](../validation.md) Sales.
- `quantity` is 1 to 1,000. `sold_at` is at most 5 minutes in the future.
- `PosKey` middleware (PTY-16) and `throttle:pos` on E25.
- The sale number comes from `DocumentNumber::next('SALE')`.

## Flows

F13, F14, F15, F16.

## Additional acceptance criteria

- [ ] **Idempotency conflict (D-029):** the same `pos_reference` with a different `menu_item_id` or `quantity` gives 409 `idempotency_conflict`. Nothing is written, and a `pos` warning is logged. The unique-violation race path applies the same comparison.
- [ ] POS key behaviour, in tests: open when unset; 401 when set and the header is wrong.

## Test data

- Classic Burger as in the worked example; stock seeded through `StockLedger` (beef 1000, bun 10, cheese 30).
- References `till-1:0001` (new) and `till-1:0001` again (replay).
- `till-1:0001` with quantity 3 (conflict).

## Additional tests

- [ ] T23: conflict gives 409, the sale count is unchanged, and stock is unchanged.
- [ ] T22: `config(['services.pos.api_key' => 'secret'])`. No header gives 401; `X-POS-Key: secret` gives 201.

## Done means

1. POST a sale of 2 Classic Burgers. 201 with three deductions and `number: SALE-2026-000001`.
2. POST the same body again. 200 with `replayed: true`, and stock unchanged.
3. POST the same reference with quantity 3. 409 `idempotency_conflict`.

## Builder notes

### What was built, in order
1. `IdempotencyConflict` (409) and `MenuItemNotSellable` (422) domain exceptions.
2. `SaleService::record()` and a `SaleResult` value object. Order: look up `pos_reference` (replay or conflict), then one `DB::transaction` holding the recipe check, SALE number, sale row, one ledger movement per recipe line and the `sale.recorded` audit entry. The `pos` log line is written after commit.
3. Race guard (R4): `UniqueConstraintViolationException` is caught outside the transaction (which has already rolled back), the winner is re-read and the same replay-or-conflict comparison runs. A unique violation on any other index is rethrown.
4. `StoreSaleRequest`, `ListSalesRequest`, `SaleResource`, `SaleController`, routes in `routes/api/v1/sales.php` (`PosKey` and `throttle:pos` on the POST only).
5. `tests/Feature/Sales/SalesTest.php` (T1, T7, T10, T20, T22, T23, race, validation, atomicity, recipe-change, E26).

### Decisions and notes
- `on_hand_after` is the CURRENT on-hand read from one grouped query after commit. For a new sale that is exactly "after this sale". For a replay or a list row it is "now", not a historical snapshot, because no balance is stored (D-004).
- Deductions are read back from the sale's own movements, not recomputed from the recipe, so a recipe change after the sale cannot alter what a sale shows (Q-007).
- A replay writes a `sale.replayed` audit row. It moves no stock.
- `pos_reference` regex ends in `\z`, not `$`. In practice `TrimStrings` strips a trailing newline before validation over HTTP, so the newline case is tested on the rule directly.
- The race test subclasses `SaleService` so its first lookup misses; this reproduces what the losing request sees without needing real concurrency.
- The atomicity test makes the second ledger write throw. It fails if `DB::transaction` is removed (verified).
- Ticket text said `patty.pos_api_key`; the real key is `services.pos.api_key` (fixed above).

### What Mohamad must be able to explain
- Why the stock check is absent (D-010): the sale already happened at the till.
- Why the unique index is the real idempotency guard and the lookup is only the fast path (D-029).
- Why the catch sits outside the transaction.

### Interview lines
1. "A sale below zero is accepted and flagged, because the burger has already left the kitchen. Refusing it would delete a real event; a negative balance tells the owner a delivery or a count is wrong."
2. "Negative stock is information: the ledger logs a warning and the API marks the ingredient `is_negative`, so the screen shows it instead of hiding it."
3. "`pos_reference` is an idempotency key: same reference and same payload returns the original with 200 and moves no stock; a different payload is a 409, because silently replaying would hide a disagreement with the till."
4. "The database unique index, not my lookup, guarantees one sale per reference. If two retries race, the loser hits the index, rolls back, re-reads the winner and answers as a replay."
