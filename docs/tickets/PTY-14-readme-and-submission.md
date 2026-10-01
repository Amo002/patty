# PTY-14 README and submission

| Field | Value |
|---|---|
| Type | Task |
| Status | To Do |
| Weight | S |
| Builder | Sonnet 5.5 |
| Reviewer | Opus 5.5 |
| Branch | `PTY-14-readme-and-submission` |
| Release | v1.0.0 |
| Depends on | all |

## Goal

The README the brief asks for, verified from a clean clone.

## Acceptance criteria

- [ ] README sections, as the brief lists them:
  1. how to run and test;
  2. data model;
  3. decisions where the brief was unclear, and why;
  4. how AI was used (link to AI_LOG);
  5. what next.
- [ ] Decisions section summarises Q-001 to Q-007 and the key engineering decisions (D-004 ledger, D-006 freshness, D-017 no async, D-018 no finance, D-019 envelope, D-020 incoming, D-021 audit, D-024 defence in depth) in plain sentences
- [ ] Section "How Patty joins the ERP", from architecture.md section 8
- [ ] Section "Logs versus audit trail": where each lives and who it is for
- [ ] GIFs from `docs/media/` embedded under "What it looks like"
- [ ] `/security-review` run on the final branch, findings recorded
- [ ] "What next" has four concrete items. Candidates: reorder levels and suggested POs, stock take with variance, waste, purchase unit conversion, roles and permissions, accounting postings, multi-branch, Larastan, optimistic locking on draft edits.
- [ ] Clean-clone check: clone into a temp folder, follow the README word for word, and the app and tests run. Record the result in the ticket.
- [ ] AI_LOG tidied. Nothing removed, summaries added.
- [ ] Release PR `develop` into `main`, tag `v1.0.0`, GitHub Release notes
