# Flows

Every flow end to end: how data enters, what the system does with it, what it stores, and what comes out. Builders implement these steps. Reviewers check the code against "Rows written" and "Error paths". Endpoint ids (E-n) are in [api.md](api.md), field rules in [validation.md](validation.md).

Template for each flow:
**Actor / screen** · **Request** · **Validation** · **Steps** (inside one transaction unless noted) · **Rows written** · **Audit** · **Log** · **Response** · **UI next** · **Error paths**

---

## Catalogue

### F1 Create ingredient
- **Actor / screen:** Manager, Ingredients, "Add ingredient" dialog
- **Request:** E3 `POST /ingredients` `{ "name": "Lettuce", "unit": "g" }`, header `X-Patty-Channel: ui`
- **Validation:** validation.md, Ingredients
- **Steps:** 1. `CatalogService::createIngredient` creates the row and a ULID.
- **Rows written:** ingredients +1, activity_log +1 (created)
- **Audit:** `created` on Ingredient (LogsActivity)
- **Log:** `catalog` info "Ingredient created"
- **Response:** 201, Ingredient with `on_hand: 0`, `incoming: 0`
- **UI next:** toast "Lettuce added"; the row appears at the top of the list with an enter animation
- **Error paths:** duplicate name (422 `validation_failed`, `errors.name`); bad unit (422)

### F2 Create supplier
- **Request:** E8 `POST /suppliers` `{ "name": "Fresh Farms", "email": "...", "phone": "..." }`
- **Steps:** `CatalogService::createSupplier`
- **Rows written:** suppliers +1, activity_log +1
- **Response:** 201 Supplier. **UI next:** toast, row appears. **Error paths:** duplicate name, bad email, bad phone (422).

### F3 Create menu item with recipe
- **Actor / screen:** Manager, Menu & Recipes, "Add menu item"
- **Request:** E12 `{ "name": "Classic Burger", "recipe": [ {beef,150}, {bun,1}, {cheese,20} ] }`
- **Steps:** 1. Create the menu item. 2. Create the recipe lines. Both in one transaction.
- **Rows written:** menu_items +1, recipe_lines +3, activity_log +2 (created, `recipe.replaced` with the new lines)
- **Log:** `catalog` info
- **Response:** 201 Menu item with recipe, `is_sellable: true`
- **Error paths:** duplicate ingredient in recipe (422 `distinct`); quantity 0 or decimal (422); unknown ingredient ULID (422)

### F4 Replace a recipe
- **Request:** E15 `PUT /menu-items/{id}/recipe` `{ "lines": [...] }`
- **Steps:** 1. Read the old lines. 2. Delete them. 3. Insert the new lines. 4. Audit with old and new. One transaction.
- **Rows written:** recipe_lines -N +M, activity_log +1 (`recipe.replaced`, properties `{ old: [...], new: [...] }`)
- **Important:** past sales are untouched. Their movements were written at sale time (Q-007).
- **Response:** 200 Menu item. **UI next:** recipe editor shows the saved lines; toast "Recipe updated, applies to future sales".

## Purchasing

### F5 Draft a purchase order
- **Actor / screen:** Manager, Purchase Orders, "New order"
- **Request:** E17 `{ "supplier_id": "...", "lines": [ { "ingredient_id": beef, "quantity_ordered": 3000 } ] }`
- **Steps:** 1. Take the next document number (`DocumentNumber::next('PO')`, with a row lock on `document_sequences`). 2. Create the PO (status `draft`). 3. Create the lines.
- **Rows written:** purchase_orders +1, purchase_order_lines +N, document_sequences updated, activity_log +1 (`purchase_order.created`)
- **Log:** `purchasing` info "PO-2026-0001 created"
- **Response:** 201 Purchase order, `allowed_actions: ["edit_lines", "send", "delete"]`
- **UI next:** navigate to the PO detail page

