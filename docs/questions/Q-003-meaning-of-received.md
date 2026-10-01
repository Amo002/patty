# Q-003 What does the `received` state mean?

Status: open
Blocks: PTY-7, PTY-8

## Context

The brief lists four states: draft, sent, received, closed. It also says "The order closes when everything has been received". So "fully received" leads to `closed`, which leaves the meaning of `received` open.

## Options

**A. `received` = at least one delivery recorded, something still outstanding ("partially received").**
- Flow: `draft -> sent -> received -> closed`.
- The first delivery moves `sent -> received`. The delivery that clears the last outstanding quantity moves the order to `closed`.
- A single delivery that covers everything goes `sent -> received -> closed` inside one transaction, so the order still passes through every state in order.
- Pro: every state means something distinct, and `received` is exactly the "open with outstanding" state the owner cares about.

**B. `received` = fully received; `closed` = a separate manual step (invoice matched, paperwork done).**
- Con: contradicts "the order closes when everything has been received", which says closing is automatic.

## Recommendation

**A.** The UI labels `received` as **"Partially received"** so its meaning is obvious.

Resulting transition map (the only place it is defined is `PurchaseOrderStatus`):

| From | To | Trigger |
|---|---|---|
| draft | sent | Manager sends |
| sent | received | First delivery |
| received | closed | Nothing outstanding (automatic), or short-close (Q-004) |

All other moves are rejected.

## Answer

<!-- Mohamad: write your answer here, then set Status: closed -->
