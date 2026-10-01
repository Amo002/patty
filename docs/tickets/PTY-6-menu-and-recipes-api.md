# PTY-6 Menu items and recipes API

| Field | Value |
|---|---|
| Type | Story |
| Phase | 3a Build backend |
| Status | In Review |
| Weight | S |
| Builder | Sonnet 5.5 |
| Reviewer | Opus 5.5 |
| Branch | `PTY-6-menu-and-recipes-api` |
| Release | v0.2.0 |
| Depends on | PTY-5, Q-007 |

## Goal

Define menu items and the ingredients each one uses.

## Covers

FR-2 AC1 to AC4. Q-007.

## Acceptance criteria

- [x] `GET/POST /menu-items`, `GET/PATCH /menu-items/{id}`
- [x] `PUT /menu-items/{id}/recipe` replaces the recipe with the given lines in one transaction (simpler than per-line endpoints, and the UI edits the recipe as a whole)
- [x] Validation: at least one line, ingredient exists, quantity an integer >= 1, no duplicate ingredient
- [x] Resources include recipe lines with the ingredient name and unit
- [x] Replacing a recipe writes the audit event `recipe.replaced` with the old and new lines in properties, plus a `catalog` log line. `LogsActivity` on MenuItem.

## Tests required

- [x] Create Classic Burger with three lines. GET returns them with units.
- [x] Duplicate ingredient in a recipe gives 422
- [x] Quantity 0 or a decimal gives 422
- [x] Replacing the recipe removes the old lines
- [x] Replacing the recipe stores old and new lines in the `recipe.replaced` audit entry

## Contract

[api.md](../api.md) E11 to E15. [validation.md](../validation.md) Menu items. E12 accepts an optional `recipe`; a menu item without one has `is_sellable: false`.

## Flows

F3, F4.

## Test data

Classic Burger: Beef 150, Bun 1, Cheese 20. Replacing it with Beef 180, Bun 1 removes cheese, and the audit entry holds the old 3 lines and the new 2 lines.

## Additional acceptance criteria

- [x] Recipe line ingredients are referenced by ULID (G4).
- [x] The list is paginated with recipes eager-loaded (no N+1).
- [x] `assertNoIntegerIds()` in every test.

## Done means

1. Create Classic Burger with its recipe through E12. The response lists 3 lines with units.
2. PUT a recipe with a duplicate ingredient. 422 names the line.
3. `GET /api/v1/activity?subject_type=menu_item&subject_id=<ulid>` shows `recipe.replaced` (once PTY-10 lands).

## Additions from D-035 to D-038

- [x] The menu item resource has `image_url` (absolute URL or null).

## Builder notes

### What was built, in order
1. `MenuService` (`create`, `rename`, `replaceRecipe`, plus `paginate` and `withRecipe` for eager loading). Create and replace share one private `writeRecipe` (read old lines, delete, insert, audit), so F3 and F4 cannot drift. Each public write runs in `DB::transaction` and logs one line to the `catalog` channel after commit.
2. `LogsActivity` on `MenuItem` (name and image_path, dirty only). Field changes are stored in the activity row's `attribute_changes` (spatie v5), not in `properties`.
3. Requests: `StoreMenuItemRequest`, `UpdateMenuItemRequest`, `ReplaceRecipeRequest`, `ListMenuItemsRequest`, and the shared trait `Concerns\RecipeLineRules` (E12 uses key `recipe`, E15 uses `lines`).
4. `MenuItemResource`, `RecipeLineResource`, `MenuItemController`, five routes in `routes/api/v1/catalog.php`.
5. `tests/Feature/Catalog/MenuItemsTest.php` (26 cases including datasets).

### What Mohamad must be able to explain
- Why replace is delete-then-insert inside one transaction, and why the audit entry is written in the same transaction (the trail cannot disagree with the data).
- Why `prepareForValidation` lowercases ULIDs: `ulid` accepts uppercase, stored ULIDs are lowercase, and `distinct` compares strings.
- Why the FormRequest resolves ULIDs to `Ingredient` models in `passedValidation()`: the service never handles input ids (G4).
- Why line error messages are built per line in `lineMessages()`: Laravel placeholders cannot carry "Line 2 (Beef)".
- Why `Rule::unique` plus the NOCASE index: the rule gives a friendly 422, the index is the race backstop.

