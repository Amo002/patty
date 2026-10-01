# Progress: the project journal

The first file to open every session. It tracks the project phase by phase: what each phase was for, how we went through it, what was decided, and what we learned. Every PR updates this file.

## Now

| | |
|---|---|
| Phase | **2a Backend design** |
| Active ticket | PTY-2 (docs), PR #2, awaiting Mohamad |
| Waiting on Mohamad | Read and merge PR #2. Confirm or rewrite the D-011 rationale in his own words. |
| Next | Release v0.1.0, then phase 2b (frontend design) alongside phase 3a (PTY-3, PTY-16, ...) |
| Deadline | Submit Sunday 4 October, target 11:00 Amman (hard limit 12:00) |
| Cut order if late | PTY-17 browser tests, PTY-15 Docker, Try-it self-ticking, Postman scenario, Activity page UI |

## Phases at a glance

| # | Phase | Goal | Exit criteria | Status |
|---|---|---|---|---|
| 0 | Discovery and planning | Understand the brief; choose stack and process | Plan approved by Mohamad | Done (2026-10-01) |
| 1 | Specification | What to build and not build; every unclear point answered | Requirements, scope, questions closed | Done (2026-10-01) |
| 2a | Backend design | How it works: architecture, data, API contract, validation, flows, threat model | Docs merged (PR #2) | **In progress** |
| 2b | Frontend design | How it looks and feels: design system and every screen in every state | Mohamad's written approval below | Not started |
| 3a | Build: backend | PTY-3, PTY-16, PTY-4 to PTY-10 | All merged, CI green, v0.3.0 tagged | Not started |
| 3b | Build: frontend | PTY-11, UI tickets (split decided in 2b), PTY-22 | All merged, QA checklists pass, v0.4.0 tagged | Blocked by 2b |
| 4 | Review and testing | Per-ticket code and design review, plus a final full pass | Every ticket's review section complete; QA-1 to QA-8 pass | Continuous |
| 5 | Security | PTY-21: `/security-review` on Fable (Mohamad's credits), triage, fixes | Every finding fixed or accepted with a reason | Not started |
| 6 | Release and submission | Postman, Docker, README, clean-clone check, v1.0.0, reply email | Submitted | Not started |
| 7 | Interview preparation | Walkthrough script; drills for likely live extensions | Rehearsed once end to end | Not started |

---

## Phase 0: Discovery and planning (done, 2026-10-01)

**Goal:** understand the brief and decide how to work.

**How it went:**
- Read the brief and the private context notes.
- Mohamad proposed a large process: docs-first, tickets, builder and reviewer agents, environments, roles, media and a polished UI.
- Four ideas were pushed back on because they conflict with the brief: auth/roles/media, dev/stg/prod branches, a Vite build, Postman staging environments. Mohamad accepted all four.
- Project named **Patty**, Jira key **PTY**, with branches named by ticket.
- PTY-1: Laravel 13 scaffold, Pest, no-build UI, CI, branch protection. PR #1 merged.

**Decisions:** D-001, D-002, D-003, D-009.

**Lessons:** the Windows ReadOnly folder attribute broke PHP's `is_writable()`. Diagnose by bisecting the path before changing anything (see AI_LOG).

## Phase 1: Specification (done, 2026-10-01)

**Goal:** know exactly what to build, and what not to.

**How it went:**
- Wrote brief, users, requirements, scope, stack, data, workflow, environments, agents, tools, design and testing docs.
- Opened Q-001 to Q-007. Mohamad answered all of them, overriding the recommendation on Q-002 with a 5% over-delivery tolerance.

**Decisions:** D-004 to D-016.

**Lessons:** a short answer ("go with A") can be ambiguous across questions, so ask rather than map it. Never write the owner's reasons for him.

## Phase 2a: Backend design (in progress, started 2026-10-01)

**Goal:** settle how every part works before code. Questions settled now are cheaper than discoveries mid-build.

**How it is going:**
- **Round 2:**
  - architecture overview;
  - alignment check against the brief (found the run-out gap, fixed with Incoming);
  - no events, queues, scheduler or finance;
  - API envelope with 409/422;
  - activitylog audit;
  - per-domain logs;
  - comment standard;
  - DB triggers, rate limit, headers;
  - Hugeicons;
  - opt-in browser tests.

  D-017 to D-026.
- **Round 3:**
  - a no-login demo experience;
  - an optional POS key;
  - the idempotency conflict rule;
  - a full validation table;
  - every flow end to end;
  - the API contract with versioning;
  - no database ids exposed (ULIDs plus document numbers);
  - pagination with skeletons and lazy loading;
  - a threat model;
  - deeper tickets;
  - this phase journal.

  D-027 to D-034.
- Round 3 output:
  - api.md (E1 to E30);
  - validation.md (G1 to G10 plus every field);
  - flows.md (F1 to F20 plus the worked day);
  - ui.md;
  - security.md (S1 to S16);
  - every ticket deepened;
  - PTY-21 and PTY-22 added.

**Lessons:** state the honest gaps when asked (validation was not complete). Ask before mapping a confusing answer (the UI split). Verify scripted edits by reading them back.

**Exit:** PR #2 merged.

## Phase 2b: Frontend design (not started)

**Goal:** a design Mohamad approves before any UI code exists.

**Plan:**
1. Claude's Artifact Design type produces a design system plus all seven screens, each in its loaded, loading (skeleton), empty and error states. Inputs: [design.md](design.md) and [ui.md](ui.md).
2. Mohamad reviews and iterates on the artifacts.
3. On approval: finalise design.md and ui.md, decide the UI ticket split (Q-012) and the PTY-17 scope, and write the UI tickets in full.

**Approval:** _(Mohamad writes approval and date here)_

## Phase 3a: Build backend (not started)
## Phase 3b: Build frontend (not started)
## Phase 4: Review and testing (continuous)
## Phase 5: Security (not started)
## Phase 6: Release and submission (not started)
## Phase 7: Interview preparation (not started)
