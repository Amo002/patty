# PTY-26 Unique-index violation returns a clean error, not 500

| Field | Value |
|---|---|
| Type | Bug |
| Phase | 3a Build backend |
| Status | In Review |
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

## Mohamad review

- [ ] Read every changed line
- [ ] Can explain it without notes
- [ ] Ran manual QA (testing.md QA-x)

Approved: (date)
