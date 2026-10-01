# PTY-3 Schema, models, identifiers and factories

| Field | Value |
|---|---|
| Type | Story |
| Phase | 3a Build backend |
| Status | To Do |
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
- [ ] Migrations for: ingredients, suppliers, menu_items, recipe_lines, purchase_orders, purchase_order_lines, deliveries, delivery_lines, sales, stock_movements, document_sequences. Foreign keys, unique constraints and indexes as in data.md.
- [ ] Quantities are `unsignedInteger`. `stock_movements.quantity_delta` is a signed `integer`. No float or decimal columns anywhere.
- [ ] `name` columns on ingredients, suppliers and menu_items use `->collation('NOCASE')` with a unique index, so case-insensitive uniqueness is enforced by the database (G6). A comment notes the MySQL equivalent (a case-insensitive collation is the default there).
- [ ] `stock_movements` has no `updated_at`. Its migration creates the triggers `stock_movements_no_update` and `stock_movements_no_delete` (`RAISE(ABORT, 'stock_movements is append-only')`); `down()` drops them; a comment notes the MySQL/Postgres equivalent (D-024).
- [ ] `sales.pos_reference` is nullable and unique.
- [ ] `purchase_orders.short_closed` is boolean, default false.

### Public identifiers (D-031)
- [ ] A `ulid` column (char 26, unique, indexed) on ingredients, suppliers, menu_items, purchase_orders, purchase_order_lines, deliveries and sales. Not on recipe_lines, delivery_lines, stock_movements or document_sequences, which are never addressed directly.
- [ ] `App\Models\Concerns\HasPublicUlid` trait:
  - generates the ULID on `creating`;
  - `getRouteKeyName()` returns `'ulid'`;
  - `$hidden` includes `id` and every `*_id` foreign key, so they can never be serialised by accident.
  - The builder verifies whether Laravel's `HasUlids` with `uniqueIds()` can do this while keeping an integer primary key, and uses it if so. The choice is recorded in the builder notes.
- [ ] A `number` column (unique) on purchase_orders, deliveries and sales.
- [ ] `document_sequences` table: `type` (`PO`, `GRN`, `SALE`), `year`, `last_value`, unique(type, year).
- [ ] `App\Services\DocumentNumber::next(string $type): string`:
  - must be called inside a transaction;
  - locks the row, increments it and formats the number (`PO-2026-0001`, `GRN-2026-0001`, `SALE-2026-000001`);
  - starts a new sequence each calendar year (UTC).

### Models and enums
- [ ] Enums `App\Enums\Unit` (g, ml, piece), `PurchaseOrderStatus` (cases only; the transition map comes in PTY-7), `MovementReason` (delivery, sale). Each has `label()`.
- [ ] Models with relations, enum casts and `$fillable`. `StockMovement` has `UPDATED_AT = null`.
- [ ] Factories for every model. Factories create valid ULIDs and numbers.
- [ ] Remove the default `users` migration, model and factory (no auth, D-001).
- [ ] Minimal `DatabaseSeeder`: the six ingredients, three suppliers and three menu items with recipes from the brief. No POs, sales or movements. The realistic seed through services is PTY-22.

## Tests required
- [ ] `tests/Feature/Schema/ConstraintsTest.php`:
  - a duplicate ingredient in a recipe is rejected by the database;
  - a duplicate ingredient on a PO is rejected by the database;
  - "Beef" and "beef" cannot both exist (NOCASE unique);
  - a duplicate `pos_reference` is rejected by the database.
- [ ] T19: raw `DB::table('stock_movements')->update(...)` and `->delete()` both throw.
- [ ] `tests/Feature/Schema/DocumentNumberTest.php`:
  - numbers are sequential per type (PO-2026-0001, then 0002);
  - types are independent;
  - a new year restarts at 0001 (using `travelTo`).
- [ ] `tests/Unit/PublicUlidTest.php`: a created model has a 26-character ULID, its route key is `ulid`, and `toArray()` contains no `id` and no `*_id`.

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
3. `DB::table('stock_movements')->delete()` in tinker fails with "append-only".

## Out of scope

Services other than DocumentNumber, controllers, routes, the realistic seed (PTY-22).

## Additions from D-035 to D-038

- [ ] Ingredients: nullable `over_tolerance_bps`, `under_tolerance_bps` (unsigned smallint), `over_tolerance_cap` (unsigned int), and `image_path` (string). Menu items: nullable `image_path`.
- [ ] Purchase order lines: `over_tolerance_bps`, `under_tolerance_bps` (not null) and `over_tolerance_cap` (nullable), the snapshot columns (D-035).
- [ ] `config/patty.php` `tolerance.defaults` per unit: g and ml (over 500, under 500, cap 2000); piece (over 500, under 500, cap null).
- [ ] `Ingredient::effectiveTolerance(): Tolerance` merges the overrides with the unit default and reports the source (`default` or `ingredient`).
- [ ] Test: an ingredient with no overrides gets the unit default; a partial override merges per field.

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
