# Q-002 Can a delivery exceed what was ordered?

Status: open
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

<!-- Mohamad: write your answer here, then set Status: closed -->
