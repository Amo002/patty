# PTY-7 Purchase orders and the state machine

| Field | Value |
|---|---|
| Type | Story |
| Phase | 3a Build backend |
| Status | Done |
| Weight | L |
| Builder | Sonnet 5.5 |
| Reviewer | Opus 5.5 |
| Branch | `PTY-7-purchase-orders-state-machine` |
| Release | v0.3.0 |
| Depends on | PTY-6, Q-003, Q-004, Q-007 |

## Goal

Purchase orders whose states cannot get into a bad place, with the allowed moves defined in exactly one place.

## Covers

FR-3 AC1 to AC6. data.md invariant 5. T4, T6.

## Acceptance criteria

- [ ] `PurchaseOrderStatus` owns the map:
  - `allowedTransitions(): array`
  - `canTransitionTo(self): bool`
  - draft to sent; sent to received; received to closed.
- [ ] `PurchaseOrder::transitionTo(PurchaseOrderStatus)` is the only way status changes. It throws `InvalidTransition` (409 `invalid_transition`) with from and to in the message. It sets `sent_at`/`closed_at`.
- [ ] `App\Services\PurchaseOrderService`:
  - `create(supplier, lines)`
  - `updateLines(po, lines)`: draft only, else `OrderNotEditable` (409 `order_not_editable`)
  - `send(po)`: requires at least one line
  - `shortClose(po)`: `received` only, sets `short_closed` (Q-004)
  - `delete(po)`: draft only
- [ ] Endpoints:
  - `GET/POST /purchase-orders`
  - `GET /purchase-orders/{id}`
  - `PUT /purchase-orders/{id}/lines`
  - `POST /purchase-orders/{id}/send`
  - `POST /purchase-orders/{id}/close`
  - `DELETE /purchase-orders/{id}`
- [ ] Every service method writes its audit event (`purchase_order.created`, `.lines_updated`, `.sent`, `.short_closed`, `.deleted`) and a `purchasing` log line. Rejected transitions are logged at `notice`.
- [ ] Resource includes status, a label ("Partially received" for received), lines, and the allowed next actions, so the UI only shows valid buttons

## Tests required

- [ ] Unit: dataset of every (from, to) pair. Exactly the three allowed pairs return true.
- [ ] Feature: send a draft. Send it again gives 409 `invalid_transition`.
- [ ] Feature: close a draft gives 409. Close a sent order gives 409. Close a closed order gives 409.
- [ ] Feature: editing lines of a sent order gives 409 `order_not_editable` (T6)
- [ ] Feature: sending an order with no lines gives 409
- [ ] Feature: short-close of a received order succeeds, sets `short_closed` and writes `purchase_order.short_closed` to the audit trail

## Out of scope

Deliveries (PTY-8), which drive sent to received and the automatic close.

## Contract

[api.md](../api.md) E16 to E22. [validation.md](../validation.md) Purchase orders.
- E16 supports `?status=draft|sent|received|closed|open`.
- Every PO carries `number` (`PO-YYYY-NNNN`, from `DocumentNumber::next('PO')` in the create transaction), `allowed_actions` and `progress_percent` (average of per-line completion, D-030).

## Flows

F5, F6, F7, F11, F12.

## Test data

- Supplier "Al-Mashreq Meats"; ingredients Beef and Bun.
- PO lines: Beef 1000, Bun 10.
- The transition dataset covers all 16 (from, to) pairs of the 4 states.

## Additional acceptance criteria

- [ ] All lists are paginated. Every reference is a ULID. `assertNoIntegerIds()` in every test.
- [ ] Deleting a draft does not reuse its document number.

## Done means

1. Create a PO through E17. The response has `number: "PO-2026-0001"` and `allowed_actions: ["edit_lines","send","delete"]`.
2. Send it, then send again. The second call gives 409 `invalid_transition`, and the message names both states.
3. PUT lines on the sent PO gives 409 `order_not_editable`.

## Additions from D-035 to D-038

- [ ] On create (E17) and line replace (E19, draft only), each line **snapshots** `Ingredient::effectiveTolerance()` into its tolerance columns (D-035).
- [ ] The PO line resource has `tolerance`, `max_receivable` and `min_to_complete`.
- [ ] T26 (part): change an ingredient's tolerance after the PO is sent. The PO line's snapshot is unchanged. While still in draft, a line replace picks up the new values.

