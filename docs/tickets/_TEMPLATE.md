# PTY-N Title

| Field | Value |
|---|---|
| Type | Task / Story / Bug |
| Phase | 3a Build backend / 3b Build frontend / ... (see progress.md) |
| Status | To Do / In Progress / In Review / Awaiting Mohamad / Done |
| Weight | S / M / L |
| Builder | model |
| Reviewer | model (one tier above builder) |
| Branch | `PTY-N-slug` |
| Release | vX.Y.Z |
| Depends on | PTY-x, Q-x |

## Goal

One or two sentences: what this ticket delivers and why.

## Covers

Requirements: FR-x ACy. Questions: Q-x. Decisions: D-x. Tests from [testing.md](../testing.md): Tx.

## Contract

Endpoints: [api.md](../api.md) E-n. Field rules: [validation.md](../validation.md) section. Never restate payloads here; link to them.

## Flows

[flows.md](../flows.md) F-n. The reviewer checks "Rows written" and "Error paths" against the code.

## Acceptance criteria

- [ ] ...

## Tests required

- [ ] `tests/Feature/...`: description (must fail if the logic is removed)

## Test data

The exact factory setup and numbers each test uses, so the builder does not invent them.

## Files expected to touch

New: `app/...`. Modified: `...`.

## Done means

A three-line demo Mohamad runs by hand to see the ticket working (curl or UI steps, and the expected result).

## Out of scope for this ticket

- ...

---

## Builder notes

What was done, in order. Every non-obvious block: "this does X because Y". Anything Mohamad should be able to explain.

## Reviewer findings

| # | Severity | File:line | Finding | Resolution |
|---|---|---|---|---|

## Mohamad review

- [ ] Read every changed line
- [ ] Can explain it without notes
- [ ] Ran manual QA (testing.md QA-x)
- [ ] Worked example checked by hand (L tickets)

Approved: (date)