### Deviations and notes
- Line error keys are flat dotted strings (`recipe.1.quantity`), as Laravel produces them.
- A PATCH with no `name` is a no-op 200 (G10).
- Create with a recipe writes a `recipe.replaced` entry with `old: []` (F3 says activity +2). Create without a recipe writes none.
- A concurrent duplicate name that slips past `Rule::unique` hits the NOCASE index and surfaces as a 500, not a 422. Not in the ticket; candidate follow-up.
- Audit lines are `{ingredient, ingredient_id (ULID), quantity}`.
- Done-means item 3 (activity endpoint) waits for PTY-10.

## Reviewer findings

Reviewer: Opus 5.5. Pint passes, full suite 100/100 green. Scope is clean (only PTY-6 files plus `MenuItem.php`), commits are authored by the repo owner with no trailers.

| # | Severity | File:line | Finding | Resolution |
|---|---|---|---|---|
| 1 | Medium | `app/Http/Requests/V1/Concerns/RecipeLineRules.php:62` | `quantity: true` is accepted and stored as 1 (201), breaking G3, which lists `true` as rejected. Laravel's `integer` rule uses `filter_var(..., FILTER_VALIDATE_INT)`, which turns `true` into 1, and `min:1` then measures it as a one-character string. Fix: add `numeric` (`is_numeric(true)` is false) or use `integer:strict`, and add `true` and `"1.5"` to the dataset at `MenuItemsTest.php:136`. PO lines, deliveries and sales will copy this rule, so fix the pattern once. | Fixed: `integer:strict` in `RecipeLineRules::lineRules()` (the one shared place, with a comment for PO lines, deliveries and sales to copy). Dataset now covers true, false, "1.5", 1.5, "abc", "1e3", -1, 0, null and []. Mutation (plain `integer`) fails the suite. |
| 2 | Medium | `app/Http/Requests/V1/StoreMenuItemRequest.php:51`, `UpdateMenuItemRequest.php:38` | `'A menu item called '.$this->input('name')` runs inside `messages()` on every request. `{"name": ["x"]}` raises "Array to string conversion" and returns 500 `server_error` instead of 422. Fix: use Laravel's `:input` placeholder (`'A menu item called :input already exists.'`), and add a test for an array name on POST and PATCH. | Fixed: static message "A menu item with this name already exists." on POST and PATCH. Test: `{"name":["x"]}` gives 422 on both; restoring the interpolation fails it. |
| 3 | Medium | `app/Services/MenuService.php:97` | No test proves that replace is atomic. Mutation: removing `DB::transaction` keeps all 26 tests green. The "keeps the old recipe" tests fail in validation and never reach the service. Add a test that makes the audit write throw (for example `Activity::creating(fn () => throw new RuntimeException)`) and asserts 500 and that the old lines are still there. The reviewer checked that this passes with the transaction and would fail without it. | Fixed: test makes `Activity::creating` throw during replace, asserts 500, same rows with same primary keys, and no `recipe.replaced` entry. Removing `DB::transaction` fails it (verified). |
| 4 | Low | `tests/Feature/Catalog/MenuItemsTest.php:106-116` | The duplicate-by-case test passes for the wrong reason. If the lowercasing is removed, the uppercase ULID fails `exists` on the same key `lines.1.ingredient_id`, so the test stays green (only the "accepts uppercase" test catches it). Assert the message `Line 2 (Beef): ingredient is listed more than once.` | Fixed: the test now asserts "Line 2 (Beef): ingredient is listed more than once." |
| 5 | Low | `tests/Feature/Catalog/MenuItemsTest.php` | No boundary tests for S10 and G5. Removing `max:50` or `max:1000000` keeps the suite green. Add tests for 50 lines (ok) and 51 lines (422), and for quantities 1,000,000 (ok) and 1,000,001 (422). | Fixed: boundary tests for 50/51 lines (POST and PUT), quantity 1,000,000/1,000,001, name 2/1/100/101 (POST and PATCH). Raising each max fails its test (verified). |
| 6 | Low | `app/Services/MenuService.php:57,79` | A concurrent duplicate name gets past `Rule::unique`, hits the NOCASE unique index and returns 500, not 422 (documented by the builder). Not required here. Recommendation: in the planned follow-up ticket, map `UniqueConstraintViolationException` centrally in `bootstrap/app.php` to 422 `validation_failed`. | follow-up ticket (central mapping of `UniqueConstraintViolationException` to 422). No change here. |
| 7 | Low | `tests/Feature/Catalog/MenuItemsTest.php:138,251,261,291,312` | The ticket says to use `assertNoIntegerIds()` in every test, but these tests skip it on their JSON responses (404, 422 empty lines, zero quantity, create audit, list). The rename test checks only the first of its three responses. | Fixed: every JSON response in the file, including the three rename responses, the 404s, the 500 and the list queries, goes through `assertNoIntegerIds()`. |
| 8 | Nit | `app/Http/Requests/V1/Concerns/RecipeLineRules.php:89` | `lineMessages()` queries ingredient names on every request, including valid ones, because `messages()` is built before validation runs. It is one bounded query (at most 50 strings). Acceptable, but Mohamad should know why the query is there. | Accepted: `messages()` runs before validation, so the names query is unavoidable without a second validation pass. It is one bounded query (at most 50 ULIDs) and it is what makes "Line 2 (Beef)" possible. |
| 9 | Nit | `app/Services/MenuService.php:80` | A PATCH that re-sends the same name still writes a "Menu item renamed" catalog line with old equal to new. No activity row is written, because nothing is dirty. Could be guarded with `wasChanged('name')`. | Fixed: `rename()` returns early when the name is identical (case-sensitive), so no log line is written; a case-only change still renames. Test uses a catalog TestHandler. |
| 10 | Nit | `app/Services/MenuService.php:59` | The `$lines !== []` branch can never run, because E12 `min:1` rejects `[]`. On create, `writeRecipe` also reads and deletes old lines that cannot exist yet. Harmless, but either simplify it or be ready to explain it. | Fixed: create calls `insertLinesAndAudit($item, $lines, [])` only when `$lines !== null`; the read-and-delete of old lines moved into `replaceRecipe()`. |

