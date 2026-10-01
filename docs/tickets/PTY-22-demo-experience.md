# PTY-22 Demo experience: no login that feels intentional

| Field | Value |
|---|---|
| Type | Story |
| Phase | 3b Build frontend |
| Status | To Do |
| Weight | M |
| Builder | Opus 5.5 |
| Reviewer | Fable 5.1 (code) + design reviewer |
| Branch | `PTY-22-demo-experience` |
| Release | v0.4.0 |
| Depends on | PTY-10, PTY-12, phase 2b design approved |

## Goal

A reviewer opens the app for the first time, understands immediately that there is no login by design, sees realistic data in every state, and has a guided path through every feature in a few minutes. D-027.

## Covers

FR-7 AC1 to AC5. D-027. Q-008. Tests T24, QA-8.

## Contract

[api.md](../api.md) E30 (local only). The Try-it card reads E16 and E27, which already exist.

## Flows

F20, and F5 to F16 as run by the seeder.

## Acceptance criteria

### Realistic seed, through the real services only (no raw inserts)
- [ ] `DemoSeeder`, called by `DatabaseSeeder`. It uses `PurchaseOrderService`, `ReceivingService` and `SaleService`, so every seeded row obeys every rule and produces movements, audit entries and document numbers. Dates are set with `Carbon::setTestNow` steps relative to now, so the data looks recent on every reset.
- [ ] After seeding:
  - **PO-...-0001 closed** (two deliveries, beef over-received 30 g);
  - **PO-...-0002 partially received**;
  - **PO-...-0003 sent**;
  - **PO-...-0004 draft**;
  - about 20 sales over the last two days (POS references `seed:0001` onwards);
  - **cheese negative**;
  - every activity entry has channel `api`, with a `seed: true` property.
- [ ] The seeder is deterministic: the same counts and the same final stock every run. The test asserts them.

### Identity and introduction
- [ ] The header chip reads "Restaurant manager", with the tooltip "No login, by design. See the README." It is keyboard-focusable.
- [ ] A first-visit banner: "Single-branch demo. No login needed. Start with the guided tour." Dismissal is stored in localStorage (wrapped in try/catch; it renders correctly without storage).

### Guided "Try it" card (Dashboard)
- [ ] Five steps, each with a link:
  1. Send PO-...-0003 (its PO page)
  2. Receive part of PO-...-0002 (its PO page)
  3. Sell 3 Classic Burgers (POS Simulator)
  4. Watch stock and Incoming change (Dashboard stock panel)
  5. See what happened (Activity)
- [ ] Each step ticks itself off from real data on refresh:
  1. the seeded PO-...-0003 is no longer `sent`
  2. the seeded PO-...-0002 has more deliveries than at seed time
  3. a non-seed sale exists
  4. ticks once step 2 or 3 is done
  5. the Activity page has been visited (localStorage)
- [ ] Dismissible; "Show tour" in the identity chip menu brings it back.

### Reset demo data (local only)
- [ ] E30 `POST /api/v1/demo/reset` is registered **only** when `app()->environment('local')`. It runs `migrate:fresh --seed` and returns 200.
- [ ] `php artisan patty:reset-demo` does the same from the CLI, with a confirmation prompt (skipped with `--force`).
- [ ] UI: "Reset demo data" in the banner and the chip menu, with a confirm dialog, then a full reload. Hidden outside local (the page checks a `demo_reset_available` flag rendered into the Blade shell).

## Tests required
- [ ] T24: with `APP_ENV=production`, E30 gives 404. With `local`, it gives 200 and the data is re-seeded.
- [ ] `DemoSeederTest`: after seeding there are 4 POs in the four expected states, the expected number of sales, cheese on-hand < 0, every PO has a number, and no stock movement is missing a reference.
- [ ] Seeding twice (via reset) gives identical counts.

## Done means
1. `composer setup` then `php artisan serve`, and open `/`. The banner, the Try-it card and live data appear, with Cheese shown as Negative.
2. Follow the five Try-it steps; each ticks.
3. "Reset demo data" restores the original state.
