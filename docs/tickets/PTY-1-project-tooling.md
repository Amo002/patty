# PTY-1 Project tooling

| Field | Value |
|---|---|
| Type | Task |
| Status | Done |
| Weight | S |
| Builder | Opus 5.5 (orchestrator, before the agent workflow existed) |
| Reviewer | Mohamad |
| Branch | `PTY-1-project-tooling` (PR #1, merged) |
| Release | v0.1.0 |

## Goal

A runnable Laravel 13 project with Pest, no JS build step, and CI that enforces style and tests.

## Acceptance criteria

- [x] Laravel 13 scaffold committed
- [x] Pest replaces PHPUnit; tests use fresh in-memory SQLite
- [x] Vite and npm removed; `composer setup` installs everything in one command
- [x] GitHub Actions runs Pint and Pest on every push and PR to `main` and `develop`
- [x] `main` and `develop` protected: PR required, CI required, no force-push, enforced for admins

## Builder notes

- The scaffold's post-install step failed because the Windows folders carried the ReadOnly attribute (see AI_LOG, 2026-10-01).
- The scaffold's `true === true` unit test was deleted as filler.

## Mohamad review

Approved: 2026-10-01 (merged PR #1)
