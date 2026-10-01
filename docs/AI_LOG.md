# AI log

How AI was used to build Patty: the main prompts, what came back, what was wrong, and what was changed. Written as the work happened, not reconstructed afterwards. Append-only.

Setup: Claude Code (CLI) as orchestrator, with builder and reviewer sub-agents per [agents.md](agents.md). Mohamad reviews and merges everything.

---

## 2026-10-01: Planning

**Prompt (summary):** read the brief and the private context notes. Plan a professional process: docs-first, tickets, builder and reviewer agents with the reviewer on a higher model, owner review on every ticket, GitHub with environments and releases, a polished animated UI with no emoji. Considered: spatie/permission, spatie/medialibrary, dev/stg/prod branches, Postman environments for dev/stg/prod.

**What came back:** a plan with a ticket breakdown, a docs set and a model ladder. It pushed back on four of the ideas because they conflict with the brief:
- no auth, roles or media (the brief says no auth);
- `main` + `develop` instead of dev/stg/prod (nothing is deployed);
- no-build UI instead of Vite (the reviewer must run it with PHP only);
- a single `local` Postman environment.

**Accepted:** all four pushbacks.

**Corrected by Mohamad:** the plan used `feature/*` branch names and `T-NNN` ticket numbers. He wanted Jira-style keys from a project name. The project is now **Patty**, key **PTY**, with branches named `PTY-N-slug`.

**Added by Mohamad mid-build:** an optional Docker setup (now PTY-15, D-007).

## 2026-10-01: PTY-1 scaffold. Laravel would not boot.

**What happened:** `composer create-project laravel/laravel` failed at `package:discover` with "bootstrap/cache directory must be present and writable", although the directory existed and the shell could write to it.

