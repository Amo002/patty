# PTY-18 UI: Catalogue: Ingredients, Suppliers, Menu and Recipes

| Field | Value |
|---|---|
| Type | Story |
| Phase | 3b Build frontend |
| Status | To Do |
| Weight | M |
| Builder | Sonnet 5.5 |
| Reviewer | Opus 5.5 (code) + Opus 5.5 design reviewer with Claude in Chrome |
| Branch | `PTY-18-ui-catalogue` |
| Release | v0.4.0 |
| Depends on | PTY-11, and the backend endpoints it uses |

Split out of PTY-12 by Q-012 (D-043). Built in code from the approved design system.

## Scope

Ingredients (photos, stock, tolerance column and override dialog, history drawer with running balance), Suppliers (list, create and edit dialog), Menu and Recipes (photo cards, recipe editor with quantity inputs).

## Contract and flows

Endpoints: [api.md](../api.md) E2 to E15, E6. Flows: [flows.md](../flows.md) F1 to F4, F18. Behaviour rules U1 to U12 and the unit conversion table: [ui.md](../ui.md). User satisfaction rules: [design.md](../design.md#user-satisfaction-rules).

## Acceptance criteria

- [ ] Every page reads and writes only through `/api/v1` and sends `X-Patty-Channel: ui` (POS Simulator: `pos`)
- [ ] Every data region has loading (skeleton), empty, error and loaded states; lists lazy-load further pages
- [ ] Only allowed actions are shown; irreversible actions confirm with a plain summary; buttons show the busy state
- [ ] 422 inline by field (in the unit the user typed); 409 notice plus refresh
- [ ] Quantities use the quantity input (g/kg, ml/L) and the units formatter; photos fall back to an icon
- [ ] API data is rendered with `x-text` only, never `x-html` or `innerHTML`
- [ ] Feature tests: each page route returns 200 with `Cache-Control: no-store`
- [ ] Design reviewer pass (1440 px and 820 px, light and dark), findings resolved

## Done means

Run QA-1, QA-2 from [testing.md](../testing.md) in the browser, light and dark.
