# Workflow

Jira-style. Project **Patty**, key **PTY**.

## Phases

The project runs in phases, each with a gate. The journal of every phase (goal, how it went, decisions, lessons) is in [progress.md](progress.md).

```
0 Discovery & planning -> 1 Specification -> 2a Backend design -> 2b Frontend design
                                                     |                     |
                                                     v                     v
                                              3a Build backend      3b Build frontend
                                                     \                     /
                             4 Review & testing (continuous, plus a final pass)
                                                     |
                                   5 Security -> 6 Release & submission -> 7 Interview prep
```

| Gate | Rule |
|---|---|
| Into 3a | Backend design docs merged (api.md, validation.md, flows.md, data.md) |
| Into 3b | Mohamad's written approval of the frontend design in progress.md |
| Into 6 | Every PTY-21 security finding fixed or accepted with a reason |
| Any PR | Updates progress.md (enforced by CI from PTY-16) |

Phases 2b and 3a can run at the same time. Every ticket names its phase.

## One ticket, one pull request (rule)

- Every PR contains the changes of **exactly one ticket**, the one in its title. No second ticket, no unrelated housekeeping, no "while I am here" fixes. Anything else gets its own small ticket and its own PR.
- After each ticket, Mohamad reviews the **full diff** of its PR and approves it by merging. Nothing merges without that.
- Before a PR is opened, the orchestrator checks `git diff --stat origin/develop..HEAD` contains only that ticket's files.
- Follow-up commits for a different ticket are never pushed to an open PR's branch.

## Parallel work

Two tickets can be built at the same time, each in its own git worktree and branch, with one reviewer shared between them (D-040). The board shows which pairs run together.

## The flow (per ticket)

```
Ticket (docs/tickets/PTY-N-slug.md, status To Do)
  -> branch PTY-N-slug from develop                         [In Progress]
  -> builder agent implements + tests, small commits
  -> reviewer agent (one tier higher) reviews the diff       [In Review]
  -> builder fixes findings
  -> Mohamad reviews: reads every line, can explain it, runs manual QA   [Awaiting Mohamad]
  -> PR into develop, CI green, Mohamad merges               [Done]
  -> at a release point: PR develop -> main, tag vX.Y.Z, GitHub Release
```

Nobody pushes to `main` or `develop` directly. Branch protection enforces this for everyone, admins included.

## Naming

| Thing | Format | Example |
|---|---|---|
| Ticket | `PTY-N` | `PTY-8` |
| Ticket file | `docs/tickets/PTY-N-slug.md` | `docs/tickets/PTY-8-receiving-deliveries.md` |
| Branch | `PTY-N-slug` | `PTY-8-receiving-deliveries` |
| Commit | `PTY-N: Imperative sentence` | `PTY-8: Close purchase order when all lines fully received` |
| PR title | `PTY-N: Ticket title` | `PTY-8: Receiving deliveries` |
| Release PR | `Release vX.Y.Z` | `Release v0.3.0` |
| Question | `docs/questions/Q-NNN-slug.md` | `Q-001-below-zero-sale.md` |
| Decision | `D-NNN` in `docs/decisions.md` | `D-004` |

## Commits

- Small and logical: one idea per commit. The suite passes at every commit where possible.
- Message says what changed and, if not obvious, why. Never `fix`, `wip` or `update`.
- **Author is Mohamad Alberqdar. No AI co-author trailers.** AI use is disclosed in [AI_LOG.md](AI_LOG.md).
- Never squash, never force-push, never rewrite pushed history.
- Never commit `.env`, `vendor/`, `database/*.sqlite` or credentials.

## Definition of Ready (before a ticket starts)

- Acceptance criteria written and linked to [requirements.md](requirements.md).
- Every question it depends on is `closed`, or its recommended answer is explicitly accepted.
- Builder and reviewer models assigned (see [agents.md](agents.md)).

## Definition of Done

- [ ] All acceptance criteria met.
- [ ] The tests named in the ticket exist and fail if the logic is removed.
- [ ] `vendor/bin/pint --test` and `php artisan test` pass locally and in CI.
- [ ] Reviewer findings resolved or explicitly rejected with a reason in the ticket.
- [ ] Mohamad has read every changed line and can explain it without notes.
- [ ] Manual QA section in [testing.md](testing.md) run for affected features.
- [ ] [progress.md](progress.md), [AI_LOG.md](AI_LOG.md) and [tickets/BOARD.md](tickets/BOARD.md) updated.
- [ ] Any new decision recorded in [decisions.md](decisions.md).

## Questions (BRD)

When the brief is unclear, the orchestrator opens `docs/questions/Q-NNN-slug.md` with options and a recommendation, status `open`. Mohamad writes his answer in the file and sets status `closed`. The orchestrator then records it as a decision in [decisions.md](decisions.md) and updates the requirements.

## Releases

| Version | Contents |
|---|---|
| v0.1.0 | Tooling and docs (PTY-1, PTY-2) |
| v0.2.0 | Data model, API foundation, stock ledger, catalogue APIs (PTY-3, PTY-16, PTY-4 to PTY-6) |
| v0.3.0 | Purchasing, receiving, sales, visibility APIs (PTY-7 to PTY-10) |
| v0.4.0 | Web UI (PTY-11, PTY-12) |
| v1.0.0 | Postman, Docker, browser tests (stretch), README, clean-clone check: the submission (PTY-13 to PTY-15, PTY-17) |
