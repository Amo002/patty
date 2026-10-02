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

## Builder notes

Where things live:

- **Pages:** `resources/views/pages/{ingredients,suppliers,menu}.blade.php`, one Alpine component each (`ingredientsPage`, `suppliersPage`, `menuPage`) in `public/js/pages/`. Routes are one commented block `// PTY-18 catalogue pages` in `routes/web.php`.
- **Shared list logic:** `public/js/pages/catalogue-common.js` exposes `Patty.pagedList(path)`: the four states (loading, empty, error, loaded), 25 per page, the IntersectionObserver sentinel plus a "Load more" button, `refresh(id)` (refetch the pages on screen, flash the changed row). All three pages and the history drawer spread it into their component. List requests are silent: the list shows its own error with the request id instead of a toast.
- **Assets partial:** `pages/catalogue/assets.blade.php` pushes the scripts and links `public/css/pages/catalogue.css`. The layout has no styles stack, so the stylesheet link sits in the page body (valid HTML).
- **Quantity input per unit:** the component fixes its unit at render time, so the recipe editor and the tolerance cap render one copy per unit (`g`, `ml`, `piece`) and `x-if` picks the one for the chosen ingredient. Recipe lines use `context="recipe"` (g and ml first, D-038).
- **Never lose a bad quantity:** an input showing an error holds `null`, so each page records `qtyError` and refuses to save until it is fixed, instead of sending "no value".
- **Tolerance:** percent text becomes basis points by string splitting (no `* 100`). Empty fields are "use the default": on edit they send `null`, on create they are left out. "Use default" empties all three.
- **Recipe save:** rename (E14) first when the name changed, then `PUT` the whole recipe (E15). If the second step fails the rename stays saved and the list is refreshed. An edit with zero lines is refused locally with a plain message.
- **Ingredients poll** every 10 s (`Patty.api.poll`) so on-hand tracks deliveries and sales; Suppliers and Menu refresh after their own changes only.

Assumptions and gaps:

- The history drawer assumes E6 returns newest first (api.md says "newest first" in the ticket, the shape does not state the order). If PTY-10 returns oldest first, change the ORDER BY there, not the UI. A 404 shows "History will appear here once stock movements are available".
- `/ingredients` endpoints (PTY-5) are not in this branch, so locally the Ingredients and Suppliers pages show their error state; the Menu list works (E11) but the recipe picker shows "Could not load ingredients" until PTY-5 merges.
- The layout has no styles stack (see above). `app.css`, `ui.js`, `api.js`, `units.js` and the components were not edited, no design-system gap found.
- The shared confirm dialog is unused here: nothing on these pages is irreversible.
- Not verified in a browser (none available to this builder): Alpine runtime behaviour, dark mode, the 820 px layout, the drawer transition, keyboard use of the picker. Server-rendered shells and JS syntax (`node --check`) were checked.

Manual QA script for Mohamad (run after PTY-5 is merged, `php artisan migrate:fresh --seed`, light and dark):

1. QA-1: `/ingredients` > Add ingredient "Lettuce", unit Grams. It appears with `0 g`, row flashes. Add "lettuce" again: inline "already exists" error, nothing created. `/suppliers` > Add supplier "Fresh Farms": appears. Try phone `abc`: inline error.
2. Ingredients extra: scroll or press "Load more" with more than 25 rows; open History on Beef (404 shows the friendly message until PTY-10); Edit an ingredient with stock: the unit select is disabled with the explanation; type `5.555` in over-tolerance (error), `5.5` and cap `2` kg then save: row shows `+5.5% / -...%, max +2 kg`; Edit again, "Use default": row shows "default".
3. QA-2: `/menu` > Add menu item "Cheese Burger", add Beef 150 g, Bun 1, Cheese 40 g: card shows 3 lines and "Sellable". In the editor try the same ingredient twice: the second row cannot pick it ("already in this recipe"). Type `2.5` grams: "Grams must be a whole number" and Save is refused. Switch a line to kg, enter `1.1`, save with a limit error from the server (or a value above 1,000,000 g): message appears in kg. Edit recipe: toast "Recipe updated, applies to future sales."
4. Keyboard: Tab through the picker, ArrowDown, Enter picks, Escape closes the list first and the dialog second. Double-click Save: only one request (button disabled with spinner).