## Builder notes

Why bad states cannot happen (the interview answer, four lines):
1. `PurchaseOrderStatus::allowedTransitions()` is the only place the map exists: draft to sent, sent to received, received to closed, closed to nothing.
2. `PurchaseOrder::transitionTo()` is the only code that writes `status`. It asks the map and throws `InvalidTransition` (409) otherwise. `status` is not `$fillable`, and a new order is a draft through the model `$attributes`, so request data can never set it.
3. Every service method re-reads the order under `lockForUpdate` inside a transaction, so the status checked is the status changed, and the audit row commits or rolls back with it.
4. The UI `allowed_actions` comes from the same status, so it never offers a button the server would refuse.

What was done:
- Enum map plus a unit test that pins all 16 (from, to) pairs.
- Model: `transitionTo` stamps `sent_at`/`closed_at` and logs rejected moves at `notice`. `status`, `sent_at`, `closed_at`, `short_closed` are not fillable (factories are unguarded, so tests can still set a status). `LogsActivity` records `updated` only and `status` only: named events come from `Audit::record`, and `supplier_id` is an integer key that must not reach the trail.
- Line model: `received()` uses the `received_sum` attribute when a list loaded it with `withSum`, else one query. `outstanding`, `underDelivered`, `overReceived` follow data.md. PTY-8 should reuse them.
- `progressPercent()`: each line percent rounded down, then the average rounded down (review finding 1). It never over-reports; 1/3 and 2/3 shows 49 by design.
- Service: create, replaceLines, send, shortClose, delete, each in a transaction with audit and a `purchasing` log line. Tolerances are snapshotted on create and on every draft line replace (D-035).
- Requests: `PurchaseOrderLineRules` is shared by E17 and E19. Quantities use `integer:strict` (so `true` is rejected), reference fields use `bail` so an array never reaches the `ulid`/`exists` rules, and error messages never interpolate raw input.
- Atomicity tests fail a line write or the audit write halfway and assert nothing was saved.

Deviations and notes:
- The service method is `replaceLines` (the ticket says `updateLines`), to match E19 "Replace lines" and `ReplacePurchaseOrderLinesRequest`.
- `DocumentNumber` is an instance service, so it is injected into `PurchaseOrderService`.
- A status change writes two activity rows: the trait `updated` (what changed) and the named event (what happened). E29 (PTY-10) may want to filter on `event`.
- Delete and send-without-lines throw `InvalidTransition` through `forAction()`, with a message naming the action.

## Reviewer findings

Reviewer: Opus 5.5. Pint passes; 142 tests pass. 30 mutations run against the PO tests; 26 caught. Survivors: M6 (lockForUpdate, which compiles to nothing on SQLite, so it cannot be tested), M8a/M8b (`allowed_actions` for received), M18 (the `notice` log), M20 (`bail`, harmless). There are no High findings: the map is defined once, `transitionTo` is the only code that writes `status`, and every guard is mutation-tested.

