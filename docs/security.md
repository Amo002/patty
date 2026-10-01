# Security

Written **before** the build, so builders code against it and reviewers check against it. The final review is PTY-21 (phase 5).

## Context and stance

Patty is a single-branch back-office tool. The brief says no authentication. We accept that knowingly and contain it:
- The development server binds to `127.0.0.1` (the README says so).
- The only machine-to-machine endpoint (POS sales) can be protected by an optional key.
- Every other risk below is handled in code, not by assuming a friendly network.

## Threat model

| # | Threat | Surface | Mitigation | Verified by | Ticket |
|---|---|---|---|---|---|
| S1 | Anyone who can reach the server posts sales and moves stock | E25 | Optional `POS_API_KEY` with `X-POS-Key`, constant-time comparison (D-028); rate limit 120/min (D-024) | T20, T22 | PTY-9, PTY-16 |
| S2 | No authentication on manager actions | all | By design (brief). Server bound to localhost. Roles and permissions listed as the first next step. | README | PTY-14 |
| S3 | SQL injection | every query | Eloquent and query builder bindings only. No `DB::raw`, `whereRaw` or `selectRaw` with request data. The window-function query uses bindings. | reviewer search | all |
| S4 | Mass assignment | models | `$fillable` on every model; services receive `validated()` data only (validation.md G1) | review | all |
| S5 | Cross-site scripting | UI rendering API data | Alpine `x-text` / `textContent` only. **`x-html` and `innerHTML` with data are forbidden.** Blade `{{ }}` escaping. Security headers. | reviewer search for `x-html` and `innerHTML` | PTY-11 onwards |
| S6 | CSRF | web routes | Pages are GET-only shells. The API is stateless and token-free, with no session cookie on api routes, so a forged cross-site request has no credentials to ride on. Documented rather than mitigated. | review | PTY-16 |
| S7 | Clickjacking, MIME sniffing, referrer leaks | responses | `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy: same-origin`, `Permissions-Policy` | T17 | PTY-16 |
| S8 | Information leakage in errors | 500s | Generic envelope message. No trace, SQL or path when `APP_DEBUG=false`. Detail goes to `laravel.log` with the request id only. | T15e | PTY-16 |
| S9 | Enumeration through sequential ids | URLs, API | ULIDs only; numeric ids give 404; document numbers are display-only (D-031) | T25 | PTY-3, PTY-16 |
| S10 | Oversized or abusive payloads | arrays, strings | Max 50 lines, bounded quantities and string lengths (validation.md) | T21 | all |
| S11 | Replay or double-counting | E25 | Idempotency by `pos_reference`, conflict detection, unique index as the final guard (D-014, D-029) | T10, T23 | PTY-9 |
| S12 | Tampering with stock history | stock_movements | Append-only in code (StockLedger only, model guards) **and** in the database (triggers) (D-024) | T19 | PTY-3, PTY-4 |
| S13 | Demo clear, seed or reset reachable in a real deployment | E30 to E32 | Routes registered only when `APP_ENV=local`; artisan commands confirm before running | T24 | PTY-22 |
| S14 | Vulnerable dependencies | composer | `composer audit` in CI on every PR | CI | PTY-16 |
| S15 | Secrets committed | git | `.env` ignored; `.env.example` holds no secrets; the POS key is empty by default | review | all |
| S16 | Log injection or PII in logs | logs | Structured context arrays, no free-form user strings in messages, no personal data stored at all | review | all |
| S17 | Third-party images: licence, privacy, availability | seed photos | Only photos whose licence page was checked; credited in CREDITS.md; no people or brands; served from our own `public/`, never hot-linked | review of CREDITS.md | PTY-22 |

## Phase 5: security review (PTY-21)

Run by Mohamad with Claude on **Opus 5.5** (Fable 5.1 optionally, if credits become available), against the final `develop` (D-039):
1. `/security-review` over the whole codebase, with this file as context.
2. Optionally, `/code-review ultra`: the multi-agent cloud review. Mohamad triggers and pays for it himself.
3. Every finding is triaged in `docs/tickets/PTY-21-security-review.md` as **fix**, **accept with reason**, or **false positive**.
4. Fixes go in as their own `PTY-21:` commits, with a regression test where one makes sense.
5. A short "Security" section in the README: this threat model in brief, the review date, and its outcome.
