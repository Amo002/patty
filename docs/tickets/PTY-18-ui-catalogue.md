# PTY-18 UI: Catalogue: Ingredients, Suppliers, Menu and Recipes

| Field | Value |
|---|---|
| Type | Story |
| Phase | 3b Build frontend |
| Status | Done |
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
- **Shared list logic:** `public/js/pages/catalogue-common.js` exposes `Patty.pagedList(path, { keyOf })`: the four states (loading, empty, error, loaded), 25 per page, the IntersectionObserver sentinel plus a "Load more" button, `refresh(id)` (refetch the pages on screen, flash the changed row). All three pages and the history drawer spread it into their component. List requests are silent: the list shows its own error with the request id instead of a toast.
- **Assets partial:** `pages/catalogue/assets.blade.php` pushes the scripts to `scripts` and `catalogue.css` to the layout's `styles` stack (one line added to the layout head in review, F8).
- **Quantity input per unit:** the component fixes its unit at render time, so the recipe editor and the tolerance cap render one copy per unit (`g`, `ml`, `piece`) and `x-if` picks the one for the chosen ingredient. Recipe lines use `context="recipe"` (g and ml first, D-038).
- **Never lose a bad quantity:** an input showing an error holds `null`, so each page records `qtyError` and refuses to save until it is fixed, instead of sending "no value".
- **Tolerance:** percent text becomes basis points by string splitting (no `* 100`). On edit a field is sent only if the user changed it (the API shows effective values, so sending all three would write defaults as overrides); a touched empty field is an explicit `null`, and "Use default" sends `null` for all three. On create, empty fields are left out.
- **Paged list:** pass `keyOf` to de-duplicate rows across pages; E6 movements have no id so the drawer passes none. `node scripts/check-paged-list.js` checks that and the queued refresh.
- **Recipe save:** rename (E14) first when the name changed, then `PUT` the whole recipe (E15). If the second step fails the rename stays saved and the list is refreshed. An edit with zero lines is refused locally with a plain message.
- **Ingredients poll** every 10 s (`Patty.api.poll`) so on-hand tracks deliveries and sales; Suppliers and Menu refresh after their own changes only.

Assumptions and gaps:

- The history drawer assumes E6 returns newest first (api.md says "newest first" in the ticket, the shape does not state the order). If PTY-10 returns oldest first, change the ORDER BY there, not the UI. A 404 shows "History will appear here once stock movements are available".
- PTY-5 is merged, so the Ingredients, Suppliers and recipe picker endpoints work. Not yet exercised in a browser by the builder.
- `app.css`, `ui.js`, `api.js`, `units.js` and the components were not edited; the layout got only the one-line styles stack.
- The shared confirm dialog is unused here: nothing on these pages is irreversible.
- Not verified in a browser (none available to this builder): Alpine runtime behaviour, dark mode, the 820 px layout, the drawer transition, keyboard use of the picker. Server-rendered shells and JS syntax (`node --check`) were checked.

Manual QA script for Mohamad (run after PTY-5 is merged, `php artisan migrate:fresh --seed`, light and dark):

1. QA-1: `/ingredients` > Add ingredient "Lettuce", unit Grams. It appears with `0 g`, row flashes. Add "lettuce" again: inline "already exists" error, nothing created. `/suppliers` > Add supplier "Fresh Farms": appears. Try phone `abc`: inline error.
2. Ingredients extra: scroll or press "Load more" with more than 25 rows; open History on Beef (404 shows the friendly message until PTY-10); Edit an ingredient with stock: the unit select is disabled with the explanation; type `5.555` in over-tolerance (error), `5.5` and cap `2` kg then save: row shows `+5.5% / -...%, max +2 kg`; Edit again, "Use default": row shows "default".
3. QA-2: `/menu` > Add menu item "Cheese Burger", add Beef 150 g, Bun 1, Cheese 40 g: card shows 3 lines and "Sellable". In the editor try the same ingredient twice: the second row cannot pick it ("already in this recipe"). Type `2.5` grams: "Grams must be a whole number" and Save is refused. Switch a line to kg, enter `1.1`, save with a limit error from the server (or a value above 1,000,000 g): message appears in kg. Edit recipe: toast "Recipe updated, applies to future sales."
4. Keyboard: Tab through the picker, ArrowDown, Enter picks, Escape closes the list first and the dialog second. Double-click Save: only one request (button disabled with spinner).

