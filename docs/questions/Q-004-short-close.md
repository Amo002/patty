# Q-004 Can an order be closed with quantity still outstanding?

Status: closed (2026-10-01)
Blocks: PTY-7, PTY-8

## Context

The supplier sends 600 of 1000 g beef and says the rest is not coming. Under the brief's literal rule ("closes when everything has been received") this order stays open forever and clutters the open-orders view.

## Options

**A. No manual close. Only full receipt closes an order.**
- Pro: smallest state machine.
- Con: dead orders stay "open" forever, which makes the open-orders view (feature 6) untrustworthy.

**B. Allow a manual "short-close" of a `received` order.**
- `received -> closed` by manager action, with `short_closed = true` recorded.
- The outstanding quantity is shown as "not delivered" on the closed order, and stock is untouched (it never arrived).
- Pro: keeps the open list honest. It is one extra action on an existing transition, not a new state.

**C. Add a `cancelled` state.**
- Con: a fifth state the brief does not list. Better kept as a live-extension candidate.

## Recommendation

**B.** Only from `received`. A `sent` order with nothing delivered cannot be short-closed. That would be a cancellation, which is out of scope (option C, a likely live-extension request).

## Answer

**B, as recommended.** Manual short-close from `received` only; `short_closed = true`; missing quantity shown as not delivered; stock untouched. Recorded as D-013.