### Builder deviations: verdicts
- Shared `RecipeLineRules` trait: accepted. One definition for E12 and E15 means the two endpoints cannot drift.
- MenuService `paginate` and `withRecipe`: accepted. Eager loading lives in one place (`recipeRelations()`), and the N+1 test catches its removal.
- Flat dotted error keys: accepted. They match the api.md example (`lines.0.quantity`).
- Create with a recipe writes `recipe.replaced` with `old: []`: accepted. It matches F3 (activity +2).
- Rename audit in `attribute_changes`: accepted (spatie v5). PTY-10's E29 `changes` must read that column, not `properties`.
- PATCH without a name returns 200 as a no-op: accepted (G10).
- Line messages built in `messages()` instead of G7's `attributes()`: accepted, because placeholders cannot carry "Line 2 (Beef)".
- Concurrent duplicate name returns 500: see finding 6.

### Mutation results (`tests/Feature/Catalog/MenuItemsTest.php`)
| Mutation | Result |
|---|---|
| Remove `distinct` | killed (2 tests) |
| Quantity `min:0` | killed (2) |
| Drop ULID lowercasing | killed (1; see finding 4) |
| Drop `DB::transaction` in replace | **survived** (finding 3) |
| Audit `old` set to `[]` | killed (1) |
| Paginate size hardcoded to 10 | killed (1) |
| Drop eager load in `paginate` | killed (1) |
| Drop nested `recipeLines.ingredient` eager load | killed (1) |
| Drop `max:50` | **survived** (finding 5) |
| Drop `max:1000000` | **survived** (finding 5) |
| Drop the unique `ignore` on PATCH | killed (1) |
| Drop the `recipe.replaced` audit call | killed (2) |
| Drop delete of the old lines | killed (2) |
