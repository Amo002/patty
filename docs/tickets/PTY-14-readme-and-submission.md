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
- [ ] Decisions section summarises Q-001 to Q-007 and D-004 to D-006 in plain sentences
- [ ] "What next" has four concrete items. Candidates: stock take and adjustments, waste, purchase unit conversion, over-delivery tolerance, roles, accounting postings, multi-branch.
- [ ] Clean-clone check: clone into a temp folder, follow the README word for word, and the app and tests run. Record the result in the ticket.
- [ ] AI_LOG tidied. Nothing removed, summaries added.
- [ ] Release PR `develop` into `main`, tag `v1.0.0`, GitHub Release notes
