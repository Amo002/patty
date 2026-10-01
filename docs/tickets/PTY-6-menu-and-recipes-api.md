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
