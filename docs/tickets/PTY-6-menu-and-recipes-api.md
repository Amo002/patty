# PTY-6 Menu items and recipes API

| Field | Value |
|---|---|
| Type | Story |
| Phase | 3a Build backend |
| Status | To Do |
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

- [ ] `GET/POST /menu-items`, `GET/PATCH /menu-items/{id}`
- [ ] `PUT /menu-items/{id}/recipe` replaces the recipe with the given lines in one transaction (simpler than per-line endpoints, and the UI edits the recipe as a whole)
- [ ] Validation: at least one line, ingredient exists, quantity an integer >= 1, no duplicate ingredient
- [ ] Resources include recipe lines with the ingredient name and unit
- [ ] Replacing a recipe writes the audit event `recipe.replaced` with the old and new lines in properties, plus a `catalog` log line. `LogsActivity` on MenuItem.

## Tests required

- [ ] Create Classic Burger with three lines. GET returns them with units.
- [ ] Duplicate ingredient in a recipe gives 422
- [ ] Quantity 0 or a decimal gives 422
- [ ] Replacing the recipe removes the old lines
- [ ] Replacing the recipe stores old and new lines in the `recipe.replaced` audit entry

## Contract

[api.md](../api.md) E11 to E15. [validation.md](../validation.md) Menu items. E12 accepts an optional `recipe`; a menu item without one has `is_sellable: false`.

## Flows

F3, F4.

## Test data

Classic Burger: Beef 150, Bun 1, Cheese 20. Replacing it with Beef 180, Bun 1 removes cheese, and the audit entry holds the old 3 lines and the new 2 lines.

## Additional acceptance criteria

- [ ] Recipe line ingredients are referenced by ULID (G4).
- [ ] The list is paginated with recipes eager-loaded (no N+1).
- [ ] `assertNoIntegerIds()` in every test.

## Done means

1. Create Classic Burger with its recipe through E12. The response lists 3 lines with units.
2. PUT a recipe with a duplicate ingredient. 422 names the line.
3. `GET /api/v1/activity?subject_type=menu_item&subject_id=<ulid>` shows `recipe.replaced` (once PTY-10 lands).

## Additions from D-035 to D-038

- [ ] The menu item resource has `image_url` (absolute URL or null).
