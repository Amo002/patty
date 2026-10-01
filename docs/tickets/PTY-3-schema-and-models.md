# PTY-3 Schema, models, identifiers and factories

| Field | Value |
|---|---|
| Type | Story |
| Phase | 3a Build backend |
| Status | In Review |
| Weight | M |
| Builder | Sonnet 5.5 |
| Reviewer | Opus 5.5 |
| Branch | `PTY-3-schema-and-models` |
| Release | v0.2.0 |
| Depends on | PTY-2 merged |

## Goal

Every table in [data.md](../data.md) with its database-level guards, the Eloquent models, public identifiers (ULIDs and document numbers), enums and factories. Everything later builds on this.

## Covers

data.md (all tables and guards). Q-006 (integer quantities, single unit). D-024 (triggers). D-031 (ULIDs, document numbers). validation.md G6 (case-insensitive unique names). Tests T19, T25 (part).

## Contract

No endpoints. Shapes in [api.md](../api.md) drive which models get a `ulid`.

## Flows

Foundation for all of F1 to F20. Document numbers are used by F5, F8 and F13.

## Acceptance criteria

### Tables
- [x] Migrations for: ingredients, suppliers, menu_items, recipe_lines, purchase_orders, purchase_order_lines, deliveries, delivery_lines, sales, stock_movements, document_sequences. Foreign keys, unique constraints and indexes as in data.md.
- [x] Quantities are `unsignedInteger`. `stock_movements.quantity_delta` is a signed `integer`. No float or decimal columns anywhere.
- [x] `name` columns on ingredients, suppliers and menu_items use `->collation('NOCASE')` with a unique index, so case-insensitive uniqueness is enforced by the database (G6). A comment notes the MySQL equivalent (a case-insensitive collation is the default there).
- [x] `stock_movements` has no `updated_at`. Its migration creates the triggers `stock_movements_no_update` and `stock_movements_no_delete` (`RAISE(ABORT, 'stock_movements is append-only')`); `down()` drops them; a comment notes the MySQL/Postgres equivalent (D-024).
- [x] `sales.pos_reference` is nullable and unique.
- [x] `purchase_orders.short_closed` is boolean, default false.

### Public identifiers (D-031)
- [x] A `ulid` column (char 26, unique, indexed) on ingredients, suppliers, menu_items, purchase_orders, purchase_order_lines, deliveries and sales. Not on recipe_lines, delivery_lines, stock_movements or document_sequences, which are never addressed directly.
- [x] `App\Models\Concerns\HasPublicUlid` trait:
  - generates the ULID on `creating`;
  - `getRouteKeyName()` returns `'ulid'`;
  - `$hidden` includes `id` and every `*_id` foreign key, so they can never be serialised by accident.
  - The builder verifies whether Laravel's `HasUlids` with `uniqueIds()` can do this while keeping an integer primary key, and uses it if so. The choice is recorded in the builder notes.
- [x] A `number` column (unique) on purchase_orders, deliveries and sales.
- [x] `document_sequences` table: `type` (`PO`, `GRN`, `SALE`), `year`, `last_value`, unique(type, year).
- [x] `App\Services\DocumentNumber::next(string $type): string`:
  - must be called inside a transaction;
  - locks the row, increments it and formats the number (`PO-2026-0001`, `GRN-2026-0001`, `SALE-2026-000001`);
  - starts a new sequence each calendar year (UTC).

### Models and enums
- [x] Enums `App\Enums\Unit` (g, ml, piece), `PurchaseOrderStatus` (cases only; the transition map comes in PTY-7), `MovementReason` (delivery, sale). Each has `label()`.
- [x] Models with relations, enum casts and `$fillable`. `StockMovement` has `UPDATED_AT = null`.
- [x] Factories for every model. Factories create valid ULIDs and numbers.
- [x] Remove the default `users` migration, model and factory (no auth, D-001).
- [x] Minimal `DatabaseSeeder`: the six ingredients, three suppliers and three menu items with recipes from the brief. No POs, sales or movements. The realistic seed through services is PTY-22.

## Tests required
- [x] `tests/Feature/Schema/ConstraintsTest.php`:
  - a duplicate ingredient in a recipe is rejected by the database;
  - a duplicate ingredient on a PO is rejected by the database;
  - "Beef" and "beef" cannot both exist (NOCASE unique);
  - a duplicate `pos_reference` is rejected by the database.
