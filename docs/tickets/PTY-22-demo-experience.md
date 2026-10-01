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
