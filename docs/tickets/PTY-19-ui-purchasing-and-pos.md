# PTY-19 UI: Purchasing and POS: Purchase Orders, Receive delivery, POS Simulator

| Field | Value |
|---|---|
| Type | Story |
| Phase | 3b Build frontend |
| Status | To Do |
| Weight | M |
| Builder | Sonnet 5.5 |
| Reviewer | Opus 5.5 (code) + Opus 5.5 design reviewer with Claude in Chrome |
| Branch | `PTY-19-ui-purchasing-and-pos` |
| Release | v0.4.0 |
| Depends on | PTY-11, and the backend endpoints it uses |

Split out of PTY-12 by Q-012 (D-043). Built in code from the approved design system.

## Scope

Purchase Orders (list with status filter, create, PO detail with line progress, allowed actions, activity panel, Receive dialog prefilled with limits and over/under tags, short-close, delete draft) and the POS Simulator (item picker, quantity, sell, deductions with on-hand after, negative highlight, resend last to show a replay or conflict).

## Contract and flows

Endpoints: [api.md](../api.md) E16 to E26, E29. Flows: [flows.md](../flows.md) F5 to F16, F21. Behaviour rules U1 to U12 and the unit conversion table: [ui.md](../ui.md). User satisfaction rules: [design.md](../design.md#user-satisfaction-rules).

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

Run QA-3 to QA-5 from [testing.md](../testing.md) in the browser, light and dark.

## Builder notes

### What was built, in order
1. Purchase Orders list (`/purchase-orders`): status tabs (`?status=`), skeleton, empty, error, lazy-load plus "Load more", 10 s poll that refetches the loaded pages in place.
2. New order (`/purchase-orders/new`): supplier and ingredient pickers (E7, E2), one `x-quantity-input` per row whose unit follows the ingredient, 422 inline per line in the typed unit.
3. PO detail (`/purchase-orders/{ulid}`): lines with limits and tags, only the buttons in `allowed_actions`, inline line editing (E19), confirms for send, delete draft and close short, Receive dialog (E23), Activity panel (E29).
4. POS Simulator (`/pos`): sell as channel `pos` with a generated `pos_reference`, deductions with on-hand-after and a Negative pill, "Resend last sale" (replay) and "Resend with quantity +1" (409), recent sales (E26).
5. `tests/Feature/Web/PurchasingPagesTest.php`.

### Decisions and notes
- **received_at is left out of the request unless the manager ticks "Set a different time"** (PTY-8 review: a fast browser clock sending "now" would 422). A picked time is checked in the browser (not in the future, not before `sent_at`) and sent as `toISOString()` (UTC with `Z`).
- Receive dialog: the manager's "Review delivery" opens the shared confirm dialog with the plain summary and a `run`, so the confirm button stays busy until E23 returns (a double-click records once; verified in Chrome). A 422 is shown on the right row and marked `handled`, so the confirm closes without repeating it. A 409 closes both and refreshes. The page adopts the `purchase_order` returned by E23, with no second request.
- Zero rows are allowed in the dialog (completed lines prefill 0) and simply not sent. The server names a line by its position in what was sent, so the page remembers which rows were sent to place the error.
- The limit shown per row is `max_receivable - quantity_received` (the cap is on the total, D-035).
- The API `status_label` is shown as is, except a short-closed order, which reads "Closed (short)". Only the tone comes from `Patty.statusMeta`.
- `x-quantity-input` takes `unit` at render time, so `pages/purchasing/quantity-by-unit.blade.php` renders the g, ml and piece variants behind `x-if` and keeps the one matching the ingredient.
- Routes: ULIDs from this API are lowercase, so the constraint is Crockford base32 in either case.
- Activity (E29) is not on this branch's backend yet (404): the panel shows "Activity is not available right now." with Retry. Verified.

### Deviations and gaps
- Added `public/js/pages/purchasing-shared.js` (fetch-all, label, quantity helpers) and `resources/views/pages/purchasing/head.blade.php`, which are not in the ownership list. They only avoid copying the same code into four pages.
- The layout has no `styles` stack and `$asset` is not visible to child views, so the page stylesheet is pushed into the `scripts` stack (it sits in `<head>`) with the same mtime cache-buster.
- `x-quantity-input` has a static `label`, so in the Receive dialog the label ("Quantity received") is visually hidden and the ingredient name beside it carries the meaning. A dynamic label prop would fix this.
- Not verified: dark mode, 820 px layout, keyboard-only pass, the 401 (POS key) and 429 notices, short-close and delete confirms, the over-received / under-delivered / not-delivered tags, lazy-load with more than 25 rows. Verified by driving Chrome through scripts against a local server: list route, create and edit a draft, send confirm text, partial receive with the 422 re-expressed in kg, completing delivery (double-click recorded once), POS sell, replay and 409 conflict.

### Manual QA script (QA-3, QA-4, QA-5 in testing.md; run in light and dark)
1. `/purchase-orders/new`: supplier Amman Bakery Supply, Beef 1 kg and Burger bun 10. Save. You land on the detail page, status Draft, buttons Edit lines, Delete draft, Send order only.
2. Edit lines: change beef to 1.2 kg, Save lines. Table shows 1.2 kg.
3. Send order: the dialog says "Lines can't be changed after this." Confirm. Edit and Delete are gone, Receive delivery appears.
4. Receive delivery: the dialog opens prefilled with 1.2 kg and 10 pcs and the limits. Set beef 0.6 kg, buns 0. Review, confirm. Status Partially received, beef outstanding 600 g, buns 10 pcs, progress bar moved. Double-click the confirm button: only one delivery.
5. Receive again with beef 0.7 kg: error under the row in kg ("1.3 kg is above the 1.26 kg limit"), stock unchanged.
6. Tick "Set a different time", pick a time before the order was sent: inline error. Untick it.
7. Receive beef 0.66 kg and buns 10: the PO becomes Closed, beef shows "Over-received 60 g" or within the limit as computed.
8. On a second partially received PO choose Close short: the dialog lists what is not delivered; after confirming the pill reads "Closed (short)" and lines show "Not delivered".
9. A draft: Delete draft, confirm, you return to the list.
10. `/purchase-orders/<any 26-char valid id>` that does not exist shows "Purchase order not found". A short value gives the 404 page.
11. `/pos`: sell 2 of an item. Deductions match the recipe times 2. Sell until an ingredient goes below zero: the sale succeeds and the row shows Negative.
12. Resend last sale: badge "Replayed, stock not moved again", on-hand-after unchanged. Resend with quantity +1: the refusal notice appears, stock unchanged. Recent sales shows one row per accepted sale.
13. At 820 px and in dark mode repeat steps 4 and 11; tab through the Receive dialog.

## Reviewer findings

Opus review of the 6 commits on `PTY-19-ui-purchasing-and-pos` against the ACs, ui.md U1 to U12, the design.md user satisfaction rules, api.md and the PTY-18 lessons. Full suite green (336 tests). Mutations: `x-html` added, `/pos` route removed, `pos` channel removed, ULID constraint loosened or removed, `innerHTML` in the shared script: all caught. An unescaped `{!! !!}` added to a page survived (finding 4).

PTY-18 lessons, checked and not repeated here: list rows are keyed by real ids (activity rows, which have none, use an index key); 409 is matched on `idempotency_conflict` on the POS; the receive dialog sends only what the user confirmed (derived prefill is reviewed in the summary, zero rows are left out); dialog state is rebuilt on every opening; no picker submits a form on Enter; the 422 row mapping uses the positions actually sent (`recv.sent`), so it stays correct when zero rows are omitted.

| # | Severity | File:line | Finding | Resolution |
|---|---|---|---|---|
| 1 | Medium | resources/views/pages/purchase-order.blade.php:229, public/css/pages/purchasing.css:62 | Receive dialog accessibility: every row's input has the same accessible name, "Quantity received", and the ingredient name is not tied to it, so a screen reader hears "Quantity received" three times with no way to tell Beef from Buns. Make each `.rcv-line` a `fieldset` with the ingredient name as its `legend` (or `role="group" :aria-labelledby` pointing at the name), and reuse the existing `.visually-hidden` class instead of the copied rule. | open |
| 2 | Low | public/js/pages/purchase-orders.js:105 and :122, public/js/pages/pos.js:207 and :222 | Lazy-load and refresh can interleave. A refresh that started before a `loadMore` overwrites the list with N pages while `page` is already N+1, so a page of rows disappears until the next poll; offset pages also shift when a row is created between requests, giving duplicate ids in an `x-for` keyed by id. On the POS, the refresh after a sale returns early while a `loadMore` is in flight, so the new sale is missing from Recent sales for up to 10 s (the "refresh dropped while one is in flight" lesson). Remember a pending refresh and run it when the in-flight request ends, and drop ids already present when concatenating a page. | open |
| 3 | Low | public/js/pages/purchase-order.js:356 | `showModal()` runs before Alpine re-renders the rows (effects flush in a microtask), so initial focus lands on the previous opening's inputs, which are then removed, or on the "Set a different time" checkbox on first open. Open the dialog in `$nextTick` and focus the first quantity field. Verify with the keyboard. | open |
| 4 | Low | tests/Feature/Web/PurchasingPagesTest.php:76 | The S5 guard does not look for unescaped Blade output: adding `{!! request("x") !!}` to `pos.blade.php` passes the suite. Add `\{!!` to the pattern. | open |
| 5 | Low | public/js/pages/purchase-order.js:71 and :87 | `adopt` has no ordering guard. A poll GET that started before an action and returns after it puts the older order back on screen (for example "Send order" reappears after sending) until the next poll; a click then gets a 409 and recovers. Keep a counter bumped by every action and ignore poll responses that started before the latest action. | open |
| 6 | Low | public/js/pages/purchase-order.js:89 | On "Purchase order not found" the poll calls `load()` every 10 s, which hides the empty state behind the skeleton each time. Skip the poll while `notFound`. | open |
| 7 | Low | public/js/pages/pos.js:139 | The 401 and 429 notices on the POS page drop the request id that U7 asks for (the request is `silent`). Add `e.requestId` to the notice. | open |
| 8 | Nit | resources/views/pages/purchase-orders.blade.php:71, public/js/pages/purchase-order-new.js:135, public/js/pages/purchase-order.js:73 | URLs are built from raw ids. They are ULIDs, so this is safe today, but PTY-18 settled on `encodeURIComponent` for ids in paths; use it here too for one rule across pages. | open |
| 9 | Nit | public/js/pages/purchase-order-new.js:66, public/js/pages/purchase-order.js:265 | Changing to another ingredient with the same unit resets `line.mode` to kg or L while the mounted input keeps the unit the user chose, so a later 422 would be re-expressed in a different unit than the field shows. Only reset `mode` when the unit changes. | open |
| 10 | Nit | public/js/pages/purchase-order.js:195 | Short-close title reads "Close PO-2026-0002 with 1 not delivered?". Say "with 1 line not delivered" (plural aware). | open |
| 11 | Nit | public/js/pages/purchase-order-new.js:133 | `{ silent: false }` is the default; remove it. | open |
| 12 | Nit | resources/views/pages/purchasing/head.blade.php:9 | Accepted deviation: the page stylesheet goes into the `scripts` stack and `$v` repeats the layout's `$asset`. Once PTY-18 adds `@stack('styles')`, push the stylesheet there. `purchasing-shared.js` and the `head` partial are accepted: they remove real duplication across four pages and each is small enough to explain. | open |