## Reviewer findings

Code review by Opus 5.5, 2026-10-02, after merging `origin/develop` (PTY-5 endpoints now present) into the branch. Verdict: request changes for F1 to F4 (all small), then hand over to the design reviewer and Mohamad's click-through. The full suite passes (326 tests). Mutations: an `x-html` in a page, a removed `/menu` route and a broken page script include each fail the suite. An `insertAdjacentHTML` in a page script and a `{!! !!}` in a page view both pass (F7). Deviations: 1 (`Patty.pagedList`) accepted, apart from F1 and F2. 2 (stylesheet `<link>` in the body) is valid HTML (`stylesheet` is body-ok), but see F8. 3 (E6 order) is fine: PTY-10's ticket says newest first, and the drawer renders whatever order arrives. Only the subtitle assumes newest first. 4 (rename, then PUT) accepted, with a message (F10). 5 (one quantity input per unit) is accepted as the honest fit for a component whose unit is fixed in Blade. Only one copy renders per row, so the cost is a three-line `@foreach`. The simpler route is a follow-up that lets `quantityInput` take a reactive `:unit`. 6 (polling, refusing zero lines, blocking save on an input error) accepted. Security: API data reaches the DOM only via `x-text` and attribute bindings, no secrets, `X-Patty-Channel: ui` comes from api.js, and numbers are sent as integers. Commits are authored by the repo owner with no trailers. Scope is PTY-18 files plus the `routes/web.php` block.

