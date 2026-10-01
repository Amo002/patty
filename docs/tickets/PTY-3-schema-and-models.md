# PTY-3 Schema, models, factories and demo seed

| Field | Value |
|---|---|
| Type | Story |
| Status | To Do |
| Weight | M |
| Builder | Opus 5.5 |
| Reviewer | Fable 5.1 |
| Branch | `PTY-3-schema-and-models` |
| Release | v0.2.0 |
| Depends on | PTY-2, Q-006 |

## Goal

Every table in [data.md](../data.md), its Eloquent model, enums, factories, and a seeder that sets up the Classic Burger scenario from the brief.

## Covers

data.md (all tables). Q-006 (integer quantities, single unit).

## Acceptance criteria

- [ ] Migrations for all ten tables, with the foreign keys, unique constraints and indexes in data.md
- [ ] Quantities are `unsignedInteger`. `stock_movements.quantity_delta` is a signed `integer`. No float or decimal columns.
- [ ] `stock_movements` has no `updated_at`
- [ ] Enums: `App\Enums\Unit` (g, ml, piece), `App\Enums\PurchaseOrderStatus` (cases only; the transition logic comes in PTY-7), `App\Enums\MovementReason` (delivery, sale). Each has a `label()` for display.
- [ ] Models with relations and enum casts. `StockMovement` has `UPDATED_AT = null`.
- [ ] Factories for every model
- [ ] Remove the unused default `users` migration and model (no auth, D-001)
- [ ] `DatabaseSeeder`:
  - ingredients beef (g), bun (piece), cheese (g), lettuce (g), tomato (g), fries (g);
  - suppliers "Al-Mashreq Meats", "Golden Bakery", "Fresh Farms";
  - menu items "Classic Burger" (beef 150, bun 1, cheese 20), "Double Burger", "Fries";
  - one sent PO. No stock movements: stock starts at 0, and the seeder goes through services once they exist.

## Tests required

- [ ] `tests/Feature/Schema/ConstraintsTest.php`: duplicate recipe ingredient and duplicate PO line ingredient are rejected by the database

## Out of scope

Services, controllers, routes.
