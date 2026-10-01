# Decisions

Append-only. Each entry records what was chosen, what was rejected, and why, so the same discussion does not happen twice. Superseded decisions are marked, never deleted.

---

## D-001 No authentication, roles or media uploads
- **Chosen:** no login, no users, no roles, no file uploads.
- **Rejected:** spatie/laravel-permission with Manager and Kitchen roles; spatie/laravel-medialibrary for ingredient images.
- **Why:** the brief says no authentication is needed. A login screen is friction for the reviewer, and roles mean code to defend that does not answer any grading criterion. Nothing in the brief has an image. Listed under README "next steps".
- **Date:** 2026-10-01

## D-002 Branches: `main` + `develop` + ticket branches, tagged releases
- **Chosen:** `PTY-N-slug` into `develop` by PR; `develop` into `main` by release PR; tags `vX.Y.Z` with GitHub Releases. Both long-lived branches protected: PR required, CI required, no force-push, enforced for admins.
- **Rejected:** separate `dev`, `stg`, `prod` branches.
- **Why:** there are no servers (the brief says no deployment), so a staging branch would point at nothing. Two branches show the same discipline honestly.
- **Note:** GitHub does not let an author approve their own PR, so required approvals are 0. The owner's review is recorded in the ticket file, and the owner merges.
- **Date:** 2026-10-01

## D-003 UI without a build step
- **Chosen:** Blade page shells calling the JSON API with `fetch`, Alpine.js vendored as a file, one hand-written CSS file with design tokens and CSS-native motion.
- **Rejected:** Vite + Tailwind (the reviewer would need Node and a build); an SPA (hours that belong to tests).
- **Why:** the reviewer must run the app with PHP and Composer only. Polish comes from the design system, not the toolchain.
- **Date:** 2026-10-01

## D-004 Stock is a ledger of movements; balances are derived
- **Chosen:** append-only `stock_movements` with a signed integer delta, reason and a reference to its cause. On-hand = sum of deltas.
- **Rejected:** a mutable `quantity_on_hand` column.
- **Why:** a derived balance cannot drift from its history. Every number traces back to the delivery or sale that caused it. Corrections are new rows, not edits. New stock events (waste, stock take, returns) become a new reason, not a new mechanism. It is the same principle as double-entry bookkeeping: postings are the truth, balances are a view of them.
- **Performance note:** if summing ever got slow, a cached balance column could be maintained from the same movements, with the movements remaining authoritative. Not needed at this size.
- **Date:** 2026-10-01

## D-005 Plain service classes in transactions; no events or listeners
- **Chosen:** each use case is one service method wrapped in `DB::transaction`, calling `StockLedger` explicitly.
- **Rejected:** domain events with listeners; model observers.
- **Why:** every stock change should be a call you can point at on screen. Events hide control flow and make "what happens when a sale is recorded" a search instead of a read. Revisit only if a requirement needs fan-out.
- **Date:** 2026-10-01

## D-006 No caching of stock; freshness is visible
- **Chosen:** no server-side cache. A `Cache-Control: no-store` middleware on API and pages, so the browser back button never shows stale stock. The UI refetches on `pageshow`/`visibilitychange` and on a light poll (about 10 s), and shows "updated N s ago".
- **Rejected:** caching stock sums; websockets.
- **Why:** the brief asks that stock reflect deliveries and sales "without the user having to guess whether it's up to date". Caching would create exactly that guess. Websockets are a lot of machinery for one branch.
- **Date:** 2026-10-01

## D-007 Optional Docker
- **Chosen:** a single-service `compose.yaml` (PHP + SQLite), documented as an alternative way to run.
- **Rejected:** Docker as the only way to run; multi-container setups (nginx, MySQL, Redis).
- **Why:** lets a reviewer without PHP run the app. Not required, because if Docker fails on their machine the Composer path must still work.
- **Date:** 2026-10-01

## D-008 Postman collection with a `local` environment only
- **Chosen:** one collection covering every endpoint, one environment `local` (`http://127.0.0.1:8000`).
- **Rejected:** staging and production environments.
- **Why:** they would point at URLs that do not exist (D-002).
- **Date:** 2026-10-01

## D-009 Jira-style tickets and authorship
- **Chosen:** project Patty, key PTY. Tickets, branches, commits and PRs carry `PTY-N`. Every commit is authored by Mohamad with no AI trailers. AI use is disclosed in `docs/AI_LOG.md`.
- **Why:** the history should read like real team work, and the AI log is the honest, graded record of how AI was used.
- **Date:** 2026-10-01

---

Pending, from open questions: Q-001 to Q-007 (see [questions/](questions/)).