- [x] T19: raw `DB::table('stock_movements')->update(...)` and `->delete()` both throw.
- [x] `tests/Feature/Schema/DocumentNumberTest.php`:
  - numbers are sequential per type (PO-2026-0001, then 0002);
  - types are independent;
  - a new year restarts at 0001 (using `travelTo`).
- [x] `tests/Unit/PublicUlidTest.php`: a created model has a 26-character ULID, its route key is `ulid`, and `toArray()` contains no `id` and no `*_id`.

## Test data

Factories only. The document number test travels from 2026-12-31 23:59 UTC to 2027-01-01 00:01 UTC.

## Files expected to touch

- New:
  - `database/migrations/*` (11 tables + triggers)
  - `app/Models/*` (10 models)
  - `app/Models/Concerns/HasPublicUlid.php`
  - `app/Enums/*`
  - `app/Services/DocumentNumber.php`
  - `database/factories/*`
  - tests above
- Removed: `users` migration, `User` model, `UserFactory`.
- Modified: `DatabaseSeeder`.

## Done means

1. `php artisan migrate:fresh --seed` runs.
2. `php artisan tinker`: `App\Models\Ingredient::first()->ulid` shows a 26-character string.
3. In tinker, insert a movement first (the triggers are row-level, so deleting from an empty table succeeds silently): `DB::table('stock_movements')->insert(['ingredient_id' => 1, 'quantity_delta' => 1, 'reason' => 'sale', 'reference_type' => 'x', 'reference_id' => 1, 'occurred_at' => now()])`. Then `DB::table('stock_movements')->update(['quantity_delta' => 5])` and `->delete()` both fail with "append-only".

## Out of scope

Services other than DocumentNumber, controllers, routes, the realistic seed (PTY-22).

## Additions from D-035 to D-038

- [x] Ingredients: nullable `over_tolerance_bps`, `under_tolerance_bps` (unsigned smallint), `over_tolerance_cap` (unsigned int), and `image_path` (string). Menu items: nullable `image_path`.
- [x] Purchase order lines: `over_tolerance_bps`, `under_tolerance_bps` (not null) and `over_tolerance_cap` (nullable), the snapshot columns (D-035).
- [x] `config/patty.php` `tolerance.defaults` per unit: g and ml (over 500, under 500, cap 2000); piece (over 500, under 500, cap null).
- [x] `Ingredient::effectiveTolerance(): Tolerance` merges the overrides with the unit default and reports the source (`default` or `ingredient`).
- [x] Test: an ingredient with no overrides gets the unit default; a partial override merges per field.

## Builder notes

