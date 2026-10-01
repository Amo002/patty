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
  1. Order status must be sent or received, else `CannotReceive` (422)
  2. Each line must belong to this order. Quantity >= 1 and <= outstanding, else `OverDelivery` (422) naming the ingredient, received and outstanding.
  3. Create the delivery and its delivery lines
  4. For each delivery line: `StockLedger::record(+qty, Delivery, deliveryLine)`
  5. If sent, transition to received
  6. If every line's outstanding is 0, transition to closed
- [ ] `PurchaseOrderLine::received()` and `outstanding()` are derived from delivery lines, never stored
- [ ] `POST /purchase-orders/{id}/deliveries`, `GET /purchase-orders/{id}/deliveries`
- [ ] PO resource shows ordered, received and outstanding per line

## Worked example (Mohamad checks by hand)

PO: beef 1000 g, bun 10.
- Delivery 1: beef 600. Beef stock +600. Outstanding beef 400, bun 10. Status received.
- Delivery 2: beef 500. Rejected (500 > 400). Nothing saved, stock unchanged.
- Delivery 3: beef 400, bun 10. Beef stock +400 (total 1000), bun +10. Outstanding all 0. Status closed.

## Tests required

- [ ] T2: partial delivery. Stock, outstanding and status as in Delivery 1 above. Not closed.
- [ ] T3: completing delivery closes the order
- [ ] A single full delivery goes sent to closed
- [ ] T5: delivery against a draft gives 422 and no movements. Against a closed order: the same.
- [ ] T9: over-delivery gives 422. In a two-line delivery where the second line is over, the first line's stock is also not saved (atomicity).
- [ ] A line from another order gives 422