| # | Severity | File:line | Finding | Resolution |
|---|---|---|---|---|
| 1 | Medium | app/Models/PurchaseOrder.php:122-147 | `progress_percent` is too clever for a display number, and it is not exact either. Adding one unit per line to fix 1/3 + 2/3 over-reports other cases: lines 1/999983 and 999978/999979 average just under 50% (exact floor 49), but the code says 50. Replace it with the one-sentence rule "each line's percent rounded down, then the average rounded down": `intdiv(min(received, ordered) * 100, ordered)` per line, then `intdiv(sum, count)`. Drop `PROGRESS_SCALE`. Change the "exact fifty from thirds" case to expect 49, and record in api.md and the docblock that 1/3 + 2/3 shows 49 (it never over-reports and never shows 100 before every line is fully received). | Fixed: per-line floor then average floor, no scale; 1/3 + 2/3 now expects 49; api.md and docblock updated |
| 2 | Medium | tests/Feature/Purchasing/PurchaseOrdersTest.php:120 | `allowed_actions` for a received order (`["receive","short_close"]`) is never asserted. Removing `short_close` or adding `send` survives (M8a, M8b), and this is the only status where the UI shows the short-close button. Assert it on the received order before closing. | Fixed: received order asserted as [receive, short_close] with its label before closing; sent and closed were already asserted |
| 3 | Low | app/Models/PurchaseOrder.php:89 | The AC "Rejected transitions are logged at `notice`" has no test. Deleting the `notice` call survives (M18). Add a `Log::shouldReceive`/channel spy assertion on one rejected send. | Fixed: TestHandler test asserts one notice naming both states on the purchasing channel |
| 4 | Low | app/Services/PurchaseOrderService.php:87 | flows.md F6 says `purchase_order.lines_updated` records the **old and new** lines. Only the new lines are recorded. Read the current lines (names and quantities, no ids) before deleting them and add them as `old_lines`. | Fixed: audit holds old_lines (name, ULID, quantity) and lines; test asserts both and no integer ids |
| 5 | Low | app/Services/PurchaseOrderService.php:17-19, 177-181 | The docblocks say "row lock", but `lockForUpdate` compiles to an empty string on SQLite (`SQLiteGrammar::compileLock`). On SQLite the guarantee comes from the single-writer database lock: a concurrent second writer fails with "database is locked". That is a 500 rather than a 409, but it can never cause a double transition. The lock only matters on MySQL or Postgres. Say so, as `DocumentNumber` already does, because Mohamad will be asked. | Fixed: docblocks say lockForUpdate is a no-op on SQLite, the single-writer lock serialises, the row lock matters on MySQL and Postgres |
| 6 | Low | tests/Feature/Purchasing/PurchaseOrdersTest.php:120 | D-013 and F11 say short-close moves no stock, but the test does not assert it. Add `expect(StockMovement::count())->toBe(0)` (or an on-hand check) after the close. | Fixed: short-close test asserts StockMovement count is 0 |
| 7 | Low | PurchaseOrderController.php:60-64, 114-120; PurchaseOrderService.php:187-194 | The same eager-load array (supplier, lines withSum `received_sum`, lines.ingredient) is written three times, and `->tap($this->withDetails(...))` is harder to read than it needs to be. Keep one definition (for example `PurchaseOrder::DETAILS` used by `with()`/`load()`), so PTY-8 cannot load a fourth, slightly different copy. | Fixed: PurchaseOrder::detailRelations() and scopeWithDetails() are the only definition; tap removed |
| 8 | Nit | app/Services/PurchaseOrderService.php:44 | `next('PO')` uses a literal. Use `DocumentNumber::PURCHASE_ORDER`. | Fixed: DocumentNumber::PURCHASE_ORDER |
| 9 | Nit | app/Http/Resources/V1/PurchaseOrderResource.php:11 | The docblock points to `PurchaseOrderController::with()`, which does not exist (the method is `withDetails`). | Fixed: points to PurchaseOrder::detailRelations() |
| 10 | Nit | tests/Feature/Purchasing/PurchaseOrdersTest.php:71, 286 | Two test names do not match their bodies. "cannot be created as sent" sets `closed`. "accepts the largest quantity and rejects one above it" only tests the accept (the reject is in the G3 dataset). Rename both. | Fixed: renamed to match bodies; the 1,000,001 rejection stays in the quantity dataset and the name says so |
| 11 | Nit | ticket, Builder notes | `replaceLines` (the ticket says `updateLines`) is not listed under deviations. Accepted, because it matches E19 "Replace lines" and `ReplacePurchaseOrderLinesRequest`, but record it there. | Fixed: listed under deviations in Builder notes |

Deviation rulings:
- (1) Removing `status`, `sent_at`, `closed_at` and `short_closed` from fillable is accepted. M14 pins it.
- (2) The fixed-point maths is rejected: see finding 1.
- (3) Injecting `DocumentNumber` is accepted.
- (4) Two activity rows per transition is accepted. D-021 puts `LogsActivity` on PurchaseOrder, and F7 expects "plus the field change". The noise is a filtering concern for E29 (PTY-10).
- (5) `integer:strict` plus `bail` is accepted. Strict is what rejects `true`, which M19 pins. Note for the UI tickets: send numbers, not strings (`"10"` gives 422). The `bail` is defensive only, because without it the case still gives 422 (M20).
- (6) `InvalidTransition::between()` and `forAction()` are accepted. They are small, named, and their messages match api.md and F7.
- (7) `replaceLines` is accepted: see finding 11.
