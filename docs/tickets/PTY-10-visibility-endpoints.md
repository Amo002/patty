# PTY-10 Visibility endpoints

| Field | Value |
|---|---|
| Type | Story |
| Phase | 3a Build backend |
| Status | To Do |
| Weight | M |
| Builder | Sonnet 5.5 |
| Reviewer | Opus 5.5 |
| Branch | `PTY-10-visibility-endpoints` |
| Release | v0.3.0 |
| Depends on | PTY-8, PTY-9 |

## Goal

The owner's two questions, answered by the API: how much of each ingredient do I have, and which orders are still open with what outstanding.

## Covers

FR-6 AC1 to AC7. D-020, D-021. T8, T11, T16.

## Acceptance criteria

- [ ] E27 `GET /api/v1/stock`: paginated, every ingredient with on-hand, **incoming** (sum of outstanding on sent/received POs, D-020), unit and a `negative` flag. Grouped queries, no N+1. `generated_at` in `meta`.
- [ ] E6 `GET /api/v1/ingredients/{ingredient}/movements`: movements newest first, paginated, no ids, with reason, delta, reference label (for example "GRN-2026-0003 for PO-2026-0002", "SALE-2026-000012 Classic Burger x2") and running balance
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

## Additions from D-035 to D-038

- [ ] Incoming (E27) and outstanding (E16) use `is_complete`. A line completed within under-tolerance contributes 0 incoming, and its shortfall is not "coming".
- [ ] T16 extended: a PO line of 1000 with 960 received contributes incoming 0.

## Builder notes

### What was built, in order
1. `App\Services\StockQuery`: `stock()`, `attachStock()`, `attachIncoming()`, `incomingFor()`, `dashboard()`, `movements()`, `activity()`. Read-only; on-hand always comes from `StockLedger::onHandForAll()` (no second formula).
2. E27, E28, E29 and E6 controllers, requests, resources and routes in `routes/api/v1/visibility.php`. E6 lives there, not in catalog.php, because it is a stock view of an ingredient.
3. `incoming` added to `IngredientResource` (E2, E4) through `IngredientService`, which now takes `StockQuery`. `meta.generated_at` on E27, E28 and E16 `?status=open` (the PurchaseOrderController one-liner).
4. Tests in `tests/Feature/Visibility/`: `DayAtPattyTest` (T8), `IncomingTest` (T16), `OpenOrdersTest` (T11), `MovementsTest`, `ActivityTest`, `StockAndDashboardTest`.

### How the numbers are computed (what to say on the call)
- **Incoming** = for every line of a sent or received order, `PurchaseOrderLine::outstanding()`, summed per ingredient. It runs in PHP over ONE query (open lines with `withSum` for the received total), not in SQL, because "complete" depends on each line's tolerance snapshot (D-035, D-036) and that rule already exists in the model. A second copy in SQL could drift. A line completed within under-tolerance contributes 0. Draft and closed orders are excluded. The query count does not grow with the number of ingredients.
- **balance_after** = `SUM(quantity_delta) OVER (PARTITION BY ingredient_id ORDER BY occurred_at, id)`. The database computes the window over the whole filtered set before LIMIT/OFFSET, so page 2 continues the balance from page 1. The newest row therefore equals on-hand. The reference label comes from eager-loaded relations (`morphWith`), so there is no N+1.
- **Activity**: subject resolved from the morph alias to `{type, ulid, label}`. `changes` come from spatie v5's `attribute_changes` (`{attributes, old}` turned into `{field: [old, new]}`), with `id` and `*_id` keys dropped. A deleted subject keeps its label from `properties.number` and has `id: null`. An unknown ULID gives an empty page, not 404.

### Decisions and notes
- The existing placeholder assertion in `IngredientsTest` (`not->toHaveKey('incoming')`) was changed to `incoming === 0`, because this ticket adds the field.
- The `activity` filter accepts uppercase ULIDs (lowercased in `prepareForValidation`, as G4 does elsewhere).
- `NoStoreCache` already covers every API response, so E27 needed no extra header code; a test pins it.
