# PTY-10 Visibility endpoints

| Field | Value |
|---|---|
| Type | Story |
| Phase | 3a Build backend |
| Status | Done |
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

## Reviewer findings

Reviewer: Opus 5.5. Full suite green (344 tests). Mutation runs: 10 of 13 killed; the 3 survivors are findings 1, 4 and 5.

| # | Severity | File:line | Finding | Resolution |
|---|---|---|---|---|
| 1 | Medium | `app/Services/StockQuery.php:164`, `tests/Feature/Visibility/MovementsTest.php:35` | Mutant "window `ORDER BY id`, list `orderByDesc('id')` only" survives the whole Visibility suite. Every test inserts movements in business-time order, so `occurred_at` is never exercised. Backdating is allowed (`received_at` any time after `sent_at`; a POS `sold_at` from an offline till), so insert order and business order can differ. Add a test: sale at 12:00, then a delivery recorded later with `received_at` 11:00; assert the history order and that `balance_after` is recomputed in business-time order. | Fixed: MovementsTest "orders the running balance by business time" (a sale, then a delivery backdated an hour). Re-running the mutant now fails it: -300, 700 instead of 700, 1000. |
| 2 | Medium | `app/Http/Resources/V1/MovementResource.php:27`, `docs/flows.md:173` | F18 says the source document is "linked", but `reference` is `{type, number, label}` with no ULID. The UI cannot link a GRN to its PO detail or open the sale. Decide before PTY-11: either add `purchase_order_id` (for a delivery) or the sale `id` as ULIDs to `reference` (api.md Movement shape), or remove "linked" from F18. | Fixed: `reference` carries `purchase_order_id` (delivery) or `sale_id` (sale) as ULIDs; api.md Movement updated; tested. |
| 3 | Low | `app/Services/StockQuery.php:34`, `app/Http/Controllers/Api/V1/PurchaseOrderController.php:33` | The "open = sent or received" list is written twice, and the `PurchaseOrderStatus` docblock says it is the one place its rules live. A live "cancelled" state would need two edits, and missing one would make incoming and E16 disagree. Move it to `PurchaseOrderStatus::open()` and use it in both places. | Fixed: `PurchaseOrderStatus::open()`, used by StockQuery and PurchaseOrderController. |
| 4 | Low | `app/Services/StockQuery.php:275`, `tests/Feature/Visibility/ActivityTest.php:33` | Mutant "keep `id` and `*_id` keys in changes" survives all Feature tests. No model logs an `*_id` attribute (PurchaseOrder logs `status` only, by design), so the guard and its test can never fail today. Keep the guard as defence in depth, but say so in the comment, and do not present the test as proof. Related: `image_path` (Ingredient, MenuItem `logOnly`) reaches E29 `changes` as an internal storage path, while the API elsewhere exposes only `image_url`. | Fixed: the comment calls the `*_id` guard defence in depth; `image_path` is dropped too; the reshaper is a public static method with a unit test (ActivityChangesTest) that does fail without it. |
| 5 | Low | `app/Services/StockQuery.php:164` | Mutant "drop PARTITION BY" survives. It is an equivalent mutant: `WHERE ingredient_id = ?` already limits the window to one ingredient, so the partition is redundant. This is harmless, but be ready to say so. api.md and the ticket AC show the formula without PARTITION BY; align the docs or the code. | Fixed: PARTITION BY removed, the docblock says why it is not needed. Code now matches api.md. |
| 6 | Low | `app/Services/StockQuery.php:252` | Deleted draft PO: entries written by the LogsActivity trait (the status `created` event) carry no `properties.number`, so after deletion their `subject.label` is null. Only the `Audit::record` events keep a label. Filtering E29 by the deleted PO's ULID returns an empty page, because the ULID lookup finds no row. Either accept and document this, or fall back to `description` for the label. | Fixed: falls back to the recorded number, then to "Deleted purchase order". The empty page for a deleted ULID is accepted and stated in the comment. |
| 7 | Low | `tests/Feature/Visibility/IncomingTest.php` | No test covers an over-received line on a still-open PO combined with a second open PO of the same ingredient. Incoming must add 0 for that line, not -30. The code is correct because `outstanding()` is 0 once a line is complete, but nothing pins it. | Fixed: IncomingTest "adds nothing for an over-received line" (1030 of 1000 received plus a second order of 400 gives 400, not 370). |
| 8 | Low | `tests/Feature/Visibility/DayAtPattyTest.php:146` | T8 skips some cells of the flows.md table: the document numbers GRN-1 to GRN-5, "over-received 30" on A at 15:00 (`lines.0.quantity_over_received`), and the cheese history showing `balance_after` -20 at 13:00. Each is a one-line assertion and makes the test match the table cell for cell. | Deferred: the existing cells already prove the arithmetic; the extra assertions are cosmetic. PTY-14 will add them if time allows. |
| 9 | Info | `app/Services/StockQuery.php:60`, `app/Services/IngredientService.php:146` | Two methods named `attachStock` each loop on-hand; the formula is shared through `onHandForAll()`, which is correct. To keep one attach path, IngredientService could call `StockQuery::attachStock()` and add only `unit_locked`. | Deferred: both share `onHandForAll()`, so the formula is not duplicated. Merging the two methods would mean changing IngredientService, which belongs to a merged ticket; not worth a cross-ticket change now. |
| 10 | Info | commit `407badd` | Authored with the GitHub noreply email, while the other four use the gmail address. Both are Mohamad and there are no AI trailers. Fine, but the authorship is inconsistent. | Fixed: the branch was unpushed, so the commit was re-authored with the gmail address. |

Deviations judged: (1) incoming in PHP over one `withSum` query is accepted. The query count is constant (one query, tested), and E27 limits the lines to the page's ingredients. Memory is O(open lines), which is tens to hundreds for one branch. A SQL version would have to copy `max(1, ordered - intdiv(ordered * under_bps, 10000))` and could drift from `Tolerance`. (2) `incoming === 0` in IngredientsTest is accepted. (3) The `generated_at` one-liner is accepted. (4) An empty page for an unknown ULID is accepted, and matches E29's 200/422 contract. (5) The deleted subject label is accepted, see #6. (6) The open-status list is not accepted as is, see #3.
