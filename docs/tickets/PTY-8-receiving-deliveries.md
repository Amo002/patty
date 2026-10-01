# PTY-8 Receiving deliveries

| Field | Value |
|---|---|
| Type | Story |
| Phase | 3a Build backend |
| Status | To Do |
| Weight | L |
| Builder | Sonnet 5.5 |
| Reviewer | Opus 5.5 |
| Branch | `PTY-8-receiving-deliveries` |
| Release | v0.3.0 |
| Depends on | PTY-7, Q-002, Q-003 |

## Goal

Record what actually arrived, full or partial. Stock rises by exactly that, and the order closes itself when nothing is outstanding.

## Covers

FR-4 AC1 to AC12. D-035. data.md invariants 2, 4, 6 and 7. T2, T3, T5, T9a to T9f, T26.

## Acceptance criteria

- [ ] `App\Services\ReceivingService::receive(PurchaseOrder, array $lines, ?received_at, ?note): Delivery`, all inside one `DB::transaction`:
  1. Re-read the order with `lockForUpdate()` (D-024; a real row lock on MySQL/Postgres, while SQLite serialises writers). Status must be sent or received, else `CannotReceive` (409 `cannot_receive`).
  2. Each line must belong to this order. Quantity >= 1, and `received so far + quantity <= max_receivable`, else `OverDelivery` (422 `over_delivery`) naming the ingredient, the attempted total and the maximum allowed. `max_receivable = ordered + min(intdiv(ordered * over_bps, 10000), over_cap)`, using the **line's snapshot** tolerance (D-035), never the ingredient's current settings.
  3. Create the delivery and its delivery lines
  4. For each delivery line: `StockLedger::record(+qty, Delivery, deliveryLine)`
  5. If sent, transition to received
  6. If every line is complete (`received >= min_to_complete`, with `min_to_complete = ordered - intdiv(ordered * under_bps, 10000)`), transition to closed
  7. Audit `delivery.recorded` (lines in properties), and `purchase_order.closed` when it closes. One `purchasing` log line. Rejected over-deliveries are logged at `notice`.
- [ ] `App\Domain\Tolerance` (a small readonly value object built from a line's snapshot) owns the arithmetic: `maxReceivable(int $ordered)`, `minToComplete(int $ordered)`. Pure integers, unit-tested on its own, so the rules live in exactly one place.
- [ ] `PurchaseOrderLine::received()`, `isComplete()`, `outstanding()`, `underDelivered()` and `overReceived()` are derived from delivery lines and the Tolerance, never stored
- [ ] `POST /purchase-orders/{id}/deliveries`, `GET /purchase-orders/{id}/deliveries`
- [ ] PO resource shows ordered, received and outstanding per line

## Worked example (Mohamad checks by hand)

Defaults: over 5%, under 5%, cap 2,000 g (none for pieces).

PO 1: beef 1000 g, bun 10.
- Beef: max receivable 1000 + min(50, 2000) = **1050**; min to complete 1000 - 50 = **950**.
- Bun: max 10 + intdiv(5000, 10000) = 10 + 0 = **10**; min **10**.
- Delivery 1: beef 600. Beef stock +600. Outstanding beef 400, bun 10. Status received.
- Delivery 2: beef 500. Rejected: 600 + 500 = 1100 > 1050. Nothing saved, stock unchanged.
- Delivery 3: beef 430, bun 10. Beef stock +430 (total 1030), bun +10. Both lines complete (beef 1030 >= 950, bun 10 >= 10). Outstanding 0, beef over-received 30. Status closed.

PO 2: beef 1000 g. Deliver 960. 960 >= 950, so the line is **complete**, outstanding 0, under-delivered 40, and the PO closes. Stock rises by 960 only.

PO 3: beef 1000 g. Deliver 900. 900 < 950, so it is not complete: outstanding 100, status partially received.

PO 4: beef 50,000 g. Max is 50000 + min(2500, **2000**) = 52,000 (the cap beats the 5%). Delivering 52,100 is rejected.

PO 5: bun 10. Delivering 11 is rejected. Delivering 9 is not complete (outstanding 1).

## Tests required

- [ ] T2: partial delivery. Stock, outstanding and status as in Delivery 1 above. Not closed.
- [ ] T3: completing delivery closes the order
- [ ] A single full delivery goes sent to closed
- [ ] T5: delivery against a draft gives 409 `cannot_receive` and no movements. Against a closed order: the same.
- [ ] T9a: within over-tolerance. Ordered 1000, receiving 1050 in total is accepted, and stock rises by the full 1050.
- [ ] T9b: beyond over-tolerance. Receiving 1051 in total gives 422. In a two-line delivery where the second line is over, the first line's stock is also not saved (atomicity).
- [ ] T9c: the cap beats the %. Ordered 50,000; 52,000 is accepted, 52,001 gives 422.
- [ ] T9d: rounding. 10 buns allows 10, and 11 gives 422.
- [ ] T9e: under-tolerance completion. Ordered 1000, receive 960: the line is complete, outstanding 0, under-delivered 40, the PO is closed and not `short_closed`.
- [ ] T9f: below under-tolerance. Ordered 1000, receive 900: not complete, outstanding 100, the PO stays partially received.
- [ ] T26 (with PTY-7): the line uses its snapshot. After the PO is sent, change the ingredient's over % to 0. A 1,040 delivery is still accepted.
- [ ] Unit test for `Tolerance`: dataset of (ordered, over_bps, under_bps, cap) to (max, min) rows, including every row of the worked example.
- [ ] Short-close of a received order marks it short-closed and moves no stock (Q-004)
- [ ] A line from another order gives 422
- [ ] A rejected delivery (over 5%) leaves no audit row and no movement (atomicity includes the audit trail)

## Contract

[api.md](../api.md) E23, E24. [validation.md](../validation.md) Deliveries. A delivery number is taken with `DocumentNumber::next('GRN')`. `received_at` must not be in the future or before `sent_at` (422).

## Flows

F8, F9, F10, F11 (incoming effect), F21 (under-tolerance completion). Rows written must match flows.md exactly.

## Test data

The worked example above, plus the "A day at Patty" deliveries (flows.md) for the interleaved test in PTY-10.

## Additional acceptance criteria

- [ ] `received_at` before `sent_at` gives 422. In the future, 422.
- [ ] Delivery lines reference PO lines by ULID, and the ULID must belong to the route PO.
- [ ] The response includes the updated PO, so the UI needs no second request.
- [ ] `assertNoIntegerIds()` in every test.

## Done means

1. Send a PO for Beef 1000 and receive 600. The PO shows "Partially received" with outstanding 400, and `GET /stock` shows beef +600.
2. Receive 500. 422 `over_delivery`, and the message says 1,100 g is above the 1,050 g limit.
3. Receive 430. The PO is closed with over-received 30.
