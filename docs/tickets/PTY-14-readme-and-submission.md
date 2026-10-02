# PTY-14 README and submission

| Field | Value |
|---|---|
| Type | Task |
| Phase | 6 Release and submission |
| Status | In Progress |
| Weight | S |
| Builder | Opus 5.5 (orchestrator; agents at the usage limit) |
| Reviewer | Opus 5.5 |
| Branch | `PTY-14-readme-and-submission` |
| Release | v1.0.0 |
| Depends on | all |

## Goal

The README the brief asks for, verified from a clean clone.

## Acceptance criteria

- [x] README sections, as the brief lists them:
  1. how to run and test;
  2. data model;
  3. decisions where the brief was unclear, and why;
  4. how AI was used (link to AI_LOG);
  5. what next.
- [x] Decisions section summarises Q-001 to Q-007 and the key engineering decisions (D-004 ledger, D-006 freshness, D-017 no async, D-018 no finance, D-019 envelope, D-020 incoming, D-021 audit, D-024 defence in depth) in plain sentences
- [x] Section "How Patty joins the ERP", from architecture.md section 8
- [x] Section "Logs versus audit trail": where each lives and who it is for
- [ ] GIFs from `docs/media/` embedded under "What it looks like". **Dropped:** Mohamad did not ask for the browser recordings; the README describes the screens instead.
- [x] Security review on the final code: the PTY-21 Opus pass (#26), 8 findings triaged. The Fable pass was dropped (D-047).
- [x] "What next" has four concrete items. Candidates: reorder levels and suggested POs, stock take with variance, waste, purchase unit conversion, roles and permissions, accounting postings, multi-branch, Larastan, optimistic locking on draft edits.
- [x] Clean-clone check: clone into a temp folder, follow the README word for word, and the app and tests run. Record the result in the ticket.
- [x] AI_LOG tidied: a summary added at the top, and every entry left unchanged.
- [ ] Release PR `develop` into `main`, tag `v1.0.0`, GitHub Release notes

## Progress

### Clean-clone check, 2026-10-02 (branch `PTY-14-readme-and-submission`, before #26 to #28 merged)

The repository was cloned from GitHub into a temp folder on Windows 11, and the README was followed word for word:
- `composer setup`: exit 0 in 153 s, mostly the dependency download. It ended with "DemoSeeder ... DONE".
- `php artisan serve`: `/`, `/ingredients`, `/purchase-orders`, `/pos`, `/activity` and `/api/v1/dashboard` all return 200. The dashboard shows 12 ingredients, 1 negative (cheese), 2 open orders and 3 outstanding lines, which matches the seed.
- `php artisan test`: 420 passed.
- The Windows read-only folder problem seen in agent worktrees did not occur on a fresh clone.

### Final clean-clone check, 2026-10-02 (commit `3dc24b9`, with #26, #27 and #28 merged)

The branch was cloned fresh from GitHub and the README followed again:
- `composer setup`: exit 0 in 159 s.
- `php artisan serve`: every page (`/`, `/ingredients`, `/suppliers`, `/menu`, `/purchase-orders`, `/pos`, `/activity`) and `/api/v1/dashboard` returned 200.
- A plain form POST to the API returned 415, which is the S6 fix working.
- `php artisan test`: 429 passed.
- The README's newman command for the Postman Scenario: 21 requests and 47 assertions, all passing with the JSON-only rule active.

Done so far: the README sections, the decisions section, "How Patty joins the ERP", "Logs versus audit trail", the four "What next" items, and the clean-clone check above.

### Not done yet

- **Release:** a PR from `develop` into `main`, the `v1.0.0` tag and the GitHub Release notes, after everything is merged.

### Fact checks made while writing

- A first draft said pieces get "no rounding room" on over-delivery. That is wrong: the 5% applies to pieces too (the demo receives 315 buns for 300). Pieces only lack the absolute cap. It was corrected before commit.
- Every test path in the "Test it" table was checked to exist. The below-zero and trigger claims were checked against `SalesTest` (T7) and `ConstraintsTest` (T19).
