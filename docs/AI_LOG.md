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