### F6 Edit draft lines
- **Request:** E19 `PUT /purchase-orders/{id}/lines`
- **Steps:** 1. Lock the PO row. 2. Status must be draft, else `OrderNotEditable`. 3. Replace the lines.
- **Rows written:** purchase_order_lines replaced, activity_log +1 (`purchase_order.lines_updated`, old and new)
- **Error paths:** PO already sent (409 `order_not_editable`; the UI shows a notice and refreshes)

### F7 Send a purchase order
- **Request:** E20 `POST /purchase-orders/{id}/send` (no body). The UI first shows a confirm: "Send PO-2026-0001 to Al-Mashreq Meats? Lines can't be changed after this."
- **Steps:** 1. Lock the PO. 2. `transitionTo(sent)` checks the transition map and sets `sent_at`.
- **Rows written:** purchase_orders status and sent_at updated, activity_log +1 (`purchase_order.sent`, plus the field change)
- **Log:** `purchasing` info
- **Response:** 200, `status: "sent"`, `allowed_actions: ["receive"]`
- **Error paths:** not draft (409 `invalid_transition`: "Cannot move PO-2026-0001 from sent to sent")

### F8 Partial delivery
- **Actor / screen:** Manager, PO detail, "Receive delivery" dialog, prefilled with the outstanding quantities and showing each line's limit
- **Request:** E23 `{ "lines": [ { "purchase_order_line_id": beefLine, "quantity": 2400 } ], "note": "Driver Sami" }`
- **Steps (one transaction):**
  1. Lock the PO row (`lockForUpdate`).
  2. Status must be sent or received, else `CannotReceive`.
  3. For each line: `received_so_far + qty <= intdiv(ordered x 105, 100)`, else `OverDelivery` (nothing saved).
  4. Take the next GRN number.
  5. Create the delivery and its delivery lines.
  6. For each delivery line: `StockLedger::record(+qty, delivery, line)`.
  7. If the status is sent, move to received.
  8. If every line's outstanding is 0, move to closed (not in this flow).
  9. Audit `delivery.recorded`; log.
- **Rows written:** deliveries +1, delivery_lines +1, stock_movements +1 (+2400 beef), purchase_orders status sent to received, activity_log +2 (`delivery.recorded`, status change), document_sequences updated
- **Log:** `purchasing` info "GRN-2026-0001 recorded on PO-2026-0001"; `stock` info per movement
- **Response:** 201 Delivery, and the updated PO (`status_label: "Partially received"`, outstanding beef 600, `progress_percent: 80`)
- **UI next:** confirm dialog ("Add 2,400 g Beef to stock?"), then a toast; the progress bar animates to 80%; the dashboard (in any tab) shows beef up and incoming down on its next refresh
- **Error paths:**
  - draft or closed PO: 409 `cannot_receive`
  - over the limit: 422 `over_delivery`
  - line from another PO: 422
  - `received_at` before `sent_at`: 422

### F9 Completing delivery (auto-close)
- As F8, but after step 6 every line has outstanding 0, so step 8 moves the order from received to closed in the **same transaction** and sets `closed_at`.
- **Rows written (extra):** activity_log +1 (`purchase_order.closed`)
- **Response:** the PO with `status: "closed"`, `allowed_actions: []`. **UI next:** status pill morphs to Closed; the PO leaves the open-orders list.
- A single delivery covering everything goes sent to received to closed in one transaction.

### F10 Over-delivery rejected
- **Request:** E23 with beef 800 when ordered 3000, received 2400, limit 3150 (2400 + 800 = 3200)
- **Steps:** step 3 throws `OverDelivery` and the transaction rolls back.
- **Rows written:** none. No delivery, no movement, no audit row.
- **Log:** `purchasing` notice "Over-delivery rejected" with the line, attempted total and limit
- **Response:** 422 `over_delivery`, `errors["lines.0.quantity"]`. **UI next:** inline error under the beef line; nothing else changes.

