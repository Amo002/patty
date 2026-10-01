# Q-005 What if the POS sends the same sale twice?

Status: closed (2026-10-01)
Blocks: PTY-9

## Context

A POS calls our endpoint over a network. If the response is lost, the POS retries. Without protection, one burger deducts stock twice. That is a "stock numbers that are always right" failure that no amount of arithmetic testing would catch.

## Options

**A. Ignore it.** Every request is a new sale.

**B. Optional `pos_reference` field, unique.** If a sale with that reference already exists, return the original sale with 200 and move no stock. Requests without a reference behave as in A.

**C. Required `pos_reference`.**
- Con: makes the UI's POS simulator and quick API testing more awkward. The UI can still generate one automatically.

## Recommendation

**B.** One nullable unique column and one lookup before writing. A small cost for a strong correctness signal. The UI's POS simulator generates a reference automatically, so the demo shows the protection working.

## Answer

**B, as recommended.** Optional unique `pos_reference`; a replay returns the original sale with 200 and moves no stock. Recorded as D-014.

## Addendum (2026-10-01)

Reusing a reference with a **different** payload returns 409 `idempotency_conflict` (Q-010, D-029).
