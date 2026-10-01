# PTY-21 Security review

| Field | Value |
|---|---|
| Type | Task |
| Phase | 5 Security |
| Status | To Do |
| Weight | M |
| Builder | Mohamad with Claude on Opus 5.5 (Fable 5.1 optional, if credits become available) |
| Reviewer | Mohamad |
| Branch | `PTY-21-security-review` |
| Release | v1.0.0 |
| Depends on | All build tickets merged into `develop` |

**Never cut.** It is required before v1.0.0.

## Goal

An independent, high-capability security pass over the finished code, with every finding resolved or consciously accepted.

## Contract

[security.md](../security.md) threats S1 to S17.

## Acceptance criteria

- [ ] `/security-review` run on Opus 5.5 (or Fable 5.1 if credits are available) against `develop`, with security.md supplied as context
- [ ] Optional: `/code-review ultra` run (Mohamad triggers it)
- [ ] Every finding in the table below triaged
- [ ] Every "fix" has its own `PTY-21:` commit and, where meaningful, a regression test
- [ ] Every "accept" has a written reason
- [ ] Manual checks:
  - search for `x-html`, `innerHTML`, `DB::raw`, `whereRaw`, `selectRaw`;
  - `composer audit` is clean;
  - `APP_DEBUG=false` 500 response checked by hand.
- [ ] README "Security" section written

## Findings

| # | Source | Severity | File:line | Finding | Decision (fix / accept / false positive) | Commit or reason |
|---|---|---|---|---|---|---|

## Done means

1. `php artisan test` is green after the fixes.
2. The findings table has no empty Decision cell.
3. README Security section is present.
