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
- [ ] T22: `config(['patty.pos_api_key' => 'secret'])`. No header gives 401; `X-POS-Key: secret` gives 201.

## Done means

1. POST a sale of 2 Classic Burgers. 201 with three deductions and `number: SALE-2026-000001`.
2. POST the same body again. 200 with `replayed: true`, and stock unchanged.
3. POST the same reference with quantity 3. 409 `idempotency_conflict`.
