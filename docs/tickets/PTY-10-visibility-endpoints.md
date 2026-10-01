# PTY-10 Visibility endpoints

| Field | Value |
|---|---|
| Type | Story |
| Status | To Do |
| Weight | M |
| Builder | Opus 5.5 |
| Reviewer | Fable 5.1 |
| Branch | `PTY-10-visibility-endpoints` |
| Release | v0.3.0 |
| Depends on | PTY-8, PTY-9 |

## Goal

The owner's two questions, answered by the API: how much of each ingredient do I have, and which orders are still open with what outstanding.

## Covers

FR-6 AC1 to AC5. T8, T11, T14.

## Acceptance criteria

- [ ] `GET /api/v1/stock`: every ingredient with on-hand, unit and a `negative` flag. One grouped query. Includes `generated_at`.
- [ ] `GET /api/v1/stock/{ingredient}/movements`: movements newest first, with reason, delta, reference label (for example "Delivery #3 / PO-0002", "Sale #12 (Classic Burger x2)") and running balance
- [ ] `GET /api/v1/purchase-orders/open`: orders in sent or received, with lines (ordered, received, outstanding) and totals. Includes `generated_at`.
- [ ] `GET /api/v1/dashboard`: counts for the KPI row (ingredients, negative count, open orders, outstanding lines)
- [ ] No N+1: outstanding computed with `withSum` or one grouped query

## Tests required

- [ ] T8: interleaved scenario (two partial deliveries, three sales, a full delivery). Final on-hand per ingredient equals a total computed by hand in the test.
- [ ] T11: open orders after a partial delivery show the right outstanding. A closed order is absent.
- [ ] Movement history running balance ends at the on-hand value
- [ ] T14: `Cache-Control: no-store` on these endpoints
