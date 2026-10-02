# PTY-8 Receiving deliveries

| Field | Value |
|---|---|
| Type | Story |
| Phase | 3a Build backend |
| Status | Done |
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

## Builder notes

Partial deliveries and tolerance (the interview answer, four lines):
1. A delivery is a document with lines; each line is one positive stock movement, and that is the only way stock rises. "Received" and "outstanding" are never stored: received is the sum of delivery lines, outstanding is ordered minus received.
2. Each PO line carries a snapshot of its tolerance (over %, under %, over cap) taken when the order was created or last edited as a draft, so changing an ingredient later cannot change an order already promised to a supplier (D-035).
3. Over side: a line may receive up to `ordered + min(intdiv(ordered * over_bps, 10000), over_cap)`; beyond that the whole delivery is rejected (422) and nothing is saved. Under side: a line is complete at `max(1, ordered - intdiv(ordered * under_bps, 10000))`, so 960 of 1000 is complete with 40 under-delivered.
4. The order moves sent to received on the first delivery and to closed in the same transaction when every line is complete. Closing by tolerance is a normal close; `short_closed` is only for a manual close with quantity outstanding.

What was done:
- `ReceivingService::receive()` in one `DB::transaction`: lock the order, status guard, tolerance check, GRN number, delivery and lines, one movement per line (`occurred_at` = `received_at`), status moves, audit `delivery.recorded` (and `purchase_order.closed`), one `purchasing` info line. A rejected delivery logs a `notice`.
- The tolerance check runs over all lines first and reports every breach (`errors` keyed `lines.N.quantity`); the message names the first. It re-reads lines inside the lock and keeps a running total per line, so a repeated line cannot slip past the limit even if the request check were bypassed.
- `StoreDeliveryRequest`: ULIDs lowercased and scoped to the route order with `Rule::exists(...)->where(...)`, `distinct`, `integer:strict` 1 to 1,000,000, static messages with `:position`. `received_at` is ISO-8601 only (`date_format` with offset, `Z` and fractional variants; `date` would accept "yesterday"), parsed with `->utc()` before it reaches the service, and compared with `sent_at` and now in UTC (Carbon::parse keeps the caller's offset and Eloquent stores clock time without converting). The `sent_at` check is skipped when the PO is a draft, because the service answers that with 409.
- E23 returns `data: { delivery, purchase_order }` so the UI needs no second request (api.md E23 note added). E24 is paginated, newest first by `received_at`, then id.
- `Tolerance` already exists as `App\Support\Tolerance` with its unit test (`tests/Unit/ToleranceTest.php`); the ticket says `App\Domain\Tolerance`. Kept where it is.
- The atomicity test forces the audit write to fail after the delivery, lines and movements are written; removing `DB::transaction` makes it fail (verified once, then restored). The over-limit check runs before any write, so a rejected delivery is clean even without the transaction, which is why the atomicity test uses the audit failure.

Deviations:
- Line messages say "Line N:" without the ingredient name (validation.md G7 asks for "Line 2 (Beef)"), because the name is unknown when the ULID itself is invalid.
- The Delivery resource has no `purchase_order_id` (the api.md Delivery shape has none).
- `docs/AI_LOG.md` was not appended (outside this ticket's ownership); the coordinator should add the entry.

## Reviewer findings

Reviewer: Opus 5.5. Verdict: approve after the Low items are resolved or accepted. No blockers. Pint clean, 233 tests pass, and 11 of 12 mutations are killed (the survivor is finding 2).

| # | Severity | File:line | Finding | Resolution |
|---|---|---|---|---|
| 1 | Low | app/Services/ReceivingService.php:109 | The `purchase_order.closed` description says "every line fully received". That is false for an under-tolerance close (T9e: 960 of 1000 closes with 40 under-delivered), and the manager sees this sentence in the audit trail. Say "every line complete" instead. | Fixed: docblock now says comparisons are of instants, and the `->utc()` that matters is before saving; the redundant `->utc()` in `after()` is removed |
| 2 | Low | app/Http/Requests/V1/StoreDeliveryRequest.php:111-124 | The docblock says the comparisons must be normalised to UTC. They do not need to be: Carbon's `isFuture()` and `lt()` compare instants, so removing `->utc()` at :124 still passes every test (mutation M9a survives). The `->utc()` that matters is at :154, before storage, because Eloquent writes the clock time without converting. Fix the comment, and optionally the redundant calls, so the explanation given live is the right one. | Fixed: pieces read "pcs" ("pc" for 1) through `OverDelivery::quantity()`; the T9d test pins "11 pcs is above the 10 pcs limit" |
| 3 | Low | app/Exceptions/Domain/OverDelivery.php:49,56 | For pieces the message reads "Bun: 11 piece is above the 10 piece limit". ui.md shows pieces as "pcs". `units.js` does not exist yet (PTY-11), so `rewriteError` cannot be checked yet. Either use the unit's display label in the message, or write the message format into PTY-11 ("<number with commas> <unit value>", unit being g, ml or piece). | Accepted: recorded under deviations in Builder notes |
| 4 | Low | app/Http/Requests/V1/StoreDeliveryRequest.php:95-103 | validation.md G7 asks for "Line 2 (Beef): ..." and an `attributes()` mapping. The messages use "Line :position:" with no ingredient name, and this is not listed as a deviation. That is defensible, because the name is unknown when the ULID itself is invalid, but record it as a deviation. | Fixed: every JSON response in ReceivingTest goes through an `api()` helper that calls `assertNoIntegerIds()` |
| 5 | Low | tests/Feature/Purchasing/ReceivingTest.php | The ticket says to call `assertNoIntegerIds()` in every test. It is called in 5 tests, which cover every response shape (201, 422 `over_delivery`, 409, E24), but not the 422 validation responses or the T9 tests. That is a reasonable call; record it as a deviation, or add the call. | Fixed: F9 now says 2 rows |
| 6 | Info | docs/flows.md (F9) | F9 says a close adds 1 activity_log row. The code adds 2: the named `purchase_order.closed` and the LogsActivity `updated` row for the status change. That matches how F8 counts the received move. The flows doc is wrong, not the code. | Noted, no change (owner decision) |
| 7 | Info | app/Services/StockLedger.php (PTY-6) | The stock log's `reference` falls back to the integer key for delivery lines, which have no ULID. Deliveries are the first code path that reaches this. It is a log, not the API or the audit trail, so D-031 is arguably not breached, but the owner should decide. | Noted, no change; PTY-12 to omit `received_at` for "now" |
| 8 | Info | app/Http/Requests/V1/StoreDeliveryRequest.php:126 | `isFuture()` is strict. A browser clock a few seconds ahead that sends `toISOString()` for "now" would get a 422. PTY-12 should omit `received_at` when the user means now. | Orchestrator |
| 9 | Info | docs/AI_LOG.md | The PTY-8 entry is still owed by the coordinator (the builder noted this). | open |

Mutation results (full suite after each mutation, then restored):
- M1, live ingredient tolerance instead of the snapshot: killed (3 tests fail).
- M2, cap dropped: killed (4).
- M3, the percentage rounded up: killed (2).
- M4, the `max(1)` floor removed: killed (1, the unit test; there is no feature test).
- M5, close when ANY line is complete: killed (1).
- M6, no sent-to-received move: killed (15).
- M7, receiving on a draft allowed: killed (1).
- M8, the line-belongs-to-this-PO scope dropped: killed (1).
- M9a, `->utc()` removed in `after()`: **survived** (finding 2).
- M9b, `->utc()` removed in `passedValidation()`: killed.
- M9c, every `->utc()` removed: killed.
- M10, `DB::transaction` dropped: killed (the audit-failure test).
