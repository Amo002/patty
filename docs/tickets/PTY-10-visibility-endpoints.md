# PTY-10 Visibility endpoints

| Field | Value |
|---|---|
| Type | Story |
| Phase | 3a Build backend |
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

FR-6 AC1 to AC7. D-020, D-021. T8, T11, T16.

## Acceptance criteria

- [ ] E27 `GET /api/v1/stock`: paginated, every ingredient with on-hand, **incoming** (sum of outstanding on sent/received POs, D-020), unit and a `negative` flag. Grouped queries, no N+1. `generated_at` in `meta`.
- [ ] E6 `GET /api/v1/ingredients/{ingredient}/movements`: movements newest first, paginated, no ids, with reason, delta, reference label (for example "Delivery #3 / PO-0002", "Sale #12 (Classic Burger x2)") and running balance
- [ ] E16 `GET /api/v1/purchase-orders?status=open`: orders in sent or received, with lines (ordered, received, outstanding), `progress_percent`, paginated, `meta.generated_at`.
- [ ] E28 `GET /api/v1/dashboard`: counts for the KPI row (ingredients, negative count, open orders, outstanding lines)
- [ ] E29 `GET /api/v1/activity?subject_type=&subject_id=`: audit entries newest first, paginated, subject referenced by ULID, each with event, description, subject label, channel, request id and time
- [ ] No N+1: outstanding computed with `withSum` or one grouped query

## Tests required

- [ ] T8: "A day at Patty" from flows.md, step by step. After every row of that table, assert beef, bun and cheese on-hand and incoming. Final: beef 180, bun 21, cheese 120; 17 movements; 5 deliveries; 4 sales.
- [ ] T11: open orders after a partial delivery show the right outstanding. A closed order is absent.
- [ ] Movement history running balance ends at the on-hand value
- [ ] T16: incoming. A PO of 1000 g beef, sent, with 600 received: incoming beef = 400. After short-close, incoming = 0. A draft PO contributes nothing.
- [ ] Activity endpoint filters by subject and returns the envelope with pagination in `meta`

## Contract

[api.md](../api.md) E6, E16 (`status=open`), E27, E28, E29. [validation.md](../validation.md) Queries.

## Flows

F17, F18, F19, and "A day at Patty" (T8).

## Additional acceptance criteria

- [ ] `balance_after` on E6 uses a SQL window function (`SUM(quantity_delta) OVER (ORDER BY occurred_at, id)`) with bindings, so it is correct on every page.
- [ ] `assertNoIntegerIds()` in every test, including E6 and E29, which carry no ids at all.

## Done means

1. Run the T8 scenario through the API (or the Postman scenario). `GET /stock` shows beef 180, bun 21, cheese 120.
2. `GET /ingredients/{cheese}/movements`: the first row's `balance_after` equals cheese on-hand.
3. `GET /dashboard` shows `negative_count` 0 at the end of the day (cheese recovered at 15:30).
