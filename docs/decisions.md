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
- **Amended by D-026:** Node returns for opt-in browser test tooling only. The app still installs and runs without it.

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
- **Clarified by D-021:** model events may be used to *observe* (audit trail), never to *change state*.

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

## D-010 A sale below zero is accepted and flagged (Q-001)
- **Chosen:** record the sale and its movements even if on-hand goes negative. Show the ingredient as "Negative" in red.
- **Rejected:** rejecting the sale (the sale already happened, so the data would be lost); clamping at zero (the ledger would stop summing to reality).
- **Why:** the POS reports facts. Negative stock is information: an unrecorded delivery, a miscount or loss. Surfacing it is what the owner needs. Hiding it by refusing the sale destroys data.
- **Date:** 2026-10-01

## D-011 Over-delivery tolerated up to 5% per line (Q-002). SUPERSEDED by D-035
- **Chosen:** a line may receive in total up to `intdiv(ordered x (100 + 5), 100)`, using integer math and rounding down. Stock rises by the full amount received. Outstanding floors at 0, and the excess shows as over-received. Beyond the limit, the whole delivery is rejected (422). Tolerance in `config/patty.php`.
- **Rejected:** strict rejection of any excess (the orchestrator's recommendation, which is simpler but ignores how suppliers actually deliver by weight); unlimited excess (a typo inflates stock).
- **Why (AI draft, Mohamad to confirm in his own words):** suppliers routinely deliver slightly over on weighed goods, and refusing to record what physically arrived makes stock wrong. A cap still catches typos. Rounding down keeps the limit an integer and never lets a small-count line (10 buns) go over at all.
- **Decided by:** Mohamad, overriding the recommendation.
- **Owner rationale (confirmed 2026-10-01):** follow established ERP practice. SAP and Microsoft Dynamics 365 both use delivery tolerances. The AI draft above is kept for history.
- **Superseded by D-035:** over **and** under tolerance in basis points, plus an absolute over-cap, snapshotted onto each PO line.
- **Date:** 2026-10-01

## D-012 `received` means partially received; closing is automatic (Q-003)
- **Chosen:** `draft -> sent -> received -> closed`. The first delivery moves sent to received. The order closes in the same transaction as the delivery that clears the last outstanding quantity. The UI labels `received` "Partially received".
- **Rejected:** `received` = fully received with a manual close (contradicts "the order closes when everything has been received").
- **Date:** 2026-10-01

## D-013 Short-close from `received` (Q-004)
- **Chosen:** a manager can close a `received` order with quantity outstanding. It is marked `short_closed`, the remainder shows as not delivered, and stock is untouched.
- **Rejected:** no manual close (dead orders clutter the open list forever); a `cancelled` state (not in the brief; a likely live extension).
- **Date:** 2026-10-01

## D-014 POS idempotency via optional `pos_reference` (Q-005)
- **Chosen:** a nullable unique `pos_reference`. A replay returns the original sale with 200 and moves no stock.
- **Why:** network retries are the most likely way stock goes wrong in real life, and arithmetic tests cannot catch it.
- **Date:** 2026-10-01

## D-015 One unit per ingredient, integer quantities (Q-006)
- **Chosen:** `g`, `ml` or `piece` per ingredient. All quantities are unsigned integers in that unit, with no conversion. The unit is locked once the ingredient is used (D-044).
- **Rejected:** purchase-unit conversion (a next step).
- **Date:** 2026-10-01

## D-016 Editing rules (Q-007)
- **Chosen:** as tabled in Q-007. PO lines and supplier are editable in draft only. Recipes are always editable but affect future sales only. Deliveries, sales and movements are never edited or deleted. Only draft POs can be deleted.
- **Date:** 2026-10-01

## D-017 No events, queues or scheduler, with the triggers that would change that
- **Chosen:** synchronous service calls only. `QUEUE_CONNECTION=sync`. No scheduled tasks.
- **Why:**
  - **Events:** a listener that fails half-way would break "stock is always right" (D-005).
  - **Queues:** nothing is slow, and a queued stock change would make the screen stale (D-006).
  - **Scheduler:** nothing in the brief is time-based.
- **When each becomes yes:**
  - **Events:** a side effect outside the stock truth, such as notifying a supplier when a PO is sent. It would be fired after commit (`ShouldDispatchAfterCommit`), with a queued listener.
  - **Queues:** emailing PDFs to suppliers, or importing a POS day-end file.
  - **Scheduler:** a nightly low-stock report, or reminders for POs sent but undelivered after N days.
- **Date:** 2026-10-01

## D-018 No financial pillar; clean seams instead
- **Chosen:** quantities only. No prices, costs, valuation or journal postings.
- **Rejected:** unit price on PO lines; weighted-average costing with stock value and cost of sales.
- **Why:** the brief is "a small inventory and purchasing service" and values correctness over size. Costing brings edge cases (the cost of negative stock, the cost of over-delivered excess) that would need their own tests and defence. The ledger design already maps one-to-one onto journal postings. `architecture.md` section 8 describes how finance, procurement and multi-branch would plug in.
- **Decided by:** Mohamad.
- **Date:** 2026-10-01

## D-019 One API response envelope; 409 for state, 422 for input
- **Chosen:** every API response is `{ success, message, data, meta? }` or `{ success: false, message, code, errors }`, produced by the `ApiResponse` trait. Every exception is rendered in one place (`bootstrap/app.php`).
- **Status map:**

  | Status | Meaning |
  |---|---|
  | 200 | Read or action |
  | 201 | Created |
  | 404 | Not found |
  | 405 | Method not allowed |
  | **409** | The request is valid but the resource's current state forbids it: `invalid_transition`, `order_not_editable`, `cannot_receive`, `unit_locked` |
  | **422** | The request content is wrong: `validation_failed`, `over_delivery`, `menu_item_not_sellable` |
  | 429 | Too many requests |
  | 500 | Generic message, never a trace |

- **Why:** the UI and the POS get one shape to handle. `code` is stable for machines, and `message` is readable for people. Separating 409 from 422 tells the client whether to fix its input or refresh its view of the resource.
- **Decided by:** Mohamad (envelope and the 409/422 split).
- **Date:** 2026-10-01

## D-020 Show Incoming next to On hand
- **Chosen:** the stock view shows, per ingredient, **Incoming** = sum of outstanding quantities on open POs. It is derived, never stored.
- **Why:** the brief's goal is to "stop running out". On hand alone answers "what do I have", and Incoming answers "what is already on the way". Together they support the reorder decision without a new table. Reorder levels stay as a next step.
- **Found by:** the alignment check against the brief's problem statement.
- **Date:** 2026-10-01

## D-021 Audit trail with spatie/laravel-activitylog
- **Chosen:** spatie/laravel-activitylog ^5.1.
  - Field changes come from the `LogsActivity` trait (dirty fields only) on Ingredient, Supplier, MenuItem and PurchaseOrder.
  - Named business events come from explicit `activity()` calls in services: `purchase_order.sent`, `delivery.recorded`, `recipe.replaced`, `sale.replayed`, and so on.
  - Every entry carries `channel` (`ui` / `pos` / `api`, from the `X-Patty-Channel` header), `request_id` and `ip`. `causer` stays null because there are no users, and no username is ever invented.
  - Entries are written inside the same transaction as the change.
  - `StockMovement` is excluded: the ledger is already its own audit trail.
- **Rejected:** our own audit table (the orchestrator's recommendation, which is more explicit but more code to own).
- **Why:** a known, maintained package covers field diffs for free, and explicit calls add the business meaning that field diffs lack.
- **Decided by:** Mohamad.
- **Date:** 2026-10-01

## D-022 Log channels per domain
- **Chosen:**
  - `laravel.log` holds errors only (daily, 14 days).
  - Per-domain daily channels: `stock`, `purchasing`, `pos`, `catalog`.
  - `RequestContext` middleware adds `request_id` and `channel` to every line, and echoes `X-Request-Id` in the response.
- **Rejected:** a channel per model (about ten mostly empty files that split one story across many places).
- **Why:** debugging follows a business flow ("what happened in purchasing at 14:02"), not a table.
- **Logs versus audit:** logs are operational, for developers, and rotated. The audit trail is business history, kept for the manager, and stored in the database.
- **Date:** 2026-10-01

## D-023 Code comment standard
- **Chosen:**
  - comments explain why, not what;
  - a docblock on every public service method, covering intent, invariants and the exceptions thrown;
  - rule-driven code cites its decision (`// D-011: ...`);
  - no commented-out code;
  - no TODO without a PTY key.
- **Why:** comments are part of the explanation the owner gives live. Too few and the reasoning is lost; too many and the code is noise.
- **Date:** 2026-10-01

## D-024 Defence in depth
- **Chosen:** in addition to validation, domain rules, transactions and locks:
  - SQLite triggers that block `UPDATE` and `DELETE` on `stock_movements`;
  - a named rate limiter on `POST /sales` (120 per minute);
  - a `SecurityHeaders` middleware (`nosniff`, `X-Frame-Options: DENY`, `Referrer-Policy: same-origin`, a minimal `Permissions-Policy`).
- **Deferred:** Larastan static analysis; optimistic locking for concurrent draft edits. Both are in the README's next steps.
- **Why:** append-only should be a property of the database, not a promise of the code. A POS integration should not be able to flood the ledger.
- **Date:** 2026-10-01

## D-025 Icons: Hugeicons free set
- **Chosen:** `@hugeicons/core-free-icons` 4.3.5 (MIT), stroke-rounded, 1.5 px stroke.
  - It ships as JS data, so a one-off script converts only the icons we use into plain SVG files, which are committed to `resources/icons`.
  - A Blade `<x-icon>` component inlines them.
  - No npm at install or runtime. The license notice is kept with the icons.
- **Rejected:** a UI kit (Pico, Open Props). Our tokens and motion are custom, and a kit would be overridden more than used.
- **Decided by:** Mohamad (Hugeicons).
- **Date:** 2026-10-01

## D-026 Browser tests as an opt-in suite (stretch)
- **Chosen:** Pest 4 browser tests (Playwright) in `tests/Browser`.
  - They are excluded from the default `php artisan test` (`defaultTestSuite="Unit,Feature"`) and run with `composer test:browser`.
  - In CI they run as a separate job.
  - `package.json` exists for test tooling only.
- **Why:** they prove the UI flows end to end without making Node a requirement for a reviewer running the app or the core tests.
- **Priority:** first to cut if time runs short.
- **Date:** 2026-10-01

## D-027 No login, by design, and it should feel intentional
- **Chosen:** all four of the following.
  - An identity chip ("Restaurant manager, no login by design") and a first-visit banner.
  - A guided "Try it" card on the dashboard that ticks itself off from real data.
  - "Reset demo data", local env only.
  - A realistic seed built through the real services, so every state is visible on first open: a closed PO with over-receipt, a partially received PO, a sent PO, a draft, two days of sales, and one negative ingredient.
- **Why:** the brief says no authentication. Without these, a reviewer lands on an app with no context and wonders what is missing. With them, the absence of login reads as a decision, and the reviewer has a path through every feature in minutes.
- **Decided by:** Mohamad (Q-008).
- **Date:** 2026-10-01

## D-028 Optional POS key, off by default
- **Chosen:** if `POS_API_KEY` is set, `POST /sales` requires a matching `X-POS-Key` (constant-time comparison), else 401. It is empty by default, so reviewers and Postman need no setup.
- **Rejected:** fully open with no option; full API authentication (the brief says none).
- **Why:** it shows the integration surface was thought about, without adding friction.
- **Decided by:** Mohamad (Q-009).
- **Date:** 2026-10-01

## D-029 Idempotency conflict is a 409
- **Chosen:** a reused `pos_reference` with the same `menu_item_id` and `quantity` is a replay (200, nothing moves). With a different payload it is **409 `idempotency_conflict`**: nothing is recorded, and a warning is logged.
- **Rejected:** treating every reuse as a replay, which would hide a POS bug.
- **Decided by:** Mohamad (Q-010).
- **Date:** 2026-10-01

## D-030 Small edges
- **Time:**
  - stored in UTC;
  - the API returns ISO-8601 with `Z`;
  - the UI shows the viewer's machine timezone (confirmed by Mohamad).
- **No deletes** of ingredients, suppliers or menu items, because history references them. Draft POs can be deleted. Archiving is a next step. Mohamad left this to the orchestrator's judgement.
- **Delivery date:** `received_at` cannot be in the future or before the PO's `sent_at`.
- **Supplier catalogue:** none. Any supplier may supply any ingredient (a next step).
- **Bounds:**
  - max 50 lines;
  - quantities 1 to 1,000,000 per line;
  - sales 1 to 1,000 per event;
  - provably no integer overflow (validation.md).
- **PO progress** is the average of per-line completion, never a sum across units.
- **Date:** 2026-10-01

## D-031 API versioning and no internal ids exposed
- **Chosen:**
  - **Versioning:** path versioning `/api/v1`, with V1 namespaces for controllers, requests and resources, and shared services. An `X-API-Version` header. A written policy: additive changes stay in v1, breaking changes go to v2, retirement announced with `Deprecation` and `Sunset` headers.
  - **Identifiers:** integer primary keys stay internal. Every addressable record has a **ULID** used in URLs, requests and responses.
  - **Document numbers:** human-readable `PO-2026-0001`, `GRN-2026-0001` and `SALE-2026-000001` come from a per-type yearly counter, and are display-only.
- **Rejected:**
  - exposing auto-increment ids, which reveal volume and invite enumeration (`/purchase-orders/3`);
  - UUID primary keys (slower joins and indexes, for no gain here);
  - document numbers derived from the id (they leak the id).
- **Why:** professional APIs do not expose database internals. ULIDs are unguessable and sortable, and keep integer keys fast for joins.
- **Decided by:** Mohamad (Q-013).
- **Date:** 2026-10-01

## D-032 Pagination everywhere, skeletons and lazy loading
- **Chosen:**
  - **Pagination:** every collection endpoint is paginated (`page`, `per_page` default 25, max 100, above that 422) with `meta.pagination`. Dashboard KPIs come from their own endpoint.
  - **UI:** skeleton placeholders for every loading region, and lazy loading of further pages on scroll with a "Load more" fallback.
- **Rejected:** unpaginated lists "because the restaurant is small" (the orchestrator's first suggestion). Mohamad wanted it done properly everywhere.
- **Decided by:** Mohamad.
- **Date:** 2026-10-01

## D-033 Phases, and docs that cannot fall behind
- **Chosen:** the project runs in phases with gates, journaled in `progress.md` (goal, how it went, decisions, lessons):
  - 0 discovery and planning;
  - 1 specification;
  - 2a backend design;
  - 2b frontend design;
  - 3a build backend;
  - 3b build frontend;
  - 4 review and testing;
  - 5 security;
  - 6 release;
  - 7 interview prep.

  Every PR updates `progress.md`. From PTY-16, CI fails a PR that changes `app/`, `routes/`, `database/` or `resources/` without changing `docs/progress.md`.
- **Decided by:** Mohamad.
- **Date:** 2026-10-01

## D-034 A frontend design phase before any UI code
- **Chosen:**
  - Phase 2b produces a design system and every screen in every state (loaded, skeleton, empty, error) as Claude Artifact designs.
  - Mohamad iterates and approves in writing.
  - Only then are design.md and ui.md finalised, the UI ticket split (Q-012) and the PTY-17 scope decided, and PTY-11 unblocked.
  - Phase 2b runs alongside the backend build.
- **Why:** a design approved up front is cheaper than one discovered while coding.
- **Decided by:** Mohamad.
- **Date:** 2026-10-01

## D-035 Delivery tolerances, SAP and Dynamics style: over, under, and an absolute cap
- **Chosen:**
  - Every PO line carries three tolerance values, snapshotted from the ingredient (or the unit default) when the line is created or edited in draft:
    - `over_tolerance_bps`;
    - `under_tolerance_bps`;
    - `over_tolerance_cap`.
  - Basis points keep percentages as integers (500 = 5.00%).
  - The arithmetic, all integers:
    - `max_receivable = ordered + min(intdiv(ordered x over_bps, 10000), over_cap if set)`. Beyond it the delivery is rejected (422 `over_delivery`).
    - `min_to_complete = ordered - intdiv(ordered x under_bps, 10000)`. At or above it the line is **complete**: outstanding becomes 0, and any shortfall shows as "under-delivered within tolerance".
    - The PO closes automatically when every line is complete.
  - Defaults (`config/patty.php`): over 5%, under 5%. Cap 2,000 for g and ml, none for pieces. Each ingredient can override all three.
- **Why:**
  - **SAP** has over- and under-delivery percentages per PO item; under-tolerance lets a line complete without manual closing.
  - **Microsoft Dynamics 365** combines percentages with absolute limits.
  - Doing both means a large order cannot be over-delivered by an unreasonable absolute amount (5% of 50 kg is 2.5 kg, but the cap holds it to 2 kg).
  - Snapshotting onto the line means changing an ingredient's settings never changes an order already sent to a supplier. Both systems behave this way.
  - Rounding down in both directions means a 10-bun line accepts exactly 10 and completes only at 10.
- **Unchanged:** short-close (D-013) still covers a supplier who will never deliver the rest, **below** the under-tolerance.
- **Decided by:** Mohamad (Q-014).
- **Date:** 2026-10-01

## D-036 Realistic seed data with licence-free photos
- **Chosen:**
  - **Data:** 12 ingredients, 5 menu items and 4 **clearly fictional** suppliers (`.example` email domains, fictitious +962 numbers). The Classic Burger is exactly as in the brief (beef 150 g, bun 1, cheese 20 g).
  - **History:** about 6 POs covering every state and tolerance case, and about 120 sales over the last 3 days with lunch and dinner peaks. Deterministic (fixed random seed), dated relative to now, created through the real services.
  - **Photos:** about 17 licence-free photos (Unsplash or Pexels; no people, no brands), center-cropped and resized to 480×480 WebP with PHP GD by `scripts/prepare-seed-images.php`, and served from `public/images/seed`. Each one is credited in `public/images/seed/CREDITS.md` with its source URL and licence.
  - A nullable `image_path` on ingredients and menu items, with a Hugeicons fallback in the UI.
- **Rejected:**
  - real company names, which could imply a relationship that doesn't exist;
  - an image upload feature (D-001 stands; a next step);
  - hot-linking images from third-party sites (privacy, availability);
  - AI-generated images (slower to produce consistently).
- **Why:** a reviewer opening the app should see a believable restaurant, not "Test Ingredient 1". Photos make the catalogue scannable at a glance.
- **Decided by:** Mohamad (Q-015).
- **Date:** 2026-10-01

## D-037 Demo data API: clear, seed, reset (local only)
- **Chosen:** three endpoints, each with an artisan twin and a UI action behind a confirm dialog. All are registered **only** when `APP_ENV=local`, and are 404 elsewhere.

  | Endpoint | Artisan command | Effect |
  |---|---|---|
  | `POST /api/v1/demo/clear` | `patty:demo:clear` | Empty everything |
  | `POST /api/v1/demo/seed` | `patty:demo:seed` | Load the realistic data into an **empty** system; 409 `demo_not_empty` otherwise |
  | `POST /api/v1/demo/reset` | `patty:demo:reset` | Clear, then seed |

- **Clear uses `migrate:fresh`, not DELETE.** The append-only triggers on `stock_movements` (D-024) refuse DELETE, as they should. Erasing history in a demo means starting a new database, which is honest and visible. Even our own tooling cannot quietly rewrite the ledger.
- **Why:** reviewers and the live session need to start from zero ("build it from scratch in front of us") or from a rich state ("show me a partially received order"), on demand, in seconds.
- **Decided by:** Mohamad (Q-016).
- **Date:** 2026-10-01

## D-038 Store base units; the UI shows and accepts kg and L
- **Chosen:**
  - **Storage, API and every calculation** stay in integer base units: `g`, `ml`, `piece` (D-015). The API never accepts or returns kg or L, and a decimal is a 422.
  - **The UI converts in one module, `public/js/units.js`:**
    - **Display:** values of 1,000 g or more show as kg, and 1,000 ml or more as L, with up to 3 decimals and trailing zeros trimmed. The exact base value shows on hover. Pieces are never converted.
    - **Input:** quantity fields for g and ml have a unit switch (g/kg, ml/L) and convert to integer base units before sending, using string-based decimal parsing, never floating-point multiplication. More than 3 decimals in kg or L is refused before sending.
    - **Server errors** are re-expressed in the unit the user typed in.
- **Rejected:** storing kg or L, or decimals, in the database (floats in stock, D-015); per-ingredient purchase units such as cases or drums (still out of scope, a next step).
- **Why:** the kitchen thinks "2.4 kg of beef", and the ledger must think "2400". Keeping the conversion at the edge means the arithmetic that has to be right never sees a decimal. The server re-validates everything, so a UI bug cannot corrupt stock.
- **Decided by:** Mohamad.
- **Date:** 2026-10-01

## D-039 Opus 5.5 is the top local model tier; Fable only in Claude Code on the web
- **Chosen:**
  - Orchestrator: Opus 5.5.
  - Every ticket: built by **Sonnet 5.5**, reviewed by **Opus 5.5** (with `/code-review`).
  - Design reviewer: Opus 5.5 with Claude in Chrome.
  - Security review (PTY-21): Opus 5.5 `/security-review` locally. **Fable 5.1 is used only through Claude Code on the web** (the cloud version), once Mohamad connects the repository there. That is where the final independent security pass runs.
- **Rejected:** Opus building and Opus reviewing L tickets (a same-tier reviewer shares the builder's blind spots); Haiku as a builder (too weak for tickets this consequential).
- **Why:** no Fable credits are available. Keeping the rule "the reviewer is always one tier above the builder" matters more than having the strongest builder. For the stock arithmetic (L tickets) the extra safety net is three checks: the Opus review at `high`, the orchestrator reading the diff, and Mohamad's hand-check of every worked example.
- **Decided by:** Mohamad.
- **Date:** 2026-10-01

## D-040 At most three agents at once: two builders, one reviewer
- **Chosen:** during the build phases, at most **3 sub-agents run at the same time**: 2 builders (Sonnet 5.5) and 1 reviewer (Opus 5.5). Further tickets wait in the queue. Fixes after review go back to the same builder (continued, not re-spawned).
- **Why:** the project runs on a Claude Pro plan, not Max. Unbounded parallel agents would exhaust the usage limit and stop the session mid-build. Two builders keep throughput up, and one reviewer keeps every ticket reviewed.
- **How it is run:**
  - The two builders work in separate git worktrees on separate ticket branches, so they never touch each other's files.
  - Tickets are paired so they do not edit the same files (for example PTY-3 schema with PTY-16 API foundation).
  - API routes live in one file per area under `routes/api/v1/`, so parallel tickets do not collide.
  - D-033 amended: a PR must change `docs/progress.md` **or** its own ticket file. The orchestrator updates the journal once per wave, so two parallel PRs do not conflict on progress.md.
- **Waves for phase 3a:**
  1. PTY-3 with PTY-16
  2. PTY-4 with PTY-6
  3. PTY-5 with PTY-7
  4. PTY-8 with PTY-9
  5. PTY-10 alone (it reads everything)
- **Decided by:** Mohamad.
- **Date:** 2026-10-01

## D-041 Visual direction: calm pro tool, indigo, Inter, light and dark
- **Chosen:** neutral zinc surfaces with a single indigo accent (`#4F46E5`, dark `#818CF8`); light and dark themes from the same tokens (system preference plus a manual toggle); Inter, self-hosted under the SIL OFL; purposeful motion only. The tokens are in design.md.
- **Supersedes:** the ember accent and the system-font stack in the original design.md.
- **Rejected:** a warm kitchen brand look; a dark-only ops console; ember or green accents (green collides with the "OK" status).
- **Decided by:** Mohamad.
- **Date:** 2026-10-01

## D-042 Brand: "the stack" logo, session loader, adaptive favicon
- **Chosen:** a geometric burger of four stacked layers. It doubles as the idea behind the stock ledger: entries that only ever stack.
  - The wordmark is Inter.
  - The SVG assets live in `public/brand/`.
  - The favicon is simplified to 3 layers and adapts to dark tabs.
  - The animated loader shows on the first load of a session (900 ms max, never blocking, reduced-motion aware), and the same motion is the busy state on buttons.
- **Decided by:** Mohamad (requested a logo with motion and a matching favicon; approved the boards).
- **Date:** 2026-10-01

## D-043 Design approved at system level; screens are built in code
- **Chosen:** Mohamad approved the brand board and the design system (light and dark) on 2026-10-01. The 7 screens are **not** drawn as separate mockups. They are built directly in code (PTY-12, PTY-18, PTY-19) from the approved tokens and components, and reviewed in the browser by the design reviewer.
- **Why:** about 8 hours of build time remain. Drawing 7 screens in 4 states would cost hours of usage and review before any feature exists. The design system already fixes every component and state the screens use, so the screens are compositions of approved parts.
- **Supersedes:** the "all 7 screens before approval" scope chosen earlier the same day.
- **Decided by:** Mohamad.
- **Date:** 2026-10-01

## D-044 Unit locks once an ingredient is used anywhere
- **Chosen:** an ingredient's unit cannot change once the ingredient has stock movements, appears in any recipe line, or appears in any purchase order line. The API answers 409 `unit_locked` and names the use ("Beef is already used in recipes, so its unit (g) can't change."). The resource's `unit_locked` flag follows the same rule. Sending the unchanged unit is not a change and is allowed.
- **Why:** movements, recipe quantities and PO quantities are all integers in the ingredient's unit. Changing g to ml would reinterpret every one of them (a recipe's 150 g becomes 150 ml, a sent PO's 1000 g becomes 1000 ml). Locking on movements alone (the first reading of D-015) left recipes and orders exposed.
- **Units stay a fixed enum** (`g`, `ml`, `piece`) with no units table. Purchase units with conversion factors (buy in drums, consume in ml) are a next step (Q-006).
- **Supersedes:** the "locked once stock has moved" wording in D-015.
- **Decided by:** Mohamad.
- **Date:** 2026-10-02

## D-045 Up to four agents at once (amends D-040)
- **Chosen:** at most **4 sub-agents at the same time: 3 builders (Sonnet 5.5) and 1 reviewer (Opus 5.5)**. Previously 2 builders and 1 reviewer.
- **Why:** after waves 1 and 2 showed the pipeline working (each ticket built, reviewed by a higher tier, fixed, verified, then one PR per ticket), Mohamad raised the limit to shorten the remaining build. One reviewer at a time still means every ticket gets an independent review.
- **How it was decided:** a builder agent relayed "the user says 4 is fine". The orchestrator did not act on that, because a relayed claim is not the owner's instruction. Mohamad then confirmed it directly.
- **Decided by:** Mohamad.
- **Date:** 2026-10-01
