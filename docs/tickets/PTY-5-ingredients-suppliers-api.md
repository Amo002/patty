# PTY-5 Ingredients and suppliers API

| Field | Value |
|---|---|
| Type | Story |
| Status | To Do |
| Weight | S |
| Builder | Sonnet 5.5 |
| Reviewer | Opus 5.5 |
| Branch | `PTY-5-ingredients-suppliers-api` |
| Release | v0.2.0 |
| Depends on | PTY-4, PTY-16 |

## Goal

Create, list and update ingredients and suppliers over `/api/v1`. The envelope, error rendering, middleware, audit and logging already exist (PTY-16). This ticket only uses them.

## Covers

FR-1 AC1 to AC6. Q-007 (unit lock). D-021, D-022 (catalog audit and logs).

## Acceptance criteria

- [ ] `GET/POST /ingredients`, `GET/PATCH /ingredients/{id}`. The list includes `on_hand` from `StockLedger::onHandForAll()`.
- [ ] `GET/POST /suppliers`, `GET/PATCH /suppliers/{id}`
- [ ] Controllers extend `Api\ApiController`. Create returns 201 via `created()`.
- [ ] FormRequests: unique name (case-insensitive), unit in the `Unit` enum, string length bounds
- [ ] `App\Services\CatalogService` (or one service per aggregate) holds create/update. Changing an ingredient's unit when movements exist throws `UnitLocked` (409 `unit_locked`).
- [ ] `LogsActivity` on Ingredient and Supplier (dirty fields only). One `catalog` log line per create/update.
- [ ] API Resources for both

## Tests required

- [ ] Create and list an ingredient. The list shows `on_hand`.
- [ ] Duplicate name, case-insensitive, gives 422 with `errors.name`
- [ ] Invalid unit gives 422
- [ ] Unit change blocked once a movement exists (409 `unit_locked`), allowed before
- [ ] Create and list a supplier
- [ ] Renaming an ingredient writes an audit entry with the old and new name