| # | Severity | File:line | Finding | Resolution |
|---|---|---|---|---|
| F1 | High | public/js/pages/catalogue-common.js:61-63 | `loadMore()` de-duplicates on `item.id`, but E6 movements have no id (api.md "Movement (E6), with no id"). `known[undefined]` is set by the first row, so every row of page 2+ is filtered out (checked under node: 0 of 2 kept). History never shows more than 25 movements, and "Load more" does nothing while the sentinel quietly walks through every page. Fix: `pagedList(path, { key })` with a key function, or skip de-duplication when rows have no `id`. | Fixed. pagedList takes an optional keyOf; without it nothing is de-duplicated. The history drawer passes none, the other lists pass byId. scripts/check-paged-list.js (node) proves both. |
| F2 | Medium | public/js/pages/catalogue-common.js:73 | `refresh()` returns at once when a refresh or loadMore is already in flight. On Ingredients the 10 s poll is often in flight, and a poll that started before the POST committed returns the old list. The new or edited row then does not appear or flash until the next tick, which breaks "did it save?" (design rule). It can also happen on Suppliers and Menu while a page is loading more. Fix: remember a pending refresh (`this.again = changedId \|\| true`) and run it once the current one ends. | Fixed. A refresh requested while one runs queues exactly one follow-up (keeping the changed id), run when the first finishes. Covered by scripts/check-paged-list.js. |
| F3 | Medium | public/js/pages/ingredients.js:152 | `onConflict` runs for any 409, including the generic `conflict` (unique-index race, PTY-26). That locks the unit select and says "already has stock history" when the unit was never the problem. Check `e.code === 'unit_locked'` in the handler. | Fixed. A unit lock is applied only for code unit_locked; any other 409 just refreshes the list after api.js shows its notice. |
| F4 | Medium | public/js/pages/ingredients.js:84-88, 136-138 | E2 returns the effective tolerance (each field falls back to the unit default on its own, `Ingredient::effectiveTolerance`), with `source: "ingredient"` if any one field is overridden. When an ingredient with only a cap override is edited, the dialog fills all three fields with the effective values and PATCH sends them. The default percentages silently become per-ingredient overrides that no longer follow config. The same happens on a rename-only edit. Options: send tolerance fields only when the user changed them (compare with the values opened), or expose per-field source in E2. The first is UI-only and smallest. | Fixed. The edit form remembers what it opened with and sends a tolerance field only if changed; Use default sends null for all three. |
| F5 | Low | public/js/pages/ingredients.js:178-183, ingredients.blade.php:176,225 | `x-if="history"` stays true when a second ingredient's history is opened, so the inner DOM is not rebuilt and `history.watchEnd($el)` never runs for the new list. The observer still points at the first ingredient's list and loads its pages in the background, while the new list relies on the button. Set `history = null` in `closeHistory()` (and on the dialog's `close` event, for Escape) so the next open re-runs `x-init`. | Fixed. The drawer close event drops the history list and item, so the next ingredient builds a fresh list and observer. |
| F6 | Low | ingredients.blade.php:143-147 | The cap's quantity input is reused across dialog opens when the unit is the same. If a user types an invalid cap and then cancels, `form.cap` stays `null`, so the component's `base` watcher never fires. The next Add/Edit still shows the old text and its error while `form.capError` is `''`, and Save sends no cap. Reset it by keying the dialog content (for example a `formKey` counter in an `x-for`/`x-if`), or by having the component clear itself on a `reset` event. | Fixed. The cap quantity input is mounted fresh on every dialog open (x-if on capShow), so stale text and error cannot survive. |
| F7 | Low | tests/Feature/Web/CataloguePagesTest.php:53 | The S5 grep checks only `x-html` and `innerHTML`. Add `outerHTML`, `insertAdjacentHTML` and `{!!` to the needles. Both mutations pass today. | Fixed. The grep now covers x-html, innerHTML, outerHTML, insertAdjacentHTML and the Blade unescaped echo. |
| F8 | Low | resources/views/pages/catalogue/assets.blade.php:240 | A `<link rel=stylesheet>` in the body is valid, but PTY-19/20 will copy it, and each page then carries its own partial. Recommend adding a one-line `@stack('styles')` after `app.css` in `layouts/app.blade.php` in this ticket and `@push('styles')` here. It is a one-line, additive change to a shared file. | Fixed. One-line styles stack in the layout head (PTY-18 comment); the catalogue stylesheet is pushed to it from the assets partial. |
| F9 | Low | public/js/pages/ingredients.js:152,196; suppliers.js:196; menu.js:245,247 | URLs are built by concatenating the ULID without `encodeURIComponent`. ULIDs from our own API are `[0-9A-Z]{26}`, so this is not exploitable, but the brief asks for encoding. Wrap the id (or add a `Patty.api.path('/x/', id)` helper) for defence in depth. | Fixed. Every ULID in a request path goes through encodeURIComponent. |
| F10 | Low | public/js/pages/menu.js:242-248,264-267 | If the rename succeeds and the recipe PUT fails, the user sees only the recipe errors. The list refresh shows the new name behind the modal. Add a one-line notice ("Name saved. The recipe was not saved: fix the lines below.") when `originalName` changed in this attempt. | Fixed. When the rename saved but the recipe PUT failed, a warning toast says so with the server reason. |
| F11 | Low | ingredients.js:172; ingredients.blade.php:119 | The lock wording says "already has stock history", but D-044 also locks the unit once the ingredient is used in a recipe or PO line. Say "is already used in stock history, recipes or purchase orders". | Fixed. The lock text says used in stock history, recipes or purchase orders; the notice after a 409 uses the server message (D-044). |
| F12 | Low | menu.js:160-171 | Enter in the picker while the list is open with no match (or the list is closed) falls through and submits the whole form. Prevent Enter whenever the list is open. | Fixed. While the list is open Enter belongs to the picker (picks if there is a match) and never submits the form. |
| F13 | Info | ingredients.blade.php:55,89; suppliers.blade.php:75; menu.blade.php:73 | Inline `style="justify-content: center"` and `margin-left: auto`. Use a design-system utility or a `catalogue.css` class. `catalogue.css` is otherwise token-only (no hard-coded colours). The Unit column shows the raw `piece`; `unit_label` exists. | Fixed. The unit column and picker use unit_label with a fallback; inline styles moved to the load-more and skeleton-end classes in catalogue.css. |
| F14 | Info | docs/tickets/PTY-18-ui-catalogue.md (Builder notes) | The notes still say PTY-5 is not on this branch and the Ingredients page shows its error state. That is stale after the develop merge. Update them when F1 to F4 are fixed. | Fixed. Builder notes updated: PTY-5 is merged and the endpoints work; the styles stack and the review changes are described. |
