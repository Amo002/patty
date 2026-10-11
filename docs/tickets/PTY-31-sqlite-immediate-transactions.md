# PTY-31 SQLite transactions take the write lock when they begin

| Field | Value |
|---|---|
| Type | Bug |
| Phase | Post-release extension |
| Status | In review |
| Weight | S |
| Builder | Opus 5.5 (orchestrator, small change) |
| Reviewer | Mohamad |
| Branch | `PTY-31-sqlite-immediate-transactions` |
| Release | v1.1.0 |
| Depends on | PTY-30 (merged, PR #35) |

## Goal

Two writes racing on SQLite (two refunds for the last unit, two identical POS retries, a delivery and a status change) must end with one success and one clean answer (422, 409 or a replay), never a 500. Found in the PTY-30 review (R17); first noted as Info in the PTY-9 review.

## Cause

`lockForUpdate()` compiles to nothing on SQLite, and Laravel's default `transaction_mode` is DEFERRED: `BEGIN` takes no lock. Two transactions both read and pass their checks; the second one's first write then fails at once with SQLITE_BUSY (SQLite skips the busy handler there, to avoid a deadlock).

## Fix (D-050)

- `config/database.php`: `transaction_mode` IMMEDIATE, `busy_timeout` 5000. `BEGIN IMMEDIATE` takes the write lock, a second writer waits up to 5 s, then reads the first one's committed rows and gets the normal answer.
- Docblocks in DocumentNumber, IngredientService, PurchaseOrderService, ReceivingService and SaleReturnService said a racing writer "fails (database is locked)". They now say it waits.

## Acceptance criteria

- [x] A transaction that has only read already holds the write lock against another connection.
- [x] The config values are pinned by a test.
- [x] Full suite green.

## Tests

- `tests/Feature/Schema/SqliteTransactionsTest.php`: on a real file database, a transaction that has only run a `select` refuses a second connection's `BEGIN IMMEDIATE` (with no wait). Fails before the fix (verified: "Failed asserting that false is true"), passes after. A second test pins IMMEDIATE and the busy timeout.

## Builder notes

- Why a file and a second connection: the in-memory test database is private to one connection, so it cannot show a lock held against another.
- Not tested end to end with two HTTP requests: `php artisan serve` handles one request at a time on Windows, so it cannot produce a real overlap. The lock test proves the property the services rely on.

## Mohamad review

- [ ] Read every changed line
- [ ] Can explain it without notes

Approved: (date)