### F11 Short-close
- **Actor:** Manager, PO detail, "Close (short)". Confirm: "Close PO-2026-0002 with 400 g Beef never delivered? Stock is not affected."
- **Request:** E21. **Steps:** lock; status must be received; `transitionTo(closed)` with `short_closed = true`.
- **Rows written:** purchase_orders updated, activity_log +1 (`purchase_order.short_closed`). **No stock movement.**
- **Response:** 200. **UI next:** Closed pill with a "Short" tag; the remaining quantity is shown as "Not delivered". Incoming drops by the remaining quantity.
- **Error paths:** draft or sent (409 `invalid_transition`)

### F12 Delete a draft
- **Request:** E22. **Steps:** status must be draft; delete the PO and its lines.
- **Rows written:** purchase_orders -1, purchase_order_lines -N, activity_log +1 (`purchase_order.deleted`, which survives the delete). The document number is not reused.
- **Error paths:** not draft (409)

## Sales (POS)

### F13 POS sale
- **Actor:** the POS system (or the POS Simulator page, channel `pos`)
- **Request:** E25 `{ "menu_item_id": classic, "quantity": 6, "pos_reference": "till-1:000101" }`, plus `X-POS-Key` if configured
- **Steps:**
  1. Middleware checks the POS key (if set) and the rate limit.
  2. If `pos_reference` exists, go to F15 or F16.
  3. Begin the transaction.
  4. Load the recipe; if empty, `MenuItemNotSellable`.
  5. Take the next SALE number.
  6. Create the sale.
  7. For each recipe line: `StockLedger::record(-(qty x sale qty), sale)`.
  8. Audit `sale.recorded`.
  9. Commit.
  10. If the insert hits the unique `pos_reference` index (a concurrent duplicate), catch it and treat it as F15 or F16.
- **Rows written:** sales +1, stock_movements +3 (beef -900, bun -6, cheese -120), activity_log +1, document_sequences updated
- **Log:** `pos` info "SALE-2026-000001 Classic Burger x6"; `stock` info per movement
- **Response:** 201 Sale with `deductions` and `on_hand_after`
- **UI next (Simulator):** the deductions animate in; the dashboard updates on its next refresh
- **Error paths:**
  - bad or missing key: 401
  - no recipe: 422 `menu_item_not_sellable`
  - quantity 0 or above 1,000: 422
  - unknown item: 422
  - too many requests: 429

### F14 POS sale driving stock negative
- Same as F13. Stock is never checked before deducting (Q-001).
- **Extra:** `StockLedger` logs a `stock` **warning** "Cheese is negative: -20 g"
- **Response:** 201; the cheese deduction has `is_negative: true`. **UI next:** the Simulator highlights the negative line; the dashboard shows Cheese in danger colour with "Negative", and the negative KPI goes up.

### F15 POS replay (same reference, same payload)
- **Steps:** find the existing sale by `pos_reference`; `menu_item_id` and `quantity` match.
- **Rows written:** none except activity_log +1 (`sale.replayed`)
- **Log:** `pos` notice "Replay of till-1:000101"
- **Response:** **200** with the original sale, `replayed: true`. Stock is unchanged.

### F16 POS idempotency conflict (same reference, different payload)
- **Steps:** an existing sale is found, but the item or quantity differs. Throw `IdempotencyConflict`.
- **Rows written:** none
- **Log:** `pos` **warning** "pos_reference till-1:000101 reused with a different payload"
- **Response:** 409 `idempotency_conflict`. The POS has a bug, and we surface it instead of hiding it.

## Visibility

### F17 Dashboard refresh cycle
- **Screen:** Dashboard. On load: E28 (KPIs), E27 (stock, page 1), E16 `?status=open` (page 1), each with a skeleton until it arrives.
- **Refresh:** every 10 s while the tab is visible; immediately on `visibilitychange` back to visible and on `pageshow` (including back/forward navigation). Only the pages already loaded are refetched, so scroll position is kept. Values that changed flash briefly. "Updated N s ago" resets.
- **Never stale:** every response is `no-store`, so the back button cannot show a cached page (D-006).
- **Rows written:** none (read only)

