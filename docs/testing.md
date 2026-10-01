# Testing

## Strategy

Prove the domain, not the framework. The grading criterion is "tests on the parts that matter most, not just the easy ones".

- **Feature tests through the HTTP API** for every use case. They exercise validation, the service and the database together, with no mocks of the domain.
- **Unit tests** only where a piece of logic stands alone: the transition map in `PurchaseOrderStatus`.
- Fresh in-memory SQLite per test (`RefreshDatabase`). Factories for setup. Each test builds exactly the data it needs.
- **No filler.** Every test must fail if the logic it covers is deleted. The reviewer checks this per ticket.
- No tests for framework behaviour (that Eloquent saves a row, that a getter returns a field).

Run: `php artisan test` (or `vendor/bin/pest`). Style: `vendor/bin/pint --test`. Opt-in browser suite (PTY-17, needs Node): `composer test:browser`.

Log lines are not tested, since asserting log text would be filler. The single exception is the negative-stock warning, which is a business signal (PTY-4).

## The tests that matter (priority order)

| # | Test | Proves | Ticket |
|---|---|---|---|
| T1 | Selling 2 Classic Burgers lowers beef by 300, bun by 2, cheese by 40 | Recipe explosion arithmetic | PTY-9 |
| T2 | A partial delivery raises stock by what arrived, leaves the right outstanding, and does not close the order | Partial receiving | PTY-8 |
| T3 | A second delivery completing the order closes it | Auto-close | PTY-8 |
| T4 | Every invalid transition is rejected with 409 `invalid_transition` (dataset of all pairs) | State machine | PTY-7 |
| T5 | Delivery against a draft or closed order is rejected, and stock does not move | Operation guards | PTY-8 |
| T6 | Editing lines of a sent order is rejected | Operation guards | PTY-7 |
| T7 | A sale below zero is accepted and the ingredient shows negative | Q-001 decision | PTY-9 |
| T8 | "A day at Patty" (flows.md): every step of the worked day asserts on-hand and incoming; final beef 180, bun 21, cheese 120 | Ledger design | PTY-10 |
| T9 | Over-delivery within 5% is accepted (stock rises by the full amount); beyond it is rejected and nothing from that delivery is saved; limit rounds down | Q-002, atomicity | PTY-8 |
| T10 | A repeated `pos_reference` does not move stock twice | Q-005 idempotency | PTY-9 |
| T11 | Open orders list shows correct outstanding per line after partial deliveries | Visibility | PTY-10 |
| T12 | Validation errors return 422 with field messages (representative, not exhaustive) | API contract | PTY-5, PTY-6 |
| T13 | Unit of an ingredient with movements cannot be changed | History integrity | PTY-5 |
| T14 | API and page responses carry `Cache-Control: no-store` (covered by T17) | Freshness | PTY-16, PTY-12 |
| T15 | Envelope contract: success shape, 201, 422 with field errors, 409 with code, 404, 500 without details | API contract (D-019) | PTY-16 |
| T16 | Incoming = outstanding on open POs; zero after short-close; drafts excluded | Run-out gap (D-020) | PTY-10 |
| T17 | Security headers, `no-store` and `X-Request-Id` on every response | HTTP layer (D-024) | PTY-16 |
| T18 | Audit entries carry the channel. A rejected delivery leaves no audit row. Recipe replace stores old and new lines. | Audit (D-021) | PTY-16, PTY-6, PTY-8 |
| T19 | Raw SQL update or delete on `stock_movements` is aborted by the database | DB guard (D-024) | PTY-3 |
| T20 | POS endpoint answers 429 beyond the rate limit | HTTP layer (D-024) | PTY-9 |
| T21 | Validation datasets: one per FormRequest, every row of validation.md, including the G3 quantity dataset (`"1.5"`, `"abc"`, `"1e3"`, -1, 0, null, true) | Input layer | PTY-5 to PTY-9, PTY-16 |
| T22 | POS key: open when unset; 401 when set and the header is missing or wrong; 201 with the right key | D-028 | PTY-16, PTY-9 |
| T23 | Idempotency conflict: same reference with a different payload gives 409; sales and stock unchanged | D-029 | PTY-9 |
| T24 | Demo reset is 404 outside the local env, and re-seeds in local | D-027, S13 | PTY-22 |
| T25 | No integer id in any response (`assertNoIntegerIds()` in every feature test); a numeric id in a URL gives 404; document numbers are sequential per type and year | D-031, S9 | PTY-3, PTY-16, all |

