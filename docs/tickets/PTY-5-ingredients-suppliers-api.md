# PTY-5 Ingredients and suppliers API

| Field | Value |
|---|---|
| Type | Story |
| Phase | 3a Build backend |
| Status | In Review |
| Weight | S |
| Builder | Sonnet 5.5 |
| Reviewer | Opus 5.5 |
| Branch | `PTY-5-ingredients-suppliers-api` |
| Release | v0.2.0 |
| Depends on | PTY-4, PTY-16 |

## Goal

Create, list and update ingredients and suppliers over `/api/v1`. The envelope, error rendering, middleware, audit and logging already exist (PTY-16). This ticket only uses them.

## Covers

FR-1 AC1 to AC6. Q-007 (unit lock). D-021, D-022 (catalog audit and logs).

## Acceptance criteria

- [x] `GET/POST /ingredients`, `GET/PATCH /ingredients/{id}`. The list includes `on_hand` from `StockLedger::onHandForAll()`.
- [x] `GET/POST /suppliers`, `GET/PATCH /suppliers/{id}`
- [x] Controllers extend `Api\ApiController`. Create returns 201 via `created()`.
- [x] FormRequests: unique name (case-insensitive), unit in the `Unit` enum, string length bounds
- [x] `App\Services\CatalogService` (or one service per aggregate) holds create/update. Changing an ingredient's unit when movements exist throws `UnitLocked` (409 `unit_locked`).
- [x] `LogsActivity` on Ingredient and Supplier (dirty fields only). One `catalog` log line per create/update.
- [x] API Resources for both

## Tests required

- [x] Create and list an ingredient. The list shows `on_hand`.
- [x] Duplicate name, case-insensitive, gives 422 with `errors.name`
- [x] Invalid unit gives 422
- [x] Unit change blocked once a movement exists (409 `unit_locked`), allowed before
- [x] Create and list a supplier
- [x] Renaming an ingredient writes an audit entry with the old and new name

## Contract

[api.md](../api.md) E2 to E5, E7 to E10. [validation.md](../validation.md) Ingredients, Suppliers, G1 to G10. All lists paginated (D-032). All references and route keys are ULIDs (D-031).

## Flows

F1, F2.

## Test data

- Ingredients "Beef" (g) and "Bun" (piece).
- Duplicate check with "beef" (lower case) and " Beef " (padded).
- Unit lock: Beef with one +100 movement, then PATCH unit to `ml`, which gives 409.
- Pagination: 30 ingredients, `per_page=25`; page 2 has 5 rows and `has_more: false`.

## Additional acceptance criteria

- [x] Lists are paginated through `ApiResponse::paginated` and sorted by name.
- [x] Every feature test calls `assertNoIntegerIds()` (T25).
- [x] `GET /api/v1/ingredients/1` (a numeric id) gives 404.

## Done means

1. `curl -X POST .../api/v1/ingredients -d '{"name":"Lettuce","unit":"g"}'` gives 201 with a ULID `id`.
2. The same again with "lettuce" gives 422 with `errors.name`.
3. `curl .../api/v1/ingredients?per_page=2` shows `meta.pagination`.

## Additions from D-035 to D-038

- [x] E3 and E5 accept `over_tolerance_bps`, `under_tolerance_bps` and `over_tolerance_cap` (validation.md). `null` on PATCH resets a field to the default.
- [x] The Ingredient resource has `tolerance { over_bps, under_bps, over_cap, source }` (effective values) and `image_url` (absolute URL via `asset()`, or null).
- [x] Tests: override then read gives `source: ingredient`; resetting to null gives `source: default`; bps above 10,000 gives 422; `image_url` is null when there is no image.

## Builder notes

### What was built, in order
1. `UnitLocked` (409 `unit_locked`), `IngredientService` (`paginate`, `create`, `update`, `withStock`) and `SupplierService`. Each write runs in `DB::transaction` and logs one `catalog` line after it; a no-op update logs nothing (`wasChanged()`).
2. `LogsActivity` on `Ingredient` (name, unit, three tolerance fields, image_path) and `Supplier` (name, email, phone), dirty fields only.
3. Requests: `Store/UpdateIngredientRequest`, `Store/UpdateSupplierRequest`, `ListIngredientsRequest`, `ListSuppliersRequest`, with shared rules in `Concerns\IngredientRules` and `Concerns\SupplierRules` so create and update cannot drift.
4. `IngredientResource`, `SupplierResource`, two controllers, eight routes appended to `routes/api/v1/catalog.php`.
5. `IngredientsTest` (20 cases) and `SuppliersTest` (11 cases).

### What Mohamad must be able to explain
- Why `unit_locked` and `on_hand` come from one `onHandForAll()` call: that query has a row for every ingredient that ever had a movement, even when the sum is 0, so "has a row" means "has stock history". No per-row query, and the lock follows history, not the balance.
- Why the lock check runs inside the transaction, and compares the incoming unit with the stored one (resending `g` for a `g` ingredient is not a change).
- Why `integer:strict`: plain `integer` accepts `true` and "5" as numbers.
- PATCH semantics (G10): absent key means leave alone, `null` on a tolerance field means reset to the default, so `effectiveTolerance()` reports `source: default` again.
- Why messages are static text: user input is never interpolated into an error.

