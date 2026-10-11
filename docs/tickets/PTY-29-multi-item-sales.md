# PTY-29 Multi-item POS sales (a ticket of one or more menu items)

| Field | Value |
|---|---|
| Type | Story |
| Phase | Post-release extension |
| Status | In progress |
| Weight | L |
| Builder | Sonnet 5.5 (three builders: backend, UI, docs) |
| Reviewer | Opus 5.5 |
| Branch | `PTY-29-multi-item-sales` |
| Release | v1.1.0 |
| Depends on | PTY-9 |

## Goal

Today a sale is one menu item times a quantity. A real till sends a ticket: 2 Classic Burger + 1 Fries under one reference. `POST /sales` must accept a ticket of one or more lines, deduct every line's recipe in one transaction, and the POS Simulator must build and sell such tickets.

Design preview approved by Mohamad: https://claude.ai/artifact/W57Mgf3wrPaM3eyW7TgaVd (the refund panel in it is PTY-30, not this ticket).

## Covers

FR-5, D-010, D-029, new D-048. Prepares PTY-30 (refunds).

## Contract (the three builders work to this; do not change it without the orchestrator)

### Data

- New table `sale_lines`: `id`, `sale_id` (FK sales, restrictOnDelete), `menu_item_id` (FK menu_items, restrictOnDelete), `quantity` (unsigned int), timestamps. Unique (`sale_id`, `menu_item_id`).
- `sales` loses `menu_item_id` and `quantity`. The migration first copies every existing sale into one `sale_lines` row, then drops the columns (drop the FK first).
- **Stock movements reference the `SaleLine`** (morph alias `sale_line`), one movement per (sale line, recipe line): `-(recipe quantity x line quantity)`, reason `sale`. Written in line order, then recipe-line id order. Nothing is summed in the ledger.
  - Why (D-048): a refund (PTY-30) of k of n units must put back exactly what that line took: `line delta / n x k`, an exact integer, correct even after a recipe change.
- The `sale` morph alias stays registered (old rows and the ledger tests use it).

### POST /api/v1/sales (E25)

Request:
```json
{
  "lines": [
    { "menu_item_id": "01JA...", "quantity": 2 },
    { "menu_item_id": "01JB...", "quantity": 1 }
  ],
  "pos_reference": "till-1:000873",
  "sold_at": "2026-10-11T13:05:00Z"
}
```
- `lines`: required, array, 1 to 50 items.
- `lines.*.menu_item_id`: required, string, ulid, exists (lowercased first, as today).
- `lines.*.quantity`: required, `integer:strict`, 1 to 1000.
- `pos_reference`, `sold_at`: unchanged rules.
- Duplicate menu items in one ticket are **merged** (quantities added) before anything else. After merging, a line above 1000 is a 422 on that line.
- The old body `{menu_item_id, quantity}` is no longer accepted (422 `lines` required).

Errors:
- 422 validation, keyed `lines`, `lines.N.menu_item_id`, `lines.N.quantity`. Messages: "Add at least one menu item to the sale.", "A sale can hold at most 50 different items.", "Line N: choose the menu item that was sold.", "Line N: that menu item does not exist.", "Line N: the quantity must be a whole number from 1 to 1,000." (N is 1-based in messages.)
- 422 `menu_item_not_sellable` if **any** item has no recipe. The whole ticket is refused: no sale, no lines, no movements, no SALE number used.
- 409 `idempotency_conflict`: the reference exists with different lines.
- 200 replay: the reference exists with the same lines. "Same" compares the merged lines as (menu item id, quantity) pairs **regardless of order**.

Response `data` (201 new, 200 replay), also each item of E26 `GET /sales`:
```json
{
  "id": "01JS...",
  "number": "SALE-2026-000125",
  "lines": [
    { "menu_item": { "id": "01JA...", "name": "Classic Burger" }, "quantity": 2 },
    { "menu_item": { "id": "01JB...", "name": "Fries" }, "quantity": 1 }
  ],
  "item_count": 2,
  "units": 3,
  "pos_reference": "till-1:000873",
  "sold_at": "2026-10-11T13:05:00Z",
  "replayed": false,
  "deductions": [
    { "ingredient": { "id": "...", "name": "Beef", "unit": "g" }, "quantity": 300, "on_hand_after": 3150, "is_negative": false }
  ]
}
```
- `menu_item` and the top-level `quantity` are removed.
- `deductions` is **grouped per ingredient** across all lines: `quantity` is the sum taken, `on_hand_after` is the balance after that ingredient's last movement in this sale, `is_negative` = `on_hand_after < 0`. Order: the ingredient's first movement in the sale.
- Messages unchanged: "Sale recorded." / "Sale already recorded. No stock moved."

### Movement history (E6) and audit

