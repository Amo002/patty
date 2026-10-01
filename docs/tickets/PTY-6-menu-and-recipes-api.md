# PTY-6 Menu items and recipes API

| Field | Value |
|---|---|
| Type | Story |
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

## Tests required

- [ ] Create Classic Burger with three lines. GET returns them with units.
- [ ] Duplicate ingredient in a recipe gives 422
- [ ] Quantity 0 or a decimal gives 422
- [ ] Replacing the recipe removes the old lines
