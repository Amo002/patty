# PTY-24 Repo hygiene: one ticket per PR, ignore local agent state

| Field | Value |
|---|---|
| Type | Task |
| Phase | 3a Build backend (process) |
| Status | Done |
| Weight | S |
| Builder | Opus 5.5 (orchestrator) |
| Reviewer | Mohamad |
| Branch | `PTY-24-repo-hygiene` |
| Release | v0.2.0 |

## Goal

Make two working rules explicit in the repo.

## Acceptance criteria

- [x] `workflow.md`: **one ticket = one PR**. No mixed changes, no housekeeping riding along. Mohamad reviews the full diff of each PR and approves it by merging (his rule, 2026-10-01).
- [x] `.gitignore` ignores `/.claude/` (local Claude Code state such as agent worktrees), so it can never be committed.

## Mohamad review

Approved:
