# Q-001 What happens when a sale would take stock below zero?

Status: closed (2026-10-01)
Blocks: PTY-9
Named in the brief: "You'll hit decisions the brief doesn't answer, like what happens when a sale would take stock below zero."

## Context

The POS reports sales that **already happened**. The customer has paid and has the burger. If the system says beef is 100 g and a burger needs 150 g, then either the system is wrong (a delivery was not recorded, a count was off, something was wasted) or the sale is.

## Options

**A. Reject the sale (422).**
- Pro: stock can never be negative.
- Con: the sale disappears from the system while it exists in the real world. Stock is now wrong in the other direction, and the error is hidden. The POS has nothing useful to do with the rejection.

**B. Accept the sale, record the movement, and flag the ingredient as negative.**
- Pro: the system records reality. Negative stock is real information: it means an unrecorded delivery, a miscount or loss, which is exactly what the owner needs to see. It is fixed later with a delivery or an adjustment, not by losing data.
- Con: balances can be negative, so the UI must make that obvious.

**C. Accept, but clamp stock at zero.**
- Con: silently loses the difference. The ledger no longer sums to reality. Not defensible.

## Recommendation

**B.** Accept, record, flag negative in red with the label "Negative". This is the standard behaviour in restaurant POS-integrated inventory, and it keeps the ledger honest.

## Answer

**B, as recommended.** Accept the sale, record the movements, flag the ingredient as Negative. Recorded as D-010.
