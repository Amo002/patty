# PTY-8 Receiving deliveries

| Field | Value |
|---|---|
| Type | Story |
| Status | To Do |
| Weight | L |
| Builder | Opus 5.5 |
| Reviewer | Fable 5.1 |
| Branch | `PTY-8-receiving-deliveries` |
| Release | v0.3.0 |
| Depends on | PTY-7, Q-002, Q-003 |

## Goal

Record what actually arrived, full or partial. Stock rises by exactly that, and the order closes itself when nothing is outstanding.

## Covers

FR-4 AC1 to AC8. data.md invariants 2, 4 and 6. T2, T3, T5, T9.

## Acceptance criteria

- [ ] `App\Services\ReceivingService::receive(PurchaseOrder, array $lines, ?received_at, ?note): Delivery`, all inside one `DB::transaction`:
  1. Re-read the order with `lockForUpdate()` (D-024; a real row lock on MySQL/Postgres, while SQLite serialises writers). Status must be sent or received, else `CannotReceive` (409 `cannot_receive`).
  2. Each line must belong to this order. Quantity >= 1, and `received so far + quantity <= max_receivable`, else `OverDelivery` (422 `over_delivery`) naming the ingredient, the attempted total and the maximum allowed. `max_receivable = intdiv(ordered * (100 + tolerance), 100)`, with tolerance from `config('patty.over_delivery_tolerance_percent')` (default 5).
  3. Create the delivery and its delivery lines
  4. For each delivery line: `StockLedger::record(+qty, Delivery, deliveryLine)`
  5. If sent, transition to received
  6. If every line's outstanding is 0, transition to closed
  7. Audit `delivery.recorded` (lines in properties), and `purchase_order.closed` when it closes. One `purchasing` log line. Rejected over-deliveries are logged at `notice`.
- [ ] `PurchaseOrderLine::received()`, `outstanding()`, `overReceived()` and `maxReceivable()` are derived from delivery lines, never stored
- [ ] `config/patty.php` with `over_delivery_tolerance_percent => 5`
- [ ] `POST /purchase-orders/{id}/deliveries`, `GET /purchase-orders/{id}/deliveries`
- [ ] PO resource shows ordered, received and outstanding per line

## Worked example (Mohamad checks by hand)

PO: beef 1000 g, bun 10. Tolerance 5%, so max receivable is beef 1050 g and bun 10 (10.5 rounds down).
- Delivery 1: beef 600. Beef stock +600. Outstanding beef 400, bun 10. Status received.
- Delivery 2: beef 500. Rejected: 600 + 500 = 1100 > 1050. Nothing saved, stock unchanged.
- Delivery 3: beef 430, bun 10. Beef stock +430 (total 1030), bun +10. Outstanding beef 0 (over-received 30), bun 0. Status closed.
- On another PO with bun 10: delivering 11 is rejected (11 > 10).

## Tests required

- [ ] T2: partial delivery. Stock, outstanding and status as in Delivery 1 above. Not closed.
- [ ] T3: completing delivery closes the order
- [ ] A single full delivery goes sent to closed
- [ ] T5: delivery against a draft gives 409 `cannot_receive` and no movements. Against a closed order: the same.
- [ ] T9a: within tolerance. Ordered 1000, receiving 1050 in total is accepted, and stock rises by the full 1050.
- [ ] T9b: beyond tolerance. Receiving 1051 in total gives 422. In a two-line delivery where the second line is over, the first line's stock is also not saved (atomicity).
- [ ] T9c: rounding. 10 buns allows 10, and 11 gives 422.
- [ ] Short-close of a received order marks it short-closed and moves no stock (Q-004)
- [ ] A line from another order gives 422
- [ ] A rejected delivery (over 5%) leaves no audit row and no movement (atomicity includes the audit trail)