- A movement whose reference is a SaleLine is described as `type: "sale"`, `number`: the sale number, `sale_id`: the sale ULID, `label`: `"SALE-2026-000125 Classic Burger x2"` for a one-line sale, `"SALE-2026-000125 3 items"` otherwise (item count = lines).
- Audit `sale.recorded` on the Sale: properties `lines: [{menu_item, quantity}]` (menu item ULIDs), `pos_reference`; description `"SALE-... Classic Burger x2"` or `"SALE-... 3 items"`.
- Log line `Sale recorded` gets `lines` instead of `menu_item` and `quantity`. The `POS reference reused` warning logs original and attempted lines.

### Worked example (seed recipes)

Ticket: Classic Burger x2 (beef 150, bun 1, cheese 20) + Double Patty x1 (beef 300, bun 1, cheese 40, burger sauce 20).

| Movement | Line | Delta |
|---|---|---|
| beef | Classic x2 | -300 |
| bun | Classic x2 | -2 |
| cheese | Classic x2 | -40 |
| beef | Double x1 | -300 |
| bun | Double x1 | -1 |
| cheese | Double x1 | -40 |
| burger sauce | Double x1 | -20 |

Deductions in the response: beef 600, bun 3, cheese 80, burger sauce 20. With beef at 3,450 g before, beef `on_hand_after` = 2,850.

## Acceptance criteria

- [ ] Migration, `SaleLine` model and factory, `Sale::lines()`, `SaleLine::sale()`, `SaleLine::menuItem()`, `SaleLine::stockMovements()`, `MenuItem::saleLines()` (replaces `sales()`), morph alias `sale_line`.
- [ ] `SaleService::record(array $lines, ?string $posReference, ?CarbonInterface $soldAt)` where `$lines` is a list of `['item' => MenuItem, 'quantity' => int]`, merged and sorted by menu item id. One transaction, recipes loaded in one query. D-010 unchanged (no stock check).
- [ ] Replay and conflict compare merged lines, order-insensitive. The unique-index race path (R4) still answers as a replay.
- [ ] `withDeductions` groups per ingredient and stays a constant number of queries for a page of sales.
- [ ] `StoreSaleRequest` rules and messages as in the contract; `lines()` resolves all menu items in one query.
- [ ] `SaleResource`, `StockQuery::describeReference` and its eager load updated.
- [ ] `DemoSeeder::sales()` sells tickets of 1 to 3 different items, still deterministic, still `SALES` sales and one replay. Stock still goes negative somewhere (the demo relies on it).
- [ ] POS Simulator: tap-to-add tiles, a ticket with steppers and remove, a per-ingredient preview, Sell sends `lines`, ticket clears on 201, the retry buttons work on tickets, Recent sales shows items and units, 422 line errors appear on the right row. Matches the design preview (without the refund panel).
- [ ] Docs: api.md, validation.md, data.md, flows.md, decisions.md (D-048), Postman E25 body and examples, README where it shows the sale body.

## Tests required

- [ ] Every existing `SalesTest` case posts `lines`.
- [ ] Ticket test: the worked example above, exact movements (reference type `sale_line`, deltas, order) and exact on hand through `StockLedger::onHand`.
- [ ] Mixed recipes: Classic + Fries deducts the union; an ingredient only in Fries is deducted only by Fries.
- [ ] One unsellable item: 422 `menu_item_not_sellable`, zero sales, zero sale lines, zero movements, and the next sale still gets the next SALE number.
- [ ] Duplicates merged: two lines Classic x1 equal one line Classic x2 (one sale line, quantity 2).
- [ ] Idempotency: same lines reordered = 200 replay with no new movements; a changed quantity = 409; an added line = 409; nothing moves on 409.
- [ ] Validation: missing `lines`, empty `lines`, 51 lines, bad `lines.1.quantity` (keyed on that line), unknown menu item, old body shape.
- [ ] Below zero: a ticket taking cheese negative is recorded, its deduction has `is_negative: true` (D-010).
- [ ] Grouped deductions: two lines sharing beef give one beef deduction with the summed quantity and the right `on_hand_after`.
- [ ] Movement history label for a one-line and a multi-line sale.
- [ ] N+1 guard: `GET /sales` with 25 three-line tickets uses the same number of queries as with 1.
- [ ] Migration: an old-shape sale becomes one sale line with the same item and quantity.
- [ ] `DemoSeederTest` updated (units sold now from `sale_lines`).

## Done means

`php artisan migrate:fresh --seed`, open `/pos`, add Classic x2 + Fries x1, Sell: the result lists one row per ingredient, Ingredients shows the new on hand, "Resend last ticket" is a replay and "Resend with one more of the first item" is a 409.

## Out of scope for this ticket

- Refunds and returns (PTY-30).
- Prices, payments, tax, modifiers ("no cheese").
- Stock checks before a sale (D-010 stands).

---

## Builder notes

## Reviewer findings

| # | Severity | File:line | Finding | Resolution |
|---|---|---|---|---|

## Mohamad review

- [ ] Read every changed line
- [ ] Can explain it without notes (worked example above checked by hand)
- [ ] Ran manual QA

Approved: (date)
