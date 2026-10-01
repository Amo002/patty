# PTY-7 Purchase orders and the state machine

| Field | Value |
|---|---|
| Type | Story |
| Phase | 3a Build backend |
| Status | To Do |
| Weight | L |
| Builder | Opus 5.5 |
| Reviewer | Fable 5.1 |
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
