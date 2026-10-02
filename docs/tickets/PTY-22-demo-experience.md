# PTY-22 Demo experience: no login that feels intentional

| Field | Value |
|---|---|
| Type | Story |
| Phase | 3b Build frontend |
| Status | To Do |
| Weight | M |
| Builder | Sonnet 5.5 |
| Reviewer | Opus 5.5 (code) + Opus 5.5 design reviewer |
| Branch | `PTY-22-demo-experience` |
| Release | v0.4.0 |
| Depends on | PTY-10, PTY-12, phase 2b design approved |

## Goal

A reviewer opens the app for the first time, understands immediately that there is no login by design, sees realistic data in every state, and has a guided path through every feature in a few minutes. D-027.

## Covers

FR-7 AC1 to AC5. D-027, D-036, D-037. Q-008, Q-015, Q-016. Tests T24, QA-8.

## Contract

[api.md](../api.md) E30, E31, E32 (local only). The Try-it card reads E16 and E27, which already exist.

## Flows

F20, F22, and F5 to F16 and F21 as run by the seeder.

## Acceptance criteria

### Realistic seed data (D-036), through the real services only
- [ ] `DemoSeeder`, called by `DatabaseSeeder` and by E32/E30. It uses only `CatalogService`, `PurchaseOrderService`, `ReceivingService` and `SaleService` (no raw inserts), so every row obeys every rule and produces movements, audit entries and document numbers.
  - Deterministic: `mt_srand(20261004)`.
  - Dated relative to now with `Carbon::setTestNow` steps, then reset.
- [ ] **Ingredients (12):** Beef g, Bun piece, Cheese g, Lettuce g, Tomato g, Onion g, Pickles g, Burger sauce ml, Potatoes g, Frying oil ml, Ketchup ml, Chicken breast g. One ingredient has a tolerance override (Beef: under 2%), to show the per-ingredient setting.
- [ ] **Menu (5):**
  - Classic Burger: beef 150, bun 1, cheese 20, **exactly the brief**;
  - Patty Deluxe;
  - Double Patty;
  - Crispy Chicken;
  - Fries (recipes as in the round 4 plan / D-036).
- [ ] **Suppliers (4, fictional):** Al-Mashreq Meats, Golden Crust Bakery, Jordan Valley Fresh Produce, Dairy Hills. Emails `@*.example`; phones `+962 6 5xx xxxx`.
- [ ] **POs**, one per state or case:
  - closed by full receipt;
  - closed within under-tolerance;
  - closed with over-receipt;
  - short-closed;
  - partially received;
  - sent;
  - draft.
- [ ] **Sales:** about 120 over the last 3 days, with lunch (12 to 15) and dinner (19 to 23) peaks, POS references `seed-till-1:00001` onwards, one replay, and Cheese ending negative.
- [ ] Every activity entry has channel `api` and property `seed: true`.

### Photos (D-036)
- [ ] About 17 photos: one per ingredient and one per menu item.
  - Source: Unsplash or Pexels.
  - **The licence page must be checked for each one.**
  - No people, no brands or logos.
  - Originals are kept outside the repo (`storage/app/seed-originals/`, gitignored).
- [ ] `scripts/prepare-seed-images.php` (PHP GD): center-crops to a square, resizes to 480×480, writes WebP at quality 80 to `public/images/seed/{ingredients,menu}/<slug>.webp`. It is idempotent.
- [ ] `public/images/seed/CREDITS.md`: one row per file with file, subject, source URL, photographer and licence.
- [ ] The seeder sets `image_path` for each record. A missing file leaves it null, and the UI falls back to the icon.
- [ ] Total size under 1 MB (checked in the test).

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

### Demo data endpoints (D-037), local only
- [ ] Routes registered only when `app()->environment(local)`; otherwise 404:
  - E31 `POST /api/v1/demo/clear`: runs `migrate:fresh` without seeding; 200.
  - E32 `POST /api/v1/demo/seed`: 409 `demo_not_empty` if any ingredient, supplier, menu item, PO or sale exists; else runs `DemoSeeder`; 200 `{ counts }`.
  - E30 `POST /api/v1/demo/reset`: clear, then seed; 200 `{ counts }`.
- [ ] Artisan: `patty:demo:clear`, `patty:demo:seed`, `patty:demo:reset`. Each asks for confirmation unless `--force`, and refuses outside local unless `--force-env`.
- [ ] UI (identity chip menu): "Reset demo", "Clear all data", "Load demo data" (enabled only when empty), each with a confirm dialog, then a full reload. Hidden outside local (a `demo_tools` flag is rendered into the Blade shell).
- [ ] After clear, the Dashboard empty state offers "Load demo data".

