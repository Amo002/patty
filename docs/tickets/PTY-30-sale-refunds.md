# PTY-30 Sale refunds (with or without restock)

| Field | Value |
|---|---|
| Type | Story |
| Phase | Post-release extension |
| Status | In review |
| Weight | L |
| Builder | Sonnet 5.5 (three builders: backend, UI, docs) |
| Reviewer | Opus 5.5 |
| Branch | `PTY-30-sale-refunds` |
| Release | v1.1.0 |
| Depends on | PTY-29 (merged, PR #34) |

## Goal

A manager can refund some or all of the items on a sale. A sale is never edited or deleted: a refund is a new document against it. The manager answers one question, "was the food already made?". If it was, stock does not change (the ingredients really left). If it was voided before cooking, the ingredients go back to stock with new positive movements.

Design preview approved by Mohamad: https://claude.ai/artifact/W57Mgf3wrPaM3eyW7TgaVd (the Refund button in Recent sales and the refund panel).

## Covers

FR-5 (extension), D-004, D-010, D-029, D-048, new D-049.

## Business rules (D-049)

1. **A sale is never changed.** A refund is a `sale_returns` row with its `sale_return_lines`. "Corrections are new documents", as in the ledger.
2. **`restock` is required, no default on the API.** The UI preselects "Yes, it was made" (`restock: false`).
   - `restock: false`: **no stock movement at all.** The refund exists for the sales report and the audit trail.
   - `restock: true`: for each refunded line, for each of that sale line's movements: a new movement of `-(movement delta) / line quantity x refunded quantity`, reason `sale_return`, referencing the `SaleReturnLine`. Always an exact positive integer because the sale movement is `-(recipe qty x line qty)`. It uses the sale's own movements, never today's recipe, so a recipe change after the sale does not change the refund.
   - **Legacy sales** (recorded before PTY-29) have movements on the Sale, not the line, and exactly one line each. For a line with no movements of its own whose sale has exactly one line, use the sale's movements. A line with no movements at all restocks nothing.
3. **Never refund more than is left, per line:** left = line quantity - sum of earlier refunds of that line. Checked for every line, inside the transaction with the sale row locked, **before anything is written**. All breaches are reported at once.
4. **Idempotency (D-029):** optional `pos_reference`, unique on `sale_returns`. Same reference + same sale + same merged lines + same `restock` = 200 replay, nothing written except a `sale.return_replayed` audit row. Anything different = 409 `idempotency_conflict`. Lines compare order-insensitively, as for sales.
5. **No stock check (D-010):** a restock never fails because of stock, and a refund never fails because stock is negative.

## Contract (the three builders work to this; do not change it without the orchestrator)

### Data

- `sale_returns`: `id`, `ulid` (unique), `number` (unique, `RET-2026-000001`, DocumentNumber type `RET`, width 6), `sale_id` (FK sales, restrictOnDelete), `restock` (boolean), `reason` (string 200), `pos_reference` (string, nullable, unique), `returned_at` (timestamp), timestamps.
- `sale_return_lines`: `id`, `sale_return_id` (FK, restrictOnDelete), `sale_line_id` (FK sale_lines, restrictOnDelete), `quantity` (unsigned int), timestamps. Unique (`sale_return_id`, `sale_line_id`).
- Models `SaleReturn` (HasPublicUlid), `SaleReturnLine`. Relations: `Sale::returns()`, `SaleLine::returnLines()`, `SaleReturn::sale()`, `SaleReturn::lines()`, `SaleReturnLine::saleReturn()`, `SaleReturnLine::saleLine()`, `SaleReturnLine::stockMovements()`.
- Morph aliases `sale_return`, `sale_return_line`.
- `MovementReason::SaleReturn = 'sale_return'`, label "Sale return".

### POST /api/v1/sales/{sale}/returns (E33; E27 was already GET /stock)

`{sale}` is the sale ULID (route model binding, as elsewhere). Middleware: `PosKey` and `throttle:pos`, like `POST /sales`.

Request:
```json
{
  "lines": [ { "menu_item_id": "01JA...", "quantity": 1 } ],
  "restock": false,
  "reason": "Customer complaint",
  "pos_reference": "till-1:ret-000031"
}
```
- `lines`: required, list, 1 to 50 rows. `lines.*.menu_item_id`: required, string, ulid, exists. `lines.*.quantity`: required, `integer:strict`, 1 to 1000. Duplicates are merged (as for sales).
- `restock`: required, `boolean:strict` (true or false only).
- `reason`: required, string, 1 to 200 characters, no control characters (same rule as names).
- `pos_reference`: same rules as on sales.

Errors:
- 422 validation, messages:
  - "Add at least one item to refund."
  - "A refund can hold at most 50 lines."
  - "Line N: choose the menu item to refund."
  - "Line N: the quantity must be a whole number from 1 to 1,000."
  - "Choose whether the ingredients go back to stock."
  - "Give a reason for the refund."
  - "The reason must be at most 200 characters."
- 422 `item_not_on_sale` (domain exception) when a menu item is not on this sale. errors: `lines.N.menu_item_id` => "Line N: Fries is not on SALE-2026-000125."
- 422 `over_refund` (domain exception, like OverDelivery) when a quantity exceeds what is left. errors: `lines.N.quantity` => "Line N: only 1 Fries left to refund on SALE-2026-000125." (N is the 1-based row in messages, the 0-based index in keys; for merged duplicates, the item's first row). Message: the first breach's text without the "Line N: " prefix.
- 409 `idempotency_conflict`.
- 404 unknown sale.

Response `data` (201 new, 200 replay; messages "Refund recorded." / "Refund already recorded. No stock moved."):
```json
{
  "id": "01JR...",
  "number": "RET-2026-000001",
  "sale": { "id": "01JS...", "number": "SALE-2026-000125" },
  "restock": true,
  "reason": "Cancelled before cooking",
  "pos_reference": "till-1:ret-000031",
  "returned_at": "2026-10-11T14:02:00Z",
  "replayed": false,
  "lines": [ { "menu_item": { "id": "01JA...", "name": "Fries" }, "quantity": 1 } ],
  "restocked": [
    { "ingredient": { "id": "...", "name": "Potatoes", "unit": "g" }, "quantity": 200, "on_hand_after": 2600 }
  ]
}
```
- `restocked` is grouped per ingredient, ordered by first movement, `on_hand_after` = balance after that ingredient's last movement in this return. Empty array when `restock` is false.

### Changes to existing endpoints

- E26 `GET /sales` and the E25 response: each item of `lines` gains `refunded` (int, sum of that line's refund quantities). The sale gains `refunded_units` (int). Constant query count (withSum).
- E6 movement history: a movement referencing a SaleReturnLine is `type: "sale_return"`, `number`: the RET number, `sale_id`: the sale ULID, `label`: `"RET-2026-000001 for SALE-2026-000125: Fries x1"`.
- Audit `sale.returned` with the **Sale** as subject (so the sale's activity shows it), properties `{return: RET number, lines: [{menu_item ulid, quantity}], restock, reason, pos_reference}`, description `"RET-2026-000001 for SALE-2026-000125: Fries x1 (restocked)"` or `"(not restocked)"`. Log channel `pos`: `Sale refunded` (info).

### Worked example

Sale SALE-2026-000125: Fries x2 (potatoes 200, oil 25, ketchup 20 each). Its line movements: potatoes -400, oil -50, ketchup -40.

| Refund | restock | Movements written | Left on the line |
|---|---|---|---|
| RET-...-000001: Fries x1 | false | none | 1 |
| RET-...-000002: Fries x1 | true | potatoes +200, oil +25, ketchup +20 (`-(-400)/2 x 1`) | 0 |
| a third: Fries x1 | either | none: 422 `over_refund` "only 0 Fries left" | 0 |

On hand after both: potatoes, oil and ketchup are each down by exactly one Fries' worth from before the sale.

## Acceptance criteria

- [ ] Migrations, models, relations, morph aliases, enum case, `RET` document type.
- [ ] `SaleReturnService::record(Sale $sale, array $lines, bool $restock, string $reason, ?string $posReference): SaleReturnResult` (lines = `['item' => MenuItem, 'quantity' => int]`), one transaction, sale locked, all checks before any write, restock arithmetic from the sale line's own movements.
- [ ] Controller, FormRequest, Resource, route; `bootstrap/app.php` renders the two new domain exceptions with their `errors`.
- [ ] Sale resource `refunded` / `refunded_units`; StockQuery label; audit and log.
- [ ] DemoSeeder: one refund not restocked and one restocked on earlier sales, deterministic; DemoSeederTest updated.
- [ ] UI: Refund button per Recent sales row ("Refunded" and disabled when nothing is left); a refund panel matching the preview: per-line steppers capped at what is left, the made/voided choice (made preselected), a reason select (Customer complaint, Wrong order, Cancelled before cooking, Long wait), a preview of what happens to stock, inline 422 errors on the row, success notice, and Recent sales shows "Fries x1 refunded" pills from `refunded`.
- [ ] Docs: D-049, api.md (E33 and the changed shapes), validation.md, data.md, flows.md, ui.md, testing.md, Postman (E33 request with examples), README.

## Tests required

- [ ] Worked example rows 1 to 3, exact movements (reason, reference type `sale_return_line`, deltas) and exact on hand via `StockLedger::onHand`.
- [ ] Multi-line sale: refunding one line restocks only that line's ingredients.
- [ ] A recipe change after the sale does not change the restocked amounts.
- [ ] Legacy sale (movements on the Sale, one line): restock uses the sale's movements.
- [ ] Two lines over the limit in one request: both errors reported, nothing written, no RET number used.
- [ ] Item not on the sale: 422 `item_not_on_sale`, nothing written.
- [ ] Idempotency: replay (reordered lines) = 200 and nothing new; changed restock = 409; changed quantity = 409; nothing written on 409.
- [ ] Validation: missing restock, non-boolean restock ("true", 1), missing/long reason, empty lines, bad quantity.
- [ ] `GET /sales` shows `refunded` and `refunded_units`, constant query count with refunds present.
- [ ] Movement history label for a restock movement.
- [ ] Audit `sale.returned` on the sale with the right description.
- [ ] Interleaved sales and refunds: on hand equals the plain SUM of movements and the expected hand-computed number.

## Done means

`php artisan migrate`, open `/pos`, refund 1 Fries "made": stock unchanged, the sale shows "Fries x1 refunded". Refund the other "voided": potatoes +200, oil +25, ketchup +20 on Ingredients. A third refund is refused with "only 0 Fries left".

## Out of scope

- Money, payments, partial amounts.
- Editing or cancelling a refund (a refund is final; a mistake would be a new document, not built here).
- Refunds without an original sale.

---

## Builder notes

Built by three Sonnet builders in parallel (backend, refund UI, docs) against the contract above, merged by the orchestrator: 503 tests green before review fixes.

- **Restock arithmetic.** `SaleReturnService::restock` writes, for each movement of the refunded sale line, `intdiv(-delta x k, line quantity)` through `StockLedger::record`, reason `sale_return`, referencing the `SaleReturnLine`. Multiply first, then divide; a remainder or a non-positive result throws `LogicException` and rolls back. That cannot happen for real data (every sale movement is `-(recipe qty x line qty)`), and a 500 beats silent rounding.
- **Legacy sales.** A line with no movements of its own, on a sale with exactly one line, uses the sale's movements (sales from before PTY-29). Any other line without movements restocks nothing.
- **"Left" under the lock.** Inside the transaction the sale row is re-read with `lockForUpdate()`, then its lines with `withSum('returnLines as refunded_sum')`. The idempotency lookup runs right after the lock. Every breach is collected and sorted back into request-row order before the RET number is taken or anything is written. `item_not_on_sale` wins over `over_refund` when both occur.
- **Merging.** Unlike sales, the request returns the rows as sent and the service merges them, remembering each item's first row so errors key on `lines.{first row}.*`. No "adds up to more than 1,000" rule is needed: `over_refund` catches any merged total above what is left.
- **Replay.** Same sale, same merged lines and same `restock` = replay (only a `sale.return_replayed` audit row). `reason` is not compared, like `sold_at` for sales; the original reason is returned.
- **Shared helper.** `SaleService::balancesAt` moved unchanged to `StockLedger::balancesAfter` so refunds can report `on_hand_after` the same way sales do.
- **UI.** The refund panel is the app's native `<dialog>` (same pattern as Receive delivery). Its state is separate from the ticket and the Result card. `lines.N` errors map to the N-th line actually sent. The preview is estimated from today's recipe and says so; the success notice shows the real restocked amounts from the response.
- **Endpoint number.** The contract said E27, which was already `GET /stock`. The docs builder numbered it E33 so nothing else shifted.

## Reviewer findings

Opus 5.5, manual review plus 12 mutation checks: 11 killed; the 12th (removing `lockForUpdate`) survives because SQLite has a single writer, the same as receiving. Verdict: approve after fixes. Worked example confirmed: 10000 / 10000 / 10000, then 9600 / 9950 / 9960 after Fries x2, then 9800 / 9975 / 9980 after the made and voided refunds, third refused with the RET sequence still at 2.

| # | Severity | File:line | Finding | Resolution |
|---|---|---|---|---|
| R1 | Medium | public/js/pages/pos.js refundSale | A new `pos_reference` on every press: a retry after a lost response would record a second refund. || Fixed (1737379). The reference is made when the panel opens and reused on retry; renewed on any change, after a 201 and after a 409. |
| R2 | Low | pos.js openRefund | Focus not placed like the receive dialog; focus lost when the opener becomes "Refunded". || Fixed (1737379). Focus follows the receive dialog; on close it goes to the Recent sales heading if the opener is disabled. |
| R3 | Low | docs/ui.md, pos.js preview | The preview uses today's recipe while ui.md said the sale's own amounts; recommended a one-line hint. || Fixed (1737379, 317fbf6). Hint added; the success notice shows the real restocked amounts. |
| R4 | Low | docs/api.md | Example message capitalised; the code says "only ...". || Fixed (317fbf6). |
| R5 | Low | docs/flows.md, docs/architecture.md | Idempotency lookup shown before the lock; log context wrong. || Fixed (317fbf6). |
| R6 | Low | docs/validation.md | Two messages missing; regex written with `$` not `\z`. || Fixed (317fbf6). The sales regex was aligned too. |
| R7 | Low | docs/ui.md | Success and replay wording did not match the UI. || Fixed (317fbf6). |
| R8 | Low | several comments | Comments still said E27. || Fixed (1737379, 5204926). The commit message saying E27 stays (no force-push). |
| R9 | Low | DemoSeeder refund block | A seeded refund could be timed after the next sale with a smaller movement id. || Fixed (5204926). Refund at the earliest of sale + 2 min, next sale - 1 s, now; a seeder test checks sale and refund movements keep clock order. |
| R10 | Low | SaleReturnService::check | `item_not_on_sale` hiding `over_refund` was undocumented and untested. || Fixed (5204926, 317fbf6). Tested and documented. |
| R11 | Low | SaleReturnsTest.php:437 | Test name did not match what it checks. || Fixed (5204926). |
| R12 | Info | SaleReturnService.php:310 | Replay ignores `reason`. | Kept, as recommended; documented. |
| R13 | Info | SaleReturnService.php:290 | `LogicException` only reachable with corrupt data. | Kept. |
| R14 | Info | requests | Refunds merge in the service, sales in the request. | Justified and tested. |
| R15 | Info | StockLedger::balancesAfter | Refactor checked: no behaviour change for sales. | No action. |
| R16 | Info | ticket, AI_LOG | Builder notes and AI log missing. | Written by the orchestrator. |

## Mohamad review

- [ ] Read every changed line
- [ ] Can explain it without notes (worked example checked by hand)
- [ ] Ran manual QA

Approved: (date)
