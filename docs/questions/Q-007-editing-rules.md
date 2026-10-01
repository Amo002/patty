# Q-007 What can be edited, and when?

Status: open
Blocks: PTY-6, PTY-7

## Context

Editing records that past events depend on can silently change history.

## Recommendation (one rule per record)

| Record | Editable? | Why |
|---|---|---|
| Ingredient name | Always | Display only |
| Ingredient unit | Only while it has no stock movements | Changing it would reinterpret every past quantity |
| Supplier | Always | Display only |
| Menu item name | Always | Display only |
| Recipe lines | Always. Affects **future** sales only | Past sales already wrote their movements; those are never rewritten |
| PO lines | Only in `draft` | Once sent, the supplier has the order. Changing it would desync outstanding from what was asked for. |
| PO supplier | Only in `draft` | Same |
| Deliveries | Never (no edit, no delete) | A mistaken delivery is corrected by a future adjustment movement (out of scope now) |
| Sales | Never | Same |
| Stock movements | Never | Append-only ledger |

Deletes: nothing that has stock movements or is referenced by an order or sale can be deleted. Draft POs can be deleted. Out of scope otherwise.

## Answer

<!-- Mohamad: write your answer here, then set Status: closed -->
