# Board: Patty (PTY)

Updated by the orchestrator whenever a ticket moves. Grouped by phase, in build order within each phase. Phase journal: [progress.md](../progress.md).

## Status summary

| Status | Tickets |
|---|---|
| Done | PTY-1 |
| Awaiting Mohamad | PTY-2 |
| In Review | (none) |
| In Progress | (none) |
| To Do | PTY-3 to PTY-17, PTY-21, PTY-22 |

## By phase

### 0 Discovery and planning
| Ticket | Title | Status | Release |
|---|---|---|---|
| [PTY-1](PTY-1-project-tooling.md) | Project tooling | Done | v0.1.0 |

### 1 Specification + 2a Backend design
| Ticket | Title | Status | Release |
|---|---|---|---|
| [PTY-2](PTY-2-project-docs.md) | Project docs, questions, decisions, contract, flows, tickets | Awaiting Mohamad | v0.1.0 |

### 2b Frontend design
No tickets. Design artifacts are produced and approved here (progress.md). The UI ticket split (Q-012) and the PTY-17 scope are decided at the end of this phase. Runs alongside 3a.

### 3a Build backend

Run in waves of two parallel builders and one shared reviewer (D-040):
1. PTY-3 with PTY-16
2. PTY-4 with PTY-6
3. PTY-5 with PTY-7
4. PTY-8 with PTY-9
5. PTY-10

| # | Ticket | Title | Wt | Builder / Reviewer | Release |
|---|---|---|---|---|---|
| 1 | [PTY-3](PTY-3-schema-and-models.md) | Schema, models, identifiers, factories | M | Sonnet / Opus | v0.2.0 |
| 2 | [PTY-16](PTY-16-api-foundation.md) | API foundation: versioning, envelope, errors, pagination, audit, logging, HTTP hardening, CI | M | Sonnet / Opus | v0.2.0 |
| 3 | [PTY-4](PTY-4-stock-ledger.md) | Stock ledger | L | Sonnet / Opus | v0.2.0 |
| 4 | [PTY-5](PTY-5-ingredients-suppliers-api.md) | Ingredients and suppliers API | S | Sonnet / Opus | v0.2.0 |
| 5 | [PTY-6](PTY-6-menu-and-recipes-api.md) | Menu items and recipes API | S | Sonnet / Opus | v0.2.0 |
| 6 | [PTY-7](PTY-7-purchase-orders-state-machine.md) | Purchase orders and state machine | L | Sonnet / Opus | v0.3.0 |
| 7 | [PTY-8](PTY-8-receiving-deliveries.md) | Receiving deliveries | L | Sonnet / Opus | v0.3.0 |
| 8 | [PTY-9](PTY-9-pos-sales.md) | POS sales endpoint | L | Sonnet / Opus | v0.3.0 |
| 9 | [PTY-10](PTY-10-visibility-endpoints.md) | Visibility endpoints | M | Sonnet / Opus | v0.3.0 |

### 3b Build frontend (blocked until 2b is approved)
| # | Ticket | Title | Wt | Builder / Reviewer | Release |
|---|---|---|---|---|---|
| 1 | [PTY-11](PTY-11-design-system.md) | Design system and app shell | M | Sonnet / Opus + design reviewer | v0.4.0 |
| 2 | [PTY-12](PTY-12-ui-pages.md) | UI pages (may be split in 2b) | M | Sonnet / Opus + design reviewer | v0.4.0 |
| 3 | [PTY-22](PTY-22-demo-experience.md) | Demo experience: identity, banner, Try-it, reset, realistic seed | M | Sonnet / Opus + design reviewer | v0.4.0 |

### 4 Review and testing (continuous)
| Ticket | Title | Wt | Builder / Reviewer | Release |
|---|---|---|---|---|
| [PTY-17](PTY-17-browser-tests.md) | Browser tests (stretch, first to cut) | M | Sonnet / Opus | v1.0.0 |

### 5 Security
| Ticket | Title | Wt | Who | Release |
|---|---|---|---|---|
| [PTY-21](PTY-21-security-review.md) | Security review (never cut) | M | Mohamad: Opus locally, Fable on the web | v1.0.0 |

### 6 Release and submission
| # | Ticket | Title | Wt | Builder / Reviewer | Release |
|---|---|---|---|---|---|
| 1 | [PTY-13](PTY-13-postman-collection.md) | Postman collection | S | Sonnet / Opus | v1.0.0 |
| 2 | [PTY-15](PTY-15-docker.md) | Optional Docker setup | S | Sonnet / Opus | v1.0.0 |
| 3 | [PTY-14](PTY-14-readme-and-submission.md) | README and submission (never cut, always last) | S | Sonnet / Opus | v1.0.0 |

### 7 Interview preparation
Walkthrough script and live-extension drills, tracked in progress.md.

## Cut order if late
1. PTY-17 browser tests
2. PTY-15 Docker
3. The PTY-22 Try-it card becomes a static list instead of self-ticking
4. The PTY-13 scenario folder
5. The Activity page UI (the API stays)

Never cut: PTY-21, PTY-14.
