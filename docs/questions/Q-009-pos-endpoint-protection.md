# Q-009 Should the POS endpoint be protected?

Status: closed (2026-10-01)

## Context

Anyone who can reach the server can post sales and move stock.

## Options

**A.** Fully open.

**B.** An optional key, `POS_API_KEY`, off by default; a 401 when it is set and doesn't match.

## Answer

**B.** Recorded as D-028.
