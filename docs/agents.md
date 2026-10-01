# Agents

AI is used as a team of builders and reviewers under one human owner. **Mohamad is the author of everything that ships.** Agents draft, propose and explain. He reads, understands and merges.

## Roles

| Role | Who | Responsibilities | Never does |
|---|---|---|---|
| Owner | Mohamad | Answers questions, reviews every line, runs manual QA, merges PRs, cuts releases | |
| Orchestrator | Main Claude Code session (Fable 5.1, switched with `/model`) | Writes tickets and questions, dispatches builders and reviewers, keeps `progress.md`, `BOARD.md` and `AI_LOG.md` current, reports back after every ticket | Merges PRs, answers BRD questions on Mohamad's behalf |
| Builder | Sub-agent, model by ticket weight | Implements one ticket on its branch, writes the tests named in the ticket, explains every non-obvious block in the ticket's Builder notes | Touches files outside the ticket, adds dependencies, pushes to `develop`/`main` |
| Reviewer | Sub-agent, **always one tier above the builder** | Reviews the diff against the ticket ACs, [data.md](data.md) invariants and [requirements.md](requirements.md). Records findings with severity and `file:line`. Checks that each test would fail if the logic were removed. | Edits code (it reports, the builder fixes) |

## Model ladder

Tiers, lowest to highest: Haiku 4.5 < Sonnet 5.5 < Opus 5.5 < Fable 5.1.

| Ticket weight | Typical work | Builder | Reviewer |
|---|---|---|---|
| S | Docs, CRUD endpoints, config, Postman, Docker | Sonnet 5.5 | Opus 5.5 |
| M | Schema, visibility queries, design system, UI pages | Opus 5.5 | Fable 5.1 |
| L | Stock ledger, state machine, receiving, sales | Opus 5.5 | Fable 5.1 |

Why the reviewer is higher: a reviewer weaker than the builder approves what it cannot fully follow. Review is where confident wrong answers get caught, so it gets the strongest model.

## Extra rule for stock arithmetic (L tickets)

The stock arithmetic and outstanding-quantity logic are never merged on an agent's word. Before Mohamad's review, the orchestrator walks him through the logic with a worked example in the ticket (numbers in, numbers out), and he checks the numbers by hand.

## Handoff format

Every builder report and reviewer report is appended to its ticket file, not left in chat. Then the reasoning lives in the repo, where Mohamad and the interviewers can read it.