### Deviations and notes
- `incoming` is NOT in the Ingredient resource. PTY-10 adds it, because it needs open-PO maths from PTY-7.
- `IngredientService` attaches `on_hand` and `has_movements` to the models as attributes (never saved), because `ApiResponse::paginated` builds resources from a class name and cannot take extra arguments.
- Name padding is handled by Laravel's `TrimStrings` middleware, so " Beef " is validated as "Beef".
- A concurrent duplicate name that slips past `Rule::unique` hits the NOCASE index and surfaces as a 500, the same known gap as PTY-6.
- Out-of-range `per_page` returns 422 (G8), tested on ingredients.

## Reviewer findings

Reviewer: Opus 5.5. Verdict: approve once #2 to #4 are fixed. #1 needs a decision from Mohamad. Pint is clean, the suite passes (156 tests on the branch, 241 after merging develop, no conflicts). All 9 mutations were caught.

| # | Severity | File:line | Finding | Resolution |
|---|---|---|---|---|
| 1 | Medium (decision) | app/Services/IngredientService.php:70 | The unit lock follows D-015 to the letter: it only counts stock movements. But recipe lines and PO lines are also integers in the ingredient's unit. Changing Beef from g to ml before any movement turns a recipe's "150 g" into "150 ml". Worse, it turns a sent PO's 1000 g into 1000 ml, and that PO will then be received in the new unit. Two options: extend the lock to "has movements, recipe lines or PO lines" (two more `exists()` calls and one more message), or record in decisions.md that this is accepted. The builder is not at fault. | open |
| 2 | Medium | app/Services/IngredientService.php:68-69 | The comment claims more than the code does. It says a movement committed between the check and the save "cannot slip past the lock". Nothing locks the ingredient row. On MySQL or Postgres a concurrent movement could commit after `exists()` and the unit change would go through. On SQLite the single-writer lock makes the racing write fail with "database is locked" (a 500); the lock is never bypassed silently. Fix: make the comment as honest as PurchaseOrderService's, and if wanted, re-read the ingredient with `lockForUpdate()` before the check (same pattern as PTY-7). A note, not a blocker. | open |
| 3 | Low | tests/Feature/Catalog/IngredientsTest.php, SuppliersTest.php | The additional AC "every feature test calls `assertNoIntegerIds()` (T25)" is ticked, but 7 tests never call it. In IngredientsTest: locked after net zero, rename audit, no-op update, create log line, query count. In SuppliersTest: clear email/phone, contact audit. All 25 PTY-6 tests do. Add the call to each JSON response. | open |
| 4 | Low | docs/tickets/PTY-5-ingredients-suppliers-api.md:95 | The builder note about a "known gap" is out of date. PTY-26 is merged, so a lost unique race now returns 409 `conflict`, not 500. Update the note. | open |
| 5 | Low | app/Services/IngredientService.php:91, 109 | `withStock()` for a single ingredient calls `onHandForAll()`, which sums every ingredient. `paginate()` also sums all ingredients, not just the 25 on the page. The figures are correct and it is one query either way. For one ingredient, `onHand()` plus `stockMovements()->exists()` says what it means more plainly. For a page, an optional `onHandForAll($ids)` filter would do the same. Fine at this scale, but be ready to explain it. | open |
| 6 | Low | app/Services/IngredientService.php:112-113, app/Http/Resources/V1/IngredientResource.php:31-34 | `on_hand` and `has_movements` are set as unsaved attributes, so they count as dirty. A later `save()` on that instance would try to write columns that don't exist. The resource also outputs `null` silently if it is ever built without going through the service. Acceptable and explainable: the service owns the lookup, so StockLedger stays the only definition of on-hand (D-004). `withSum('stockMovements as on_hand', 'quantity_delta')` plus `withExists(...)` would load them as clean attributes in the same query, but it would put a second on-hand formula outside StockLedger. Keep the current approach. Optionally, add a docblock line saying never to save an instance after `withStock()`. | open |
| 7 | Low | tests/Feature/Catalog/*.php | No test checks the `created` activity row on ingredient or supplier create (F1, F2: activity_log +1). Nothing checks the "Supplier created" log line either. Deleting that log call goes uncaught. | open |
| 8 | Info | docs/flows.md F1, F2; docs/api.md "Ingredient"; docs/validation.md Ingredients | The docs have drifted from the code. The flows still name `CatalogService::createIngredient`. api.md shows `unit_label: "grams"` while the enum returns "Grams". validation.md gives the duplicate-name example as "An ingredient called Beef already exists.", which puts input into the message. The code's static text is the correct choice. Bring the docs in line with the code. | open |