### F18 Ingredient history
- **Screen:** Ingredients, then a row, then the history drawer. E6, newest first, 25 per page, lazy-loading more on scroll.
- Each row shows reason, delta, `balance_after` (window function) and the source document (GRN or SALE number, linked).
- **Check:** `balance_after` of the newest movement equals `on_hand`.

### F19 Activity view
- **Screen:** Activity (global) or the PO detail panel (filtered). E29, newest first, lazy loading.
- Each entry shows a description, the channel tag (UI / POS / API) and the time in the viewer's timezone.

### F20 Reset demo data (local only)
- **Screen:** Dashboard intro banner, "Reset demo data". Confirm: "This erases everything and restores the demo. Continue?"
- **Request:** E30. The route exists only when `APP_ENV=local`.
- **Steps:** `Artisan::call('migrate:fresh', ['--seed' => true])`. Not in a transaction, because the database is rebuilt. The seeder runs through the real services (D-027).
- **Response:** 200. **UI next:** toast, then a full reload.
- **Outside local:** 404 (the route is not registered).

---

## A day at Patty (the worked scenario, and test T8)

Classic Burger = Beef 150 g, Bun 1, Cheese 20 g. Everything starts at zero. Every number below is asserted by the T8 test.

| Time | Event | Beef | Bun | Cheese | Incoming beef / bun / cheese | Order states |
|---|---|---|---|---|---|---|
| 08:00 | PO-A beef 3000 g, PO-B bun 40, PO-C cheese 500 g, all sent | 0 | 0 | 0 | 3000 / 40 / 500 | A sent, B sent, C sent |
| 09:00 | GRN-1 on PO-A: beef 2400 | 2400 | 0 | 0 | 600 / 40 / 500 | A partially received |
| 09:30 | GRN-2 on PO-B: bun 40 | 2400 | 40 | 0 | 600 / 0 / 500 | B **closed** (auto) |
| 10:00 | GRN-3 on PO-C: cheese 300 | 2400 | 40 | 300 | 600 / 0 / 200 | C partially received |
| 12:00 | Sale x6 (till-1:0001) | 1500 | 34 | 180 | 600 / 0 / 200 | |
| 12:30 | Sale x4 (till-1:0002) | 900 | 30 | 100 | 600 / 0 / 200 | |
| 13:00 | Sale x6 (till-1:0003) | 0 | 24 | **-20** | 600 / 0 / 200 | cheese flagged Negative |
| 14:55 | GRN on PO-A: beef 800. Rejected: 2400 + 800 = 3200 > 3150 limit | 0 | 24 | -20 | 600 / 0 / 200 | unchanged, nothing saved |
| 15:00 | GRN-4 on PO-A: beef 630 (total 3030, within 5%) | 630 | 24 | -20 | 0 / 0 / 200 | A **closed**, over-received 30 |
| 15:30 | GRN-5 on PO-C: cheese 200 | 630 | 24 | 180 | 0 / 0 / 0 | C **closed** |
| 16:00 | POS retries till-1:0003 (same payload) | 630 | 24 | 180 | 0 / 0 / 0 | replay, nothing moves |
| 16:05 | POS sends till-1:0003 as x2 (different payload) | 630 | 24 | 180 | 0 / 0 / 0 | 409, nothing moves |
| 17:00 | Sale x3 (till-1:0004) | **180** | **21** | **120** | 0 / 0 / 0 | |

**Check by movements:**
- Beef: +2400 -900 -600 -900 +630 -450 = **180**
- Bun: +40 -6 -4 -6 -3 = **21**
- Cheese: +300 -120 -80 -120 +200 -60 = **120**

**Counts at close:** 5 deliveries (GRN-1 to GRN-5), 4 sales (SALE ...0001 to ...0004), and stock movements = 5 delivery lines + 4 sales x 3 ingredients = **17**. One over-delivery and one idempotency conflict were rejected, with no rows written.
