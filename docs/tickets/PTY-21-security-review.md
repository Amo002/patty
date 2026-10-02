# PTY-21 Security review

| Field | Value |
|---|---|
| Type | Task |
| Phase | 5 Security |
| Status | Done |
| Weight | M |
| Builder | Opus 5.5 locally (the Fable pass was dropped, D-047) |
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

- [x] Security pass run on Opus 5.5 locally against `develop`, checked against security.md. The Fable 5.1 pass was dropped: no credits (D-047).
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
| 1 | Orchestrator, PTY-22 review | Medium | `bootstrap/app.php` (API middleware) | Cross-site request forgery. With no login, a page open in the same browser could send a plain form POST to `http://localhost:8000/api/v1/demo/clear` (or any write), and the browser sends it without asking first. S6 claimed there were "no credentials to ride on", but none are needed. | fix | `2ecc650`: `RequireJsonWrites` returns 415 for any write not declared `application/json`. api.js declares JSON on body-less writes. `JsonWritesTest` |
| 2 | Opus 5.5, verifying fix 1 | Medium | `config/cors.php` (framework default) | Fix 1 depends on the preflight being refused. Laravel's default CORS config answered preflights from any origin with `Access-Control-Allow-Origin: *` (checked live against `php artisan serve`), so a malicious page could still send JSON writes. | fix | `303dd7f`: CORS is off (no paths, no origins). A test pins that a foreign-origin preflight gets no allow header. |
| 3 | Opus 5.5 | Low | `ReceivingService.php:162`, name rules | Log forging. An over-delivery logs the ingredient name to `purchasing.log`, and names accepted line breaks, so a name could write a fake second log line (S16). | fix | `59b6ed1`: ingredient, supplier and menu item names refuse control characters with a 422. `NameControlCharactersTest` |
| 4 | Opus 5.5 | Medium | `compose.yaml` (PTY-15) | The Docker setup published port 8000 on every host interface (`0.0.0.0`, verified with `docker compose port`), with `APP_ENV=local`. Anyone on the same network could reach the app with no login, including the demo reset. | fix | PTY-15 commit `69ce0fb` (PR #25): `127.0.0.1:8000:8000`, verified. |
| 5 | Opus 5.5 | Low | all routes | DNS rebinding. A malicious domain that re-points itself to 127.0.0.1 becomes "same origin", which gets past fixes 1 and 2. | accept | It needs an attacker targeting this machine while the app runs, and the brief rules out auth. The fix is a Host allow-list (Laravel `trustHosts`), which would also block any other hostname a reviewer uses. Listed as a next step with roles and login (S2). |
| 6 | Opus 5.5 | Info | E25 (S1) | The POS key is optional, and open by default. | accept | By design (D-028), so reviewers and Postman need no setup. Setting `POS_API_KEY` turns it on, compared in constant time. |
| 7 | Manual search | Info | `components/icon.blade.php:20` | `{!! $svg !!}` is unescaped output. | false positive | The name comes from our own templates, never from a request, and is checked against `^[a-z0-9-]+$`. The SVG files are ours (built by `scripts/build-icons.php`). Attributes go through `toHtml()`. |
| 8 | Manual search | Info | `StockLedger.php:119`, `StockQuery.php:157`, `SaleService.php:152`, stock movements migration | `DB::raw`, `selectRaw` and `DB::statement` are in use. | false positive | Every one is a constant string with no request data: the sums, the window function and the append-only triggers (S3 holds). |

### Manual checks

- Search for `x-html`, `innerHTML`, `outerHTML`, `insertAdjacentHTML`, `{!!`, `DB::raw`, `whereRaw`, `selectRaw`, `DB::statement`, `DB::unprepared`: results are rows 7 and 8 only.
- `composer audit`: "No security vulnerability advisories found."
- `APP_DEBUG=false` 500, forced by pointing a throwaway server at a database file that does not exist. The response was `{"success":false,"message":"Something went wrong on our side.","code":"server_error","errors":{}}` with an `X-Request-Id`, and no path, SQL or trace (S8).
- S4: every model declares `$fillable`. S15: no `.env`, `.sqlite`, key or pem file is tracked.

### Fable pass: dropped

Mohamad has no Fable credits, so the second, independent pass on Fable 5.1 did not run (D-047). The Opus pass above is the security review of record. `/code-review ultra` was optional and was not run.

## Done means

1. `php artisan test` is green after the fixes.
2. The findings table has no empty Decision cell.
3. README Security section is present.
