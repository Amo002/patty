# Q-013 Should database ids appear in URLs and the API?

Status: closed (2026-10-01)

## Context

`/purchase-orders/3` leaks volume and invites guessing other ids.

## Answer

**Never.** ULIDs are the public identifiers, and human document numbers are for display. Recorded as D-031.
