# Testing

## Strategy

Prove the domain, not the framework. The grading criterion is "tests on the parts that matter most, not just the easy ones".

- **Feature tests through the HTTP API** for every use case. They exercise validation, the service and the database together, with no mocks of the domain.
- **Unit tests** only where a piece of logic stands alone: the transition map in `PurchaseOrderStatus`.
- Fresh in-memory SQLite per test (`RefreshDatabase`). Factories for setup. Each test builds exactly the data it needs.
- **No filler.** Every test must fail if the logic it covers is deleted. The reviewer checks this per ticket.
- No tests for framework behaviour (that Eloquent saves a row, that a getter returns a field).

Run: `php artisan test` (or `vendor/bin/pest`). Style: `vendor/bin/pint --test`.

## The tests that matter (priority order)

| # | Test | Proves | Ticket |
|---|---|---|---|
| T1 | Selling 2 Classic Burgers lowers beef by 300, bun by 2, cheese by 40 | Recipe explosion arithmetic | PTY-9 |
| T2 | A partial delivery raises stock by what arrived, leaves the right outstanding, and does not close the order | Partial receiving | PTY-8 |
| T3 | A second delivery completing the order closes it | Auto-close | PTY-8 |
| T4 | Every invalid transition is rejected with 422 (dataset of all pairs) | State machine | PTY-7 |
| T5 | Delivery against a draft or closed order is rejected, and stock does not move | Operation guards | PTY-8 |
| T6 | Editing lines of a sent order is rejected | Operation guards | PTY-7 |
| T7 | A sale below zero is accepted and the ingredient shows negative | Q-001 decision | PTY-9 |
| T8 | Interleaved deliveries and sales give an exact on-hand, equal to a hand-computed total | Ledger design | PTY-10 |
| T9 | Over-delivery within 5% is accepted (stock rises by the full amount); beyond it is rejected and nothing from that delivery is saved; limit rounds down | Q-002, atomicity | PTY-8 |
| T10 | A repeated `pos_reference` does not move stock twice | Q-005 idempotency | PTY-9 |
| T11 | Open orders list shows correct outstanding per line after partial deliveries | Visibility | PTY-10 |
| T12 | Validation errors return 422 with field messages (representative, not exhaustive) | API contract | PTY-5, PTY-6 |
| T13 | Unit of an ingredient with movements cannot be changed | History integrity | PTY-5 |
| T14 | API responses carry `Cache-Control: no-store` | Freshness | PTY-10 |

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
