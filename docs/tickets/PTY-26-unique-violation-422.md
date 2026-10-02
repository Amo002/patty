# PTY-26 Unique-index violation returns a clean error, not 500

| Field | Value |
|---|---|
| Type | Bug |
| Phase | 3a Build backend |
| Status | Done |
| Weight | S |
| Builder | Sonnet 5.5 |
| Reviewer | Opus 5.5 |
| Branch | `PTY-26-unique-violation-422` |
| Release | v0.3.0 |
| Depends on | PTY-16 |

## Goal

Names (and pos_reference, document numbers, duplicate lines) are unique twice: `Rule::unique` gives a friendly 422, and a NOCASE unique index is the backstop (D-024, validation.md G6). When two requests race, the loser passes validation and hits the index, and the API answered 500 `server_error`. It must answer with a clean, documented error. Found in the PTY-6 review.

## Covers

D-019, D-024. validation.md G6.

## Contract

[api.md](../api.md) status table: new row `409 conflict`.

## Acceptance criteria

- [ ] `bootstrap/app.php` API render closure maps `Illuminate\Database\UniqueConstraintViolationException` to 409 `conflict`, message "This record already exists or was just created by another request. Refresh and try again.", `errors` empty.
- [ ] The response never contains SQL or the constraint name.
- [ ] The exception is still reported (full detail in the log), plus a warning line without SQL.
- [ ] `docs/api.md` status table lists `409 conflict`.

## Tests required

- [ ] `tests/Feature/Api/UniqueViolationTest.php`:
  - a case-different duplicate returns 409, `code: conflict`, `success: false`, `errors: []`;
  - no SQL text in the body;
  - `Exceptions::assertReported(UniqueConstraintViolationException::class)`;
  - the tests fail when the mapping is removed.

## Done means

Send two inserts differing only by case past validation (the test route does this): the second returns 409 `conflict` instead of 500.

## Out of scope for this ticket

- Changing any FormRequest or index.
- Retrying the write automatically.

---

## Builder notes

- **Why 409 and not 422:** api.md defines 409 as "the request is valid but the current state forbids it". A race-lost duplicate is exactly that, because the input passed validation. 422 stays for input that is malformed.
- The mapping is one `match` arm in the single render closure (D-019), so no controller or service needs a try/catch.
- The message is fixed text. The SQL and constraint name stay in the log only (S8).
- Logging: Laravel still reports the exception (error level, full SQL, in the `errors` channel). The closure adds a warning with method and path only, so a lost race is visible without being mistaken for a bug.
- Test scaffolding: a throwaway table with a NOCASE unique index and a test-only route doing a raw insert, so validation is bypassed the way a race bypasses it.

## Reviewer findings

| # | Severity | File:line | Finding | Resolution |
|---|---|---|---|---|
| R1 | Low | bootstrap/app.php:97-104 | The extra `Log::warning` writes a second line next to the error-level report, so the race is logged twice and still shows at error level. The comment says it keeps a lost race from looking like a bug, but the error entry is still there. It is also untested (changing it to `Log::debug` keeps the suite green). Suggest removing it and adding `$exceptions->level(UniqueConstraintViolationException::class, LogLevel::WARNING)`. That gives one log entry at warning level with the full detail, and the "still reported" test stays valid. | Fixed. Log::warning removed; `$exceptions->level(UniqueConstraintViolationException::class, LogLevel::WARNING)` registered. PTY-26d asserts one record at Warning level. |
| R2 | Low | tests/Feature/Api/UniqueViolationTest.php:49-58 | PTY-26b also passes on a generic 500, which never leaks either. A leaked message is still caught by PTY-26a's exact-message assertion, so the suite is sound, but 26b does not prove anything on its own. Add `->assertStatus(409)` before reading the body. | Fixed. 26b asserts 409 first. |
| R3 | Low | docs/api.md:58-81 | The status table has the new row, but the endpoint table's error column for writes guarded by a unique index (E3, E5, E8, E10, E12, E14, E15, E17, E19, E25) does not mention 409 `conflict`. Either add it to those rows or add one line under the status table: "any write guarded by a unique index can return 409 `conflict`". | Fixed. One general note added under the status table in api.md. |
| R4 | Info (for PTY-9) | docs/api.md:81 | Once `/sales` exists, two identical POS retries that race will have the loser hit the `pos_reference` unique index, and it will get 409 `conflict` instead of the 200 replay D-029 promises. PTY-9 should catch `UniqueConstraintViolationException` around the insert, re-read the sale and run the replay or `idempotency_conflict` comparison. This ticket is the generic fallback, not that path. | Noted. Handled by the orchestrator in PTY-9. |
| R5 | Info | ticket name / branch | The slug says `422`, but the decision is 409. Renaming the branch would be churn. The title and body are correct, so the mismatch is cosmetic. | Accepted. Cosmetic, branch not renamed. |

## Mohamad review

- [ ] Read every changed line
- [ ] Can explain it without notes
- [ ] Ran manual QA (testing.md QA-x)

Approved: (date)
