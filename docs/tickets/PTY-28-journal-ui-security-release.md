# PTY-28 Journal: UI build, Docker, security pass

| Field | Value |
|---|---|
| Type | Task |
| Phase | 4 Review and testing (journal) |
| Status | Awaiting Mohamad (PR) |
| Weight | S |
| Builder | Opus 5.5 (orchestrator) |
| Reviewer | Mohamad |
| Branch | `PTY-28-journal-ui-security-release` |
| Release | v1.0.0 |

## Goal

Bring the journal up to date after the UI build (PTY-10, PTY-12, PTY-18, PTY-19, PTY-22), the Docker verification (PTY-15) and the Opus security pass (PTY-21), in its own PR.

## Acceptance criteria

- [x] AI_LOG entry: the UI reviewer catches, the usage limit, the guessed credential header, Docker's first real run, the security pass including the incomplete first CSRF fix, and the orchestrator's own slips
- [x] progress.md: phases 3a and 3b done, phase 4 summary, phase 5 in progress, phase 6 next
- [x] BOARD.md status summary and every merged ticket's Status set to Done
- [x] The field-name mismatch noted earlier (`ordered` / `received` in ui.md and api.md): checked, already gone. api.md uses `quantity_ordered`, `quantity_received` and `quantity_outstanding`, and ui.md names no fields. Nothing to change.