### What was built, in order
1. Migrations for all 11 tables (plus a sessions-only migration, see deviations). Quantities are `unsignedInteger`, `quantity_delta` is a signed `integer`, no float or decimal columns. `name` on ingredients, suppliers and menu_items is `NOCASE` with a unique index (G6). `stock_movements` has no `updated_at` and two triggers (`stock_movements_no_update`, `stock_movements_no_delete`) created in `up()` and dropped in `down()`.
2. Enums `Unit`, `PurchaseOrderStatus` (cases and labels only), `MovementReason`; `config/patty.php` tolerance defaults; the readonly `App\Support\Tolerance` value object (`maxReceivable`, `minToComplete`, integer basis-point arithmetic only).
3. `HasPublicUlid` trait, 11 models, `Ingredient::effectiveTolerance()` and `PurchaseOrderLine::tolerance()` (reads the line's own snapshot).
4. `App\Services\DocumentNumber::next()`, factories for every model that needs one, and a minimal `DatabaseSeeder` (6 ingredients, 3 suppliers, 3 menu items with recipes; no POs, sales or movements).
5. Tests: `tests/Feature/Schema/ConstraintsTest.php`, `DocumentNumberTest.php`, `tests/Unit/PublicUlidTest.php`, `ToleranceTest.php`, `EffectiveToleranceTest.php`.

### ULID approach
Laravel's `HasUlids` works with an integer primary key. `HasUniqueStringIds` only changes `getKeyType()` and `getIncrementing()` when the primary key itself is listed in `uniqueIds()`. The trait overrides `uniqueIds()` to return `['ulid']`, so `id` stays an auto-increment integer, the `ulid` column is filled on insert, and a malformed route value (including a numeric id) gives a 404 through `resolveRouteBinding`. No hand-rolled `creating` hook was needed. The ULID is filled in `performInsert`, just after the `creating` event fires, so a `creating` observer sees it as null.

`$hidden` is computed in an overridden `getHidden()`: the model's own list plus `id` and every attribute ending in `_id`. A static `$hidden` list would have to be kept in sync with each model's foreign keys by hand.

### What Mohamad must be able to explain
- Why a ULID column beside an integer key (D-031) and how `uniqueIds()` makes that work.
- The tolerance arithmetic: `intdiv` rounds the allowance down, so 10 buns get no allowance; the cap clamps large weighed goods (50000 g gets 2000, not 2500). A cap of 0 and a bps of 0 are valid overrides, which is why `effectiveTolerance()` tests for null, not falsy.
- The triggers are row-level: a `DELETE` on an empty table does not raise. That is expected, and the test inserts a row first.
- `DocumentNumber::next()` inserts the counter row with `insertOrIgnore`, then reads it with `lockForUpdate`. SQLite ignores the lock (its single writer already serialises); MySQL and Postgres honour it.

### Deviations and things not done
- **Sessions migration kept.** `.env.example` uses `SESSION_DRIVER=database`, so deleting the whole default users migration would break `php artisan serve`. The users migration, `User` model and `UserFactory` are removed; `0001_01_01_000000_create_sessions_table.php` keeps only the `sessions` table.
- `config/auth.php` still imports `App\Models\User` for its `AUTH_MODEL` default. It is only a class-name string, so the app boots and tests pass. Left alone (outside my ownership).
- **No `DocumentSequence` factory.** Only `DocumentNumber` touches that table, and a factory would be unused. There are 11 models rather than the ticket's 10.
- **No morph map.** `stock_movements.reference_type` stores full class names (`App\Models\Sale`, `App\Models\DeliveryLine`). A morph map belongs in a service provider, which this ticket may not edit.
- **No test for the "must be inside a transaction" guard** in `DocumentNumber`: `RefreshDatabase` wraps every test in a transaction, so the guard cannot be reached from a test. It is a plain `transactionLevel() < 1` check.
- `docs/AI_LOG.md` was not touched by this builder.

## Reviewer findings

Reviewer: Opus 5.5. Verdict: **CHANGES REQUESTED** (two majors, both small). Pint passes, 39 tests pass, `migrate:fresh --seed` runs. 21 of 21 mutations were caught by the suite (NOCASE x3, both composite uniques, `pos_reference` unique, each trigger, `short_closed` default, the Tolerance cap and both roundings, the DocumentNumber year, type and increment, the `??` merge, the source label, `getHidden`, `uniqueIds`, the route key). Scope is clean. The commits are authored by the owner, with no trailers.

Builder deviations: accepted are the sessions table, `config/auth.php` (#12), no DocumentSequence factory and row-level triggers (but see #6). The missing morph map is accepted only if PTY-4 lists it (#7). The reason given for the untested transaction guard is not accepted (#1).

| # | Severity | File:line | Finding | Resolution |
|---|---|---|---|---|
| 1 | major | app/Services/DocumentNumber.php:53 | `RefreshDatabase` keeps every test at transaction level 1 or more, so the suite can never see this guard. A later service (PTY-7, 8 or 9) that forgets `DB::transaction` would pass every test and then throw `LogicException` (a 500) in production. The guard can be tested with `DB::shouldReceive('transactionLevel')->andReturn(0)`. A simpler fix is to wrap the body in `DB::transaction()`: when nested it becomes a savepoint inside the caller's transaction, and when it stands alone it is safe. Pick one and test it. | fixed in 51c0ebc |
| 2 | major | app/Support/Tolerance.php:58, docs/validation.md:29 | When `under_bps` is 10000, `minToComplete()` returns 0. The line is then "complete" with nothing received, and the first delivery on any other line auto-closes the PO. validation.md allows `max:10000`. Cap `under_tolerance_bps` at 9999, or guard it in Tolerance, and add a test. With any value below 10000 the result is at least 1. | fixed in b3c5580 |
| 3 | minor | database/migrations/2026_10_01_000004:16, 000007:16, 000009:15, 000010:16, 000011 | SQLite ignores UNSIGNED. A raw insert of `quantity = -5` into recipe_lines was accepted (verified). data.md promises `> 0` and `quantity_delta != 0`, and the database enforces neither. Either add guards (BEFORE INSERT triggers in the style of D-024) or change data.md to say that validation and services enforce it. Do not claim in the interview that the database rejects negatives. | doc wording (see data.md), fixed in b3c5580 |
| 4 | minor | database/factories/PurchaseOrderFactory.php:21, DeliveryFactory.php:19, SaleFactory.php:19 | Factory numbers use the real format, the current year and a random 1..9999. A later test that mixes factory documents with `DocumentNumber` output can hit unique(number) at random, which makes it flaky. Use a shape that cannot collide (for example `PO-TEST-%06d`, or year 1999). | fixed in 5702269 |
| 5 | minor | database/factories/DeliveryFactory.php:20, DeliveryLineFactory.php:21 | The default factory data is invalid in domain terms. A delivery is created against a Draft PO, and a delivery line's PO line belongs to a different PO than its delivery. Later tests that rely on the defaults would build impossible states. Make the defaults coherent (sent PO; PO line from the delivery's PO), or document them as raw rows only. | fixed in 5702269 |
| 6 | minor | docs/tickets/PTY-3-schema-and-models.md:92 (Done means 3) | On a freshly seeded database `stock_movements` is empty. The triggers are row-level, so `DB::table('stock_movements')->delete()` succeeds silently (verified in tinker). Change the step to insert a movement first, so the live demo does not backfire. | open |
| 7 | minor | app/Models/StockMovement.php (reference morph) | `reference_type` stores `App\Models\Sale` and `App\Models\DeliveryLine`. The builder defers the morph map to PTY-4, but the PTY-4 ticket does not mention it (no "morph" in docs/tickets outside PTY-3). Add `Relation::enforceMorphMap` to the PTY-4 acceptance criteria, so it is in place before the first real movement is written. | deferred to PTY-4 |
| 8 | minor | app/Models/Concerns/HasPublicUlid.php (HasUlids) | `HasUlids` generates lowercase ULIDs, but `Str::isUlid` and the `ulid` rule also accept uppercase. Lookups are case-sensitive, so an uppercase ULID passes validation and then misses (verified: count 0). Lowercase incoming ULIDs in PTY-16 or PTY-5 (G4), or record it as known. | fixed in 4e91acf |
| 9 | nit | app/Models/Concerns/HasPublicUlid.php:40 | The `getHidden()` override makes `makeVisible('id')` a silent no-op (verified). That is the intent, but the docblock should say so. RecipeLine, DeliveryLine and StockMovement do not use the trait, so their `toArray()` exposes `id` and `*_id` (verified on RecipeLine). The PTY-5 to PTY-10 Resources must list their fields explicitly. | deferred (later Resources list fields explicitly) |
| 10 | nit | database/migrations/2026_10_01_000001:14-17 (and 000002, 000003) | `collation('NOCASE')` is SQLite-only and would fail on MySQL ("unknown collation"). The comment says MySQL "needs no equivalent". Reword it: on MySQL, drop the collation call, because the default `_ci` collation is already case-insensitive. | fixed in f001012 |
| 11 | nit | app/Models/PurchaseOrderLine.php:44 | `tolerance()` leaves `source` at its default, `'default'`, which misdescribes a snapshot. Pass an explicit source, or note that the source is meaningful only from `effectiveTolerance()`. | fixed in b3c5580 |
| 12 | nit | config/auth.php:3,67 | Dangling reference to the deleted `App\Models\User`. It is harmless (a class-name string that is never resolved, because nobody logs in). Clean it up in a ticket that owns config. | accepted (not in scope) |
| 13 | nit | docs/tickets/PTY-3-schema-and-models.md:7, docs/AI_LOG.md | The ticket status is still "To Do" and no acceptance criterion is ticked. `docs/AI_LOG.md` has no entry for PTY-3, which the CLAUDE.md hard rule requires. | open |
| 14 | nit | database/migrations/2026_10_01_000009:15 | No index on `delivery_lines.purchase_order_line_id`, which `received(line)` sums over. That is fine at this scale and worth one line if performance comes up. | fixed in f001012 |