## Manual QA checklist (owner runs per ticket)

Start from `php artisan migrate:fresh --seed` and `php artisan serve`.

### QA-1 Ingredients and suppliers
- [ ] Create ingredient "Lettuce", unit g. It appears in the list with 0 g.
- [ ] Create a duplicate "lettuce". An inline error appears and nothing is created.
- [ ] Create supplier "Fresh Farms". It appears in the list.

### QA-2 Recipes
- [ ] Create "Cheese Burger" with beef 150 g, bun 1, cheese 40 g. Its recipe shows 3 lines.
- [ ] Adding the same ingredient twice is blocked with a message.

### QA-3 Purchase orders
- [ ] Draft a PO to Fresh Farms: beef 1000 g, buns 10. Status shows Draft.
- [ ] Edit a line while in draft. It saves.
- [ ] Send it. Status shows Sent and line editing controls are gone.
- [ ] No control offers an invalid move (for example, no "Receive" button on a draft).

### QA-4 Receiving
- [ ] Receive beef 600 g only. Stock of beef rises by 600. The PO shows Partially received, outstanding beef 400 and buns 10.
- [ ] Try to receive beef 500 (total 1100, above the 1050 limit). An error appears and stock is unchanged.
- [ ] Receive beef 430 (within 5%) and buns 10. Beef rises by 430, the PO shows Closed with beef over-received 30, and it disappears from open orders.
- [ ] On another partially received PO, short-close it. It shows Closed (short) with the missing quantity as not delivered, and stock is unchanged.

### QA-5 POS sale
- [ ] In the POS simulator, sell 2 Classic Burgers. Beef falls by 300, bun by 2, cheese by 40.
- [ ] Sell enough to push cheese below zero. The sale succeeds and cheese shows Negative in red.
- [ ] Send the same POS reference twice. Stock moves only once.

### QA-6 Visibility
- [ ] Keep the dashboard open in one tab and record a sale in another. The dashboard updates within about 10 s without a reload, and the "updated" stamp resets.
- [ ] Navigate away and press the browser Back button. The numbers are current, not a cached page.
- [ ] Open an ingredient's history. The movements sum to the displayed on-hand.

### QA-7 User experience (design.md user satisfaction rules)
- [ ] Double-click "Record delivery". Only one delivery is recorded, and the button showed it was busy.
- [ ] Recording a delivery, sending a PO and short-closing each ask for confirmation with a plain summary.
- [ ] The delivery form opens prefilled with the outstanding quantities and shows each line's limit.
- [ ] An over-limit quantity shows "Beef: 1,100 g is above the 1,050 g limit" next to the field.
- [ ] Open the PO in two tabs, send it in one, then try to edit it in the other. A notice explains it was already sent, and the view refreshes.
- [ ] Empty lists explain what to do and offer the action.
- [ ] 12500 g displays as `12,500 g` with a `12.5 kg` hint.
- [ ] At 820 px width everything is usable. Tab through a page and focus is always visible. With reduced motion on, nothing animates.
- [ ] The dashboard shows Incoming next to On hand. After a partial delivery, incoming falls and on hand rises by the same amount.
- [ ] The PO detail Activity panel shows created, sent and delivery recorded, each with its channel.

### QA-8 First visit (no-login experience)
- [ ] Fresh `composer setup`. Opening `/` shows the intro banner, the "Restaurant manager" chip and the Try-it card. Skeletons appear briefly, then real data.
- [ ] The seeded data shows a closed, a partially received, a sent and a draft PO, and Cheese as Negative.
- [ ] Each of the five Try-it steps ticks after doing it.
- [ ] "Reset demo data" asks for confirmation, then restores the seeded state.
- [ ] No URL anywhere contains a plain number id. Purchase orders show `PO-2026-....` numbers.
- [ ] Switch the PC's timezone. Times in the UI follow it after reload.
