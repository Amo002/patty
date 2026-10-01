# Docs: start here

This folder is the context every person and AI agent reads **before** writing code. It is not documentation written after the fact.

## Reading order

| # | Doc | Purpose |
|---|---|---|
| 1 | [architecture.md](architecture.md) | The big picture: problem, layers, flows, protection, how it joins an ERP |
| 2 | [progress.md](progress.md) | Where work stopped and what is next. **Open first every session.** |
| 3 | [tickets/BOARD.md](tickets/BOARD.md) | Ticket board |
| 4 | [workflow.md](workflow.md) | Ticket to branch to review to merge to release. Naming. Definition of Done. |
| 5 | [agents.md](agents.md) | Who builds, who reviews, on which model |
| 6 | [tools.md](tools.md) | Tools and skills we use, and what agents may never touch |

## Product

| Doc | Purpose |
|---|---|
| [brief.md](brief.md) | The problem, who has it, what success looks like |
| [users.md](users.md) | Manager and POS: their tasks |
| [requirements.md](requirements.md) | FR and NFR with acceptance criteria |
| [scope.md](scope.md) | In, and deliberately out |
| [questions/](questions/) | Where the brief was unclear, and the answers |

## Engineering

| Doc | Purpose |
|---|---|
| [stack.md](stack.md) | What we use, conventions, and what we never use |
| [data.md](data.md) | Tables, derived values, invariants |
| [api.md](api.md) | API contract v1: every endpoint, shapes, status and error codes, versioning |
| [validation.md](validation.md) | Every input rule, per endpoint and field |
| [flows.md](flows.md) | Every flow end to end, plus "A day at Patty" |
| [ui.md](ui.md) | Pages, URLs, data sources, behaviour rules |
| [security.md](security.md) | Threat model and security review plan |
| [design.md](design.md) | Design tokens, motion, components, icons |
| [testing.md](testing.md) | The tests that matter, and the manual QA checklist |
| [environments.md](environments.md) | local, test, dev, prod, and why nothing is deployed |

## Living records (append-only)

| Doc | Purpose |
|---|---|
| [decisions.md](decisions.md) | What we chose, what we rejected, why |
| [AI_LOG.md](AI_LOG.md) | How AI was used and where it was wrong |
