# Progress

The first file to open every session. Newest entry on top.

## Now

- **Release:** working towards v0.1.0 (tooling and docs)
- **Active ticket:** PTY-2 (awaiting Mohamad)
- **Waiting on Mohamad:**
  - read and merge PR #2;
  - confirm or rewrite the D-011 rationale in his own words.
- **Next:** release PR v0.1.0, then build order PTY-3, then PTY-16, then PTY-4 onwards (see the board)
- **Deadline:** submit Sunday 4 October, target 11:00 Amman (hard limit 12:00)
- **Cut order if late:** PTY-17 browser tests, PTY-15 Docker, Postman scenario tests, the Activity page in the UI

## Log

### 2026-10-01 (Thu)
- Read the brief and planned the work. Project named Patty (PTY).
- PTY-1 done: Laravel 13, Pest, no-build UI, CI, branch protection. PR #1 merged.
- PTY-2: docs set, questions Q-001 to Q-007, tickets PTY-3 to PTY-15 written.
- All questions answered. Q-002 changed to a 5% over-delivery tolerance (D-011).
- Architecture round 2: `architecture.md` added; D-017 to D-026 recorded:
  - no async, no finance;
  - API envelope with 409/422;
  - Incoming column;
  - activitylog audit;
  - per-domain logs;
  - comment standard;
  - DB triggers, rate limit, headers;
  - Hugeicons;
  - opt-in browser tests.
- New tickets PTY-16 (API foundation) and PTY-17 (browser tests, stretch).