**What the AI got wrong, in order:**
1. It assumed the Claude Code sandbox was blocking PHP. It re-ran outside the sandbox: same error. Wrong guess.
2. It assumed Windows ACLs and granted the current user full control on the project folder with `icacls`. Same error. This was an unnecessary change, harmless but made before the cause was known. Lesson: diagnose before changing things.
3. It compared `is_writable()` up the path (`D:\` true, `D:\Projects` true, `D:\Projects\food` false) and found the **ReadOnly attribute** on the folders. Windows PHP honours that flag on directories, though Explorer and most tools ignore it. Clearing it on the project tree fixed the problem.

**Takeaway:** the third step should have been the first. Bisecting the path is cheaper than guessing.

## 2026-10-01: PTY-1 tests

**What the AI caught in the scaffold:** Laravel's example unit test asserts `true === true`. It passes whatever the code does, which makes it filler by our own rule. Deleted. The feature example became a single Pest smoke test.

## 2026-10-01: PTY-2 docs

**Caught:** the Laravel 13 scaffold ships a `CLAUDE.md`/`AGENTS.md` that instructs AI agents to install Laravel Boost before doing anything. Not followed, because it adds a dependency that is not in the brief (see [tools.md](tools.md)). Replaced with our own repo instructions.

## 2026-10-01: Answering the BRD questions

**What the AI proposed:** seven questions with options and a recommendation each (Q-001 to Q-007).

**Where Mohamad overrode it:** Q-002, over-delivery. The AI recommended strict rejection of any excess, for simplicity. Mohamad chose a 5% tolerance. (The AI first wrote a rationale here as if it were his. It was not, so it was corrected to a draft he must confirm in D-011.)

**Follow-up the AI raised:** the tolerance needs a rounding rule for small counts. 5% of 10 buns is 10.5. It proposed rounding down in integer math (`intdiv(ordered * 105, 100)`), so a 10-bun line allows exactly 10 and no float ever enters stock arithmetic. Accepted.

**Ambiguity caught:** a short answer ("go with A") could have been read as Q-004 option A (no manual close), the opposite of what was intended elsewhere. The AI asked instead of assuming. Q-004 was confirmed as B (short-close).

**Changes made:** requirements FR-4 AC4/AC5/AC9, data.md derived values and invariant 4, the PTY-8 worked example and tests (T9a to T9c), testing.md, decisions D-010 to D-016.

## 2026-10-01: Architecture round 2 (async, finance, envelope, audit, logging, protection)

**Prompt (summary):** Mohamad asked whether we need events, queues or a scheduler, and about the "financial side" ("isn't it an ERP, it needs all the pillars?"). He also asked for an API response trait with `success` / `data` / `errors` and correct status codes, an audit log, per-model log channels, a professional comment standard, layers of protection, user satisfaction, tools, a UI kit, and automated browser tests.

**What the AI got wrong:**
- **It misread a word.** It could not parse "fenteich sides" and offered "frontend" or "fetching". Mohamad meant "financial". It asked rather than guessed, so nothing was built on the wrong reading.
- **It nearly attributed reasoning to Mohamad again.** The earlier D-011 correction made this a standing rule: decisions record who decided, and rationale the owner did not give is labelled as the AI's draft.
- **A tooling slip.** A one-off edit script called `python`, which on this machine is the Windows Store stub and waits forever. The command was stopped, nothing had changed, and the edits were redone with the editor.

**Where the AI pushed back, and the outcome:**
- **Finance:** it argued against building costing, because the brief is quantities only, correctness beats size, and costing adds edge cases such as the cost of negative stock. Mohamad agreed: no money (D-018). The ERP thinking goes into `architecture.md` section 8 instead.
- **Events, queues, scheduler:** recommended none, with the trigger for each written down (D-017). Accepted.
- **Audit:** recommended its own explicit table. Mohamad chose spatie/laravel-activitylog. Adopted (D-021), with D-005 clarified so model events may observe but never change state.
- **Logging:** recommended per-domain channels over per-model. Accepted (D-022).
- **Protection:**
  - adopted: database triggers on the ledger, a rate limit, security headers (D-024);
  - deferred by Mohamad: Larastan, optimistic locking.
- **UI kit:** recommended none. Mohamad chose Hugeicons for icons. The AI checked the license (MIT) and the format (JS data, not SVG) before committing to it, and planned a one-off conversion so no npm is needed (D-025).
- **Browser tests:** added as an opt-in stretch suite that cannot break a reviewer's `php artisan test` (D-026).

**What the alignment check found:** walking the brief's own problem statement against the design showed a real gap. "Stop running out" was not served, because the manager could see stock but not what was already on order. Fixed with a derived Incoming column (D-020), with no new table. Reorder levels stay out on purpose, as a likely live-extension request.

**Changes made:**
- new `architecture.md` and `docs/README.md`;
- decisions D-017 to D-026;
- updated requirements (409 versus 422, envelope, audit, logging, Incoming);
- new tickets PTY-16 and PTY-17, plus updates to PTY-3 to PTY-14;
- tests T15 to T20 and QA-7;
- the comment standard in `CLAUDE.md`.

## 2026-10-01: Round 3, closing the gaps before build

**Prompt (summary):** Mohamad asked:
- how reviewers test without login without it feeling odd;
- to see every flow and how data moves;
- whether validation had been studied fully;
- whether the tickets were detailed enough.

He also asked for:
- pagination with skeletons and lazy loading;
- professional API versioning, with no database ids visible;
- a phase journal;
- a separate frontend design phase;
- a final security review with Fable.

**Honest answers the AI gave:**
- **Validation had not been studied fully.** Rules were scattered across tickets. Now `validation.md` has every field, with global rules G1 to G10 and a test for each row.
- **The backend tickets were reasonable, the UI tickets were thin.** There was no single API contract (now `api.md`, E1 to E30) and no flows (now `flows.md`, F1 to F20).

**Where Mohamad was ahead of the AI:**
- **Pagination.** The AI first proposed leaving catalogue lists unpaginated "because the restaurant is small". Mohamad wanted pagination everywhere, with skeletons and lazy loading (D-032).
- **Database ids.** The AI's earlier design used `PO-0001` derived from the database id and exposed integer ids in URLs. Mohamad asked for nothing internal to be shown. Now ULIDs are the public ids, and document numbers come from their own sequence (D-031).
- **Phases.** The AI's progress log was a flat list. Mohamad asked for phases, with frontend and backend design separated (D-033, D-034).

**What the AI caught itself in this round:**
- **Idempotency conflict.** A reused `pos_reference` with a different item or quantity was silently treated as a replay, which would hide a POS bug. It now returns 409 (D-029, Mohamad agreed).
- **A wrong progress formula in its own contract draft.** The PO `progress_percent` summed quantities across lines, which adds grams to pieces. It now averages each line's own completion.
- **Case-insensitive uniqueness** is now enforced by the database (`COLLATE NOCASE` with a unique index), not only by the validator.
- **Delivery dates** cannot precede the PO's `sent_at`.

**Process slips:**
- **The UI ticket question confused Mohamad.** He read "split the UI tickets" as "separate UIs". The AI re-explained (one app, the split is only about review size), and the decision moved to the design phase, where page sizes will be known.
- **A `sed` edit stripped the backslashes** from PHP namespaces in PTY-16. It was caught by reading the result back and fixed by hand.
- **The endpoint-coverage check reported false "unclaimed" endpoints**, because tickets cite ranges ("E2 to E5"). It was verified by hand. Lesson: a checking script needs checking too.

**Changes made:**
- new `api.md`, `validation.md`, `flows.md` (with "A day at Patty": beef 180, bun 21, cheese 120, checked by hand), `ui.md` and `security.md`;
- decisions D-027 to D-034;
- questions Q-008 to Q-013 (Q-012 open until phase 2b);
- tickets: every ticket now has phase, contract, flows, test data and a demo script; PTY-3 rewritten; new PTY-21 (security) and PTY-22 (demo experience);
- tests T21 to T25 and QA-8;
- the phase journal in progress.md.

## 2026-10-01: Round 4 (tolerances like SAP and Dynamics, real seed data, demo API, units)

**Prompt (summary):** Mohamad asked for:
- delivery tolerance "like SAP and Microsoft Dynamics, both": SAP's over- and under-delivery tolerances, and Dynamics' absolute limit ("max 2 kg");
- seeders with real data and real images;
- API endpoints to clear and seed data;
- storage in g and ml, with the UI handling kg and L.

**What the AI did:**
- Before planning, it explained how each system actually behaves, so "both" became a concrete design:
  - SAP's under-tolerance completes a line without manual closing;
  - an absolute cap combines with the percentage as the **stricter** of the two;
  - both systems **copy tolerances onto the PO line** so later changes don't alter sent orders.

  Mohamad chose all three (D-035).
- It chose **basis points** so percentages stay integers (and 2.5% remains possible), and rounding down in both directions so a 10-bun line accepts exactly 10 and completes only at 10.
- It re-checked "A day at Patty" against the new rules before changing the docs. All existing numbers stay valid.
- It noticed that **"clear data" would collide with our own append-only triggers**, because DELETE on `stock_movements` is refused by design. Clear is therefore `migrate:fresh`: a new database, not a quiet edit of history (D-037). This is now a walkthrough point.
- **Images:**
  - it checked that PHP GD with WebP support is available, so photos can be processed with no new tool;
  - it specified licence checks and credits (S17);
  - it insisted on fictional suppliers with `.example` emails, so no real business is implied.
- **Units:** storage and API stay integer base units; only the UI scales and parses kg and L, using string-based decimal parsing so `1.005 kg` becomes exactly `1005` with no float error (D-038).

**Owner rationale recorded:** D-011's "why" is now Mohamad's own, follow SAP and D365 practice, replacing the AI draft flagged earlier.

**A slip:** a security threat (S17) was appended after S16's position by a scripted edit and had to be reordered. Small, but it is why every scripted edit is read back.

## 2026-10-01: No Fable credits, model ladder re-planned

**Situation:** the plan assumed Fable 5.1 as orchestrator and top reviewer. Mohamad has no Fable credits, so Opus 5.5 becomes the top tier.

**AI decision, explained to the owner:** keep "the reviewer is one tier above the builder" by moving every builder to Sonnet 5.5 and every reviewer to Opus 5.5, rather than letting Opus review its own tier. The heavy tickets get extra checks instead: `/code-review high`, the orchestrator reading the diff, and the owner's hand-check of the worked examples. Recorded as D-039. All tickets, the board and agents.md were updated.
