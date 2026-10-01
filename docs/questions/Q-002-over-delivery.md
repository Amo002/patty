# Q-002 Can a delivery exceed what was ordered?

Status: closed (2026-10-01)
Blocks: PTY-8

## Context

Order 1000 g beef. The supplier sends 1050 g. It happens in real kitchens. Weights are not exact, and suppliers round up.

## Options

**A. Reject any line where received > outstanding (422), and save nothing from that delivery.**
- Pro: `received <= ordered` is a hard invariant. Outstanding can never go negative. Simple to explain and test.
- Con: the manager must record only what was ordered, or ask for the PO to be amended. The extra 50 g is not tracked.

**B. Allow over-delivery up to a tolerance (for example 5%), with the excess going to stock.**
- Pro: matches reality.
- Con: a tolerance setting, outstanding that can go negative, and more states to test.

**C. Allow any over-delivery.**
- Con: a typo (10000 instead of 1000) silently inflates stock.

## Recommendation

**A.** Reject with a clear message: "Beef: received 1050 g exceeds outstanding 1000 g". The README notes that real systems allow a tolerance, and that it is a natural extension.

## Answer

**B, with a 5% tolerance** (differs from the recommendation).

- Per line, total received may reach `intdiv(ordered x 105, 100)`, in integers, rounding down: 1000 g allows 1050 g; 10 buns allows 10.
- Stock rises by the full quantity received, excess included. Outstanding is `max(0, ordered - received)`; the excess is shown as over-received.
- Beyond the limit, the whole delivery is rejected with 422.
- The tolerance is `config('patty.over_delivery_tolerance_percent')`, so changing it is one line.

Recorded as D-011.
