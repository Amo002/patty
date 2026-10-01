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
