# PTY-29 Multi-item POS sales (a ticket of one or more menu items)

| Field | Value |
|---|---|
| Type | Story |
| Phase | Post-release extension |
| Status | In review |
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

Built by three Sonnet builders in parallel, each owning separate files: backend (app, database, tests), POS UI (pos.blade.php, pos.js, purchasing.css), docs. The orchestrator merged them and ran the suite: 459 tests green before review fixes.

- **Merging duplicates.** `StoreSaleRequest::lines()` adds quantities per menu item and loads every item in one `whereIn` query. An `after()` hook rejects a merged total above 1,000 on the item's first row. `SaleService::record()` merges and sorts again, so any caller (the seeder, a test) gets the same lines and the same movement order. Merging twice is a no-op.
- **Order of writes.** All recipes load in one query. Every item is checked for a recipe **before** the SALE number is taken, so a refused ticket does not touch the counter. Then: sale, each SaleLine, and each recipe line's movement against its line, in line order (menu item id) then recipe-line id.
- **Replay and conflict.** The stored and attempted lines both become a `menu item id => quantity` map, key-sorted, compared with `!==`. Order on the wire does not matter. The R4 unique-race catch is unchanged.
- **Grouped deductions.** `withDeductions` loads the lines' movements (plus the sale's own movements, for rows recorded before this change) in id order and groups them by ingredient. `groupBy` keeps first-appearance order. `quantity` is the negated sum. `on_hand_after` is `balancesAt()` (one correlated query) for the group's **last** movement. Example: beef at 1,000, Classic then Double: 850 after the first line, 550 after the last, so 550 is shown.
- **Legacy rows.** Movements written before PTY-29 reference the Sale and can never be repointed (append-only). `withDeductions`, `StockQuery::describeReference` and `Sale::summary()` handle both reference types.
- **Migration order differs from the contract.** The contract said "copy into sale_lines, then drop the columns". On SQLite, dropping a foreign-key column makes Laravel rebuild `sales` (drop, create, copy), and that fails with "FOREIGN KEY constraint failed" once `sale_lines` rows reference `sales`. So the migration reads the sales into memory, drops the FK and both columns, creates `sale_lines` and inserts one line per old sale. Verified by the orchestrator on a copy of a real local database: 121 sales became 121 lines, 201 units before and after, stock total unchanged (24,630). `down()` is lossy and unsupported after real multi-line tickets.
- **Seeder.** 70% of tickets have one item (old weighted quantity 1 to 4), about 22% two and 8% three, one of each item. Still 120 sales and one replay. Cheese still ends negative. A combo of one each keeps beef from going far below zero, which would break "every other ingredient positive".
- **UI.** The ticket is an array of `{menu_item_id, quantity, error}` in tap order. Names and recipes are looked up from the loaded menu items. The preview sums `recipe quantity x line quantity` per ingredient and subtracts it from the loaded `on_hand`. `lines.N.*` errors map to ticket row N, because Sell sends the ticket exactly as it stands.

## Reviewer findings

Opus 5.5, `/code-review high` plus a manual pass. Verdict: approve after fixes. Worked example confirmed against SalesTest (seven movements, exact deltas and order, beef 2,850).

| # | Severity | File:line | Finding | Resolution |
|---|---|---|---|---|
| R1 | Medium | public/js/pages/pos.js:243 | Every 2xx cleared the ticket, whichever request it answered: a "Resend last ticket" replay wiped a ticket being built, and items tapped during an in-flight sale were cleared unsold. || Fixed (87db2e8). Only Sell clears the ticket, and only if it still equals the snapshot that was sent; controls are disabled while a sale is in flight. |
| R2 | Medium | public/js/pages/pos.js:275 | `lines.N` errors from a resend (payload from `last`) were mapped onto unrelated ticket rows. || Fixed (87db2e8). Row errors are mapped only for Sell; resend errors go to the notice. |
| R3 | Low | docs/data.md | Invariant 3 claimed every sale movement references a sale line; pre-PTY-29 movements reference the sale. || Fixed (be3b577). |
| R4 | Low | docs/validation.md | Two extra messages in the code (non-string and bad-ULID menu item ids) were not documented. || Fixed (be3b577). Both messages documented. |
| R5 | Low | docs/data.md | The migration note stated the contract's order, not the real one and its reason. || Fixed (be3b577). Real order and the SQLite reason documented. |
| R6 | Low | migration 2026_10_11_000001 | `down()` is lossier than its docblock said. || Fixed (6f17178). Docblock states rollback after real tickets is unsupported, and why. |
| R7 | Low | app/Services/SaleService.php:215 | The service would create a sale with zero lines if called with none; only the FormRequest prevented it. || Fixed (6f17178). InvalidArgumentException before any write, with a test. |
| R8 | Low | tests/Feature/Sales/SalesTest.php:249 | Deleting the service-level merge was caught by no test. || Fixed (6f17178). Service-level merge test with exact movements. |
| R9 | Low | app/Http/Requests/V1/StoreSaleRequest.php | Merged duplicates over 1,000 got the generic quantity message on a row whose own value was valid; the 50 limit counts rows but the message said "different items". || Fixed (6f17178). "Line N: {item} appears more than once and adds up to more than 1,000." and "A sale can hold at most 50 lines." |
| R10 | Low | public/css/pages/purchasing.css:98 | Tile badge text used `--surface` instead of `--on-accent`. || Fixed (87db2e8). |
| R11 | Low | resources/views/pages/pos.blade.php:457 | `aria-live` wrapped the whole ticket, re-announcing rows on every stepper change. || Fixed (87db2e8). aria-live only on the count line. |
| R12 | Low | process | Builder notes empty, no AI_LOG entry, merge commits not in `PTY-N:` form. | Notes and AI_LOG written by the orchestrator. Later merges use `PTY-29: Merge ...`. History not rewritten. |
| R13 | Info | StoreSaleRequest.php:373 | `exists` runs one query per line (up to 50) before `lines()` loads the items again. | Accepted at 50 lines. |
| R14 | Info | SaleService.php:124 | Two redundant eager loads; the query count is still constant. | Accepted; the legacy load is needed for migrated rows. |
| R15 | Info | StockLedger.php:78 | Up to 250 `onHand()` sums in one transaction for a 50-line ticket; a doubly-negative ingredient logs the warning twice. | Accepted at this scale. |
| R16 | Info | DemoSeederTest.php:192 | The flaky test the builder reported could not be reproduced (9 runs); nothing in PTY-29 makes it worse. | Pre-existing, no change. |
| O1 | Low | (orchestrator smoke test) | The sales 409 message still said "(other item or quantity)". || Fixed (6f17178). Now "(other items or quantities)". |

## Mohamad review

- [ ] Read every changed line
- [ ] Can explain it without notes (worked example above checked by hand)
- [ ] Ran manual QA

Approved: (date)