## Tests required
- [ ] T24: with `APP_ENV=production`, E30, E31 and E32 all give 404. In local: clear leaves zero rows in every table; seed on empty gives the expected counts; seed on non-empty gives 409 `demo_not_empty`; reset twice gives identical counts.
- [ ] `DemoSeederTest`: after seeding there are 7 POs covering every case (including one closed within under-tolerance and one short-closed), every image_path points to an existing file, total photo size is under 1 MB, the expected number of sales, cheese on-hand < 0, every PO has a number, and no stock movement is missing a reference.
- [ ] Seeding twice (via reset) gives identical counts.

## Done means
1. `composer setup` then `php artisan serve`, and open `/`. The banner, the Try-it card and live data appear, with Cheese shown as Negative.
2. Follow the five Try-it steps; each ticks.
3. "Reset demo data" restores the original state.

## Builder notes

Status of this run: seed, demo API, artisan commands and identity-chip actions are done. **Not done: photos** (see below). **Moved out of this ticket: the guided "Try it" card now belongs to PTY-12 (Dashboard).**

### Seeded numbers (deterministic, `mt_srand(20261004)`, identical on every run)

Counts: 12 ingredients, 4 suppliers, 5 menu items, 7 purchase orders, 6 deliveries, 120 sales (plus one replay, which moves no stock), 478 stock movements.

Final stock: Beef 3,450 g; Bun 178; Burger sauce 640 ml; **Cheese -740 g (negative on purpose, D-010)**; Chicken breast 4,040 g; Frying oil 2,005 ml; Ketchup 740 ml; Lettuce 3,280 g; Onion 2,655 g; Pickles 1,178 g; Potatoes 2,400 g; Tomato 5,010 g.

Purchase orders (numbers in creation order, `PO-<year>-000N`; the Try-it card in PTY-12 can rely on these):

| No. | Supplier | State | Case |
|---|---|---|---|
| 0001 | Al-Mashreq Meats | closed | full receipt over two deliveries (beef 14,000 + 10,000, chicken 6,000) |
| 0002 | Dairy Hills | received | partial: cheese 2,000 of 5,000, sauce 1,800 of 3,000 (still has outstanding quantity) |
| 0003 | Golden Crust Bakery | draft | 500 buns, ready to send |
| 0004 | Jordan Valley Fresh Produce | closed, short_closed false | every line at 98.5% (inside the 5% default under-tolerance) |
| 0005 | Golden Crust Bakery | closed, short_closed false | 315 buns for 300 ordered (+5%, the limit) |
| 0006 | Jordan Valley Fresh Produce | closed, short_closed true | potatoes 15,000 of 20,000 (75%), then short-closed by hand |
| 0007 | Al-Mashreq Meats | sent | 10,000 g beef, nothing arrived |

Sales: 120 over today and the two days before, 60% lunch (12:00 to 14:59) and 40% dinner (19:00 to 22:59), times in the app timezone; never in the future (a peak that has not happened yet today moves to the day before, so the count is always exactly 120). References `seed-till-1:00001` to `seed-till-1:00120`; the replay is of reference 60.

### Decisions and deviations

- **Photos are not included.** unsplash.com and pexels.com refuse scripted page requests (HTTP 401 and 403), so the licence page of a candidate photo could not be opened and checked, and the brief says not to guess URLs or credits. `images.unsplash.com` answers, but without a verified photo id and licence page that is not enough. No images and no `CREDITS.md` were created. The seeder sets `image_path` only when the file exists, so everything works today and photos can be added later. To finish: put originals in `storage/app/seed-originals/{ingredients,menu}/<slug>.jpg`, run `php scripts/prepare-seed-images.php` (written and runnable), write `CREDITS.md`, commit. Slugs: beef, bun, cheese, lettuce, tomato, onion, pickles, burger-sauce, potatoes, frying-oil, ketchup, chicken-breast; classic-burger, patty-deluxe, double-patty, crispy-chicken, fries.
- **`seed: true` on every activity row** without touching `Audit`: the seeder swaps the spatie before-logging hook for its own (which calls `Audit::stamp`, then forces channel `api` and `seed: true`) and restores the normal hook in a `finally`. Tested both ways.
- **Menu item photos** are set with `MenuItem::update` after `MenuService::create`, because the service has no image argument. Display field only.
- **Demo logic lives in `App\Support\DemoTools`** (clear, seed, reset, counts, `isEmpty`, `enabled`), shared by the controller, the commands and the layout flags, so they cannot disagree.
- **UI:** `public/js/demo.js` (new, loaded only in local) holds the three actions; `layouts/app.blade.php` has marked `PTY-22` edits (flags, script tag, menu section, banner button). The banner button is always "Reset demo data" in local.
- **Tests and SQLite:** `migrate:fresh` ends with `VACUUM`, which SQLite refuses inside a transaction, so the demo test files roll back the `RefreshDatabase` transaction first. Each test boots its own in-memory database, so nothing leaks.
- **T24:** the suite boots as `testing`, where the routes are not registered at all (asserted); a second test loads `routes/api/v1/demo.php` as `production` (404) and a third as `local` (200), so the guard in that file is what is proven.
