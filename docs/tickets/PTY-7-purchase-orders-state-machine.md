# PTY-7 Purchase orders and the state machine

| Field | Value |
|---|---|
| Type | Story |
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
