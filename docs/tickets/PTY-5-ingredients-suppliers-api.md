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
| Depends on | PTY-4 |

## Goal

Create, list and update ingredients and suppliers over `/api/v1`, plus the shared API plumbing every later ticket uses.

## Covers

FR-1 AC1 to AC6. NFR-3, NFR-6. Q-007 (unit lock).

## Acceptance criteria

- [ ] `routes/api.php` with the `/api/v1` prefix
- [ ] Middleware `ForceJsonResponse` (api group) and `NoStoreCache` (api and web groups)
- [ ] `bootstrap/app.php` renders `App\Exceptions\Domain\DomainException` subclasses as 422 `{ message }`, and 404s as JSON on api routes
- [ ] `GET/POST /ingredients`, `GET/PATCH /ingredients/{id}`. The list includes `on_hand` from `StockLedger::onHandForAll()`.
- [ ] `GET/POST /suppliers`, `GET/PATCH /suppliers/{id}`
- [ ] FormRequests: unique name (case-insensitive), unit in the `Unit` enum
- [ ] Changing an ingredient's unit when movements exist throws `UnitLocked` (422)
- [ ] API Resources for both

## Tests required

- [ ] Create and list an ingredient. The list shows `on_hand`.
- [ ] Duplicate name, case-insensitive, gives 422 with `errors.name`
- [ ] Invalid unit gives 422
- [ ] Unit change blocked once a movement exists, allowed before
- [ ] Create and list a supplier
- [ ] API responses carry `Cache-Control: no-store` (T14)
