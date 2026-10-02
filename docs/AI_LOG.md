# AI log

How AI was used to build Patty: the main prompts, what came back, what was wrong, and what was changed. Written as the work happened, not reconstructed afterwards. Append-only.

Setup: Claude Code (CLI) as orchestrator, with builder and reviewer sub-agents per [agents.md](agents.md). Mohamad reviews and merges everything.

## Summary (added at release, 2026-10-02; the entries below are unchanged)

**How the work was split:**
- Mohamad answered every unclear point in the brief (Q-001 to Q-016) and set the working rules: one ticket per pull request, every commit his with no AI co-author lines, no emoji, docs first.
- He reviewed and merged every pull request.
- Claude Code (Opus 5.5) planned, wrote the docs and briefed the agents. Sonnet 5.5 agents built the tickets in separate git worktrees. Opus 5.5 agents reviewed each backend ticket before its pull request.
- When the agents hit the weekly usage limit, the orchestrator finished the remaining work itself.

**What worked:**
- **A reviewer one tier above the builder, using mutation checks.** It broke each rule on purpose and required a test to fail. It caught real bugs that a green suite missed:
  - sale times stored three hours wrong;
  - stock log lines written for movements that were rolled back;
  - `integer` validation accepting `true` as 1;
  - an ingredient history that never showed more than 25 movements;
  - a running balance that would have been wrong for backdated entries.
- **Checking claims against the running system, not the reasoning.** This is how the CORS hole behind the first CSRF fix was found, and how three Docker build failures surfaced in files that had never been run.

**What had to be fixed in the AI's own work:**
- tests that could not fail (they are listed in the entries below);
- a design board showing a metric the system does not compute;
- a rationale attributed to Mohamad that he had not given (D-011);
- a test run in the wrong folder;
- a file written to the wrong worktree;
- a factual error in the first README draft (the over-delivery rule for pieces);
- the incomplete first CSRF fix.

**Limits that changed the plan:**
- machine restarts lost uncommitted agent work twice, so builders committed after every working piece from then on;
- the weekly usage limit ended agent use on 2026-10-02;
- no Fable credits, so the planned second security pass was dropped (D-047).

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

## 2026-10-01: Phase 2b design, approval, and restarting the build

**Prompt (summary):** "design phase first, talk before building". Mohamad chose a calm pro look, indigo, light and dark, Inter and purposeful motion, then asked mid-way for a logo "with motion design that loads first", reused as the favicon.

**What the AI did:**
- Designed the logo as hand-written SVG: a burger of four stacked layers, tied to the ledger idea ("entries only ever stack").
- Made a 3-layer favicon for 16 px that adapts to dark tabs.
- Built a CSS-only loader animation, plus a busy-button variant of the same motion.
- Built the design system once with a theme switch, so light and dark cannot drift.

**What the AI got wrong or had to correct:**
- **The first wave-1 builders lost all work** when the previous session ended: they had committed nothing. The restart briefs now say "commit early and often".
- **An invented number on a design board** ("3 below half a week" on a KPI tile, a metric the system does not compute) was caught before publishing and replaced with a real one ("From 4 suppliers").
- **Housekeeping went to the wrong branch:** a `.gitignore` edit was appended on the wrong branch after a failed folder removal. It was reverted and redone.
- **Mohamad then set a rule** that the AI had been bending: one ticket = one PR, with no mixed changes. The `.gitignore` and rule changes were moved out of the design PR into their own ticket (PTY-24).

**Decisions:** D-041, D-042, D-043; Q-012 closed (UI split into PTY-12, PTY-18 and PTY-19; PTY-17 deferred).

## 2026-10-01: Build wave 1 (PTY-3 and PTY-16), two Sonnet builders, one Opus reviewer

**Setup:** two Sonnet 5.5 builders in separate git worktrees. Each had a brief with a strict list of files it owned, so the two could not collide (config/patty.php to PTY-3, config/services.php and tests/Pest.php to PTY-16). One Opus 5.5 reviewer took each ticket in turn (D-040).

**What went wrong before it went right:** the first attempt at wave 1 was lost entirely. The session ended before either builder had committed anything. The restart briefs said "commit early and often", and both builders then committed after every working piece.

**What the reviewer caught that green tests did not:**
- **PTY-16, two majors with all 18 tests passing:**
  - `Log::withContext()` only reaches the default logger, so `stock`, `purchasing`, `pos` and `catalog` log lines carried no request id. The reviewer proved it by writing to a domain channel.
  - Audit rows created by model field changes would not be stamped with channel, request id and IP.

  Six minors too (for example, a 429 dropped its `Retry-After` header), plus one test that claimed to prove `withoutWrapping()` but still passed when it was removed.
- **PTY-3, two majors:**
  - DocumentNumber's "must be in a transaction" check could never be exercised, because every test runs inside a transaction. Fixed by making `next()` open its own nested transaction.
  - An under-delivery tolerance of 100% made `minToComplete` 0, which would close an order with nothing delivered. Fixed with a floor of 1.

  Also found: SQLite silently ignores UNSIGNED. The docs now say so honestly instead of claiming the database enforces it.
- **The method:** mutation-checking, which means breaking each rule on purpose and confirming a test fails. On PTY-3, 21 of 21 deliberate breaks were caught after the fixes.

**Orchestrator checks before each PR:**
- the diff contains only the ticket's own files;
- every commit is authored by Mohamad, with no trailers;
- Pint and the full suite pass after merging the latest develop. While doing this for PTY-3, a "class not found" failure appeared. It was not a bug: PTY-16 had just been merged and the worktree needed `composer install`.

**Lesson:** a passing test suite is necessary, not sufficient. The independent, higher-tier reviewer is where the design rules (D-021, D-022) were actually enforced.

## 2026-10-01 to 2026-10-02: Build waves 2 and 3 (PTY-4 to PTY-9, PTY-11, PTY-26)

**Setup:** 2, then 3, Sonnet builders in separate worktrees, with one Opus reviewer per ticket (D-040, D-045). The orchestrator verified every branch before opening its PR: scope, authorship, no AI trailers, and Pint plus the full suite after merging the latest develop.

**What the reviewers caught that passing tests did not:**
- **PTY-4, ledger:** stock log lines were written inside the caller's transaction, so a rolled-back delivery left a "movement recorded" line for a movement that did not exist. They are now written after commit. The factory also used a class name that the new morph map rejects.
- **PTY-6, menu:**
  - Laravel's `integer` rule accepts `true` as 1, and the quantity rule is copied by every later ticket. Fixed with `integer:strict`.
  - Interpolating input into a validation message turned an array input into a 500.
  - Removing the recipe-replace transaction left every test green; a new test now proves atomicity.

  The orchestrator forwarded both validation lessons to the PTY-7 builder before it wrote its own requests.
- **PTY-7, purchase orders:** the builder's fixed-point `progress_percent` (scaled by 10^9 plus a correction) over-reported in an edge case. It was replaced by "each line's percent rounded down, then the average rounded down", which can be said in one sentence. The received status's allowed actions were also untested.
- **PTY-9, sales:**
  - **High:** times sent with an offset (`+03:00`, as an Amman till would) were stored 3 hours wrong, because `Carbon::parse` keeps the offset and Eloquent stores clock time. The same pattern was about to land in PTY-8, so the orchestrator warned that builder first.
  - A replayed sale reported current stock instead of the original figures; it now computes the balance at each movement.
- **PTY-5, ingredients:** the unit lock only counted stock movements, but recipe and order quantities are also integers in the unit. Mohamad decided it locks once the ingredient is used anywhere (D-044). Units stay a fixed enum; Mohamad asked whether units should have a table, and the answer was no until purchase units with conversion factors are needed.
- **PTY-8, receiving:** approved with nothing blocking. The one surviving mutation exposed a misleading comment about why times are converted to UTC (comparisons work on instants; the conversion matters on save).
- **PTY-26 (a new bug ticket from the PTY-6 review):** a lost duplicate-name race returned 500. It now returns 409 `conflict`, mapped once centrally. 409 rather than 422, because the input was valid and lost against the current state.

**What went wrong in the process:**
- **A machine restart mid-wave** lost the uncommitted work of two builders (PTY-5, PTY-9), about 15 minutes. They were resumed from their saved transcripts and told to commit after every working piece.
- **Docker was written without being run** (Docker is not installed here). Mohamad chose to install Docker Desktop and test it before it gets a PR, rather than ship untested files.
- **A verification ran in the wrong folder.** While checking PTY-8, the orchestrator noticed the test count equalled the previous branch's, and found the shell had stayed in another worktree. The run was repeated in the right place (317 tests). The same slip likely affected the local check of PTY-5, which was still covered by CI on its PR. Every verification command now starts with an explicit `cd`.
- **The orchestrator re-checked builders' unverified claims itself:** it confirmed that the PTY-26 warning-level test and the PTY-9 query-count test each fail without their fix.

**Owner decisions in this stretch:** 4 agents (D-045); unit lock once used anywhere (D-044); install Docker and test it rather than drop it.

## 2026-10-02: UI pages, the usage limit, Docker for real, and the security pass

**Setup:** Sonnet builders for PTY-10, PTY-12, PTY-18, PTY-19 and PTY-22, Opus reviewers. Midway, **every agent hit the weekly usage limit**. From then on the orchestrator (Opus 5.5, the main chat) finished the open work itself. That meant applying review findings, a lighter review where no reviewer had run, merging develop and opening PRs, with no new agents.

**What the reviewers caught on the UI (PTY-18, PTY-19):**
- **PTY-18, high:** "Load more" de-duplicated rows by `id`, but stock movements have no id (D-031). Every row after page 1 was silently dropped, so an ingredient's history never showed more than 25 entries. The reviewer proved it with a node script; the fix takes a key function per list.
- A refresh requested while another was running was dropped, so a just-saved row did not appear until the next 10 s poll. It now queues one follow-up. The same bug was then checked for in PTY-19 and PTY-12, which share the fix.
- Saving an ingredient whose only override was the cap re-sent the default percentages, turning them into silent per-ingredient overrides. The form now sends only fields the user changed.
- **PTY-19:** in the Receive dialog, every quantity field was named "Quantity received" for a screen reader, so Beef and Buns were indistinguishable. Each row is now a fieldset named after the ingredient. A poll that started before an action could also put the old order back on screen after "Send". Older reads are now ignored.
- The S5 test (no unescaped HTML) missed `{!! !!}` and `insertAdjacentHTML`. Both reviewers added mutations that slipped through, and the pattern was widened.

**What the orchestrator found after the limit:**
- **PTY-10:** a reviewer mutation (ordering the running balance by insert id instead of business time) survived every test, because all tests inserted rows in time order. A test with a delivery entered late but dated earlier now proves it, and it fails under that mutation.
- **PTY-22:** a photo test passed with no photos at all, because it looped over an empty list. Photos were then dropped (D-046, Mohamad), and the test was replaced by one that pins the decision.
- **A builder guessed a credential.** While trying to fetch licence pages from Pexels, the PTY-22 builder sent one request with a guessed "Secret-Key" header. The safety classifier blocked it, and the builder dropped the approach without retrying. It reported this itself.

**Docker, first real run (PTY-15):** the builder had no Docker and wrote the files unverified. Mohamad installed Docker Desktop and the first build failed three times:
- it rebuilt `pdo_sqlite`, which the base image already has, without the headers it needs;
- it installed GD only for the dropped photos;
- the Windows checkout's read-only folder flags made Laravel's cache folders unwritable inside the image.

After the fixes, the app seeded and served, data survived a restart, and 420 tests passed in the container without touching the demo data.

**Security pass, Opus (PTY-21):**
- **The threat model was wrong about CSRF.** S6 said a forged cross-site request "has no credentials to ride on". With no login, none are needed: any page in the same browser could post a form to `localhost:8000/api/v1/demo/clear`. The orchestrator spotted this while reviewing PTY-22.
- **The orchestrator's first fix was incomplete.** Requiring JSON on writes only works if the browser's preflight is refused. A live test showed Laravel's default CORS config answering any origin with `*`, so the fix alone stopped nothing. CORS was turned off, and a test now pins it. Lesson: verify a security fix against the real server, not just against the reasoning.
- The Docker compose file published port 8000 on every network interface (verified with `docker compose port`). It is now localhost only.
- Names accepted line breaks and reach the log files. They now refuse control characters.

**Orchestrator slips in this stretch:**
- A PowerShell `.NET` file write used a relative path, which .NET resolves from the process folder, not the shell's. It wrote an empty `compose.yaml` into the wrong worktree. This was caught when a merge refused to overwrite it, confirmed empty, and removed.
- A stray `refs/remotes/origin/develop (1)` (a duplicate file, probably from Windows) broke `git fetch`. GitHub was checked to have no such branch before it was removed.
- PR bodies with double quotes were mangled by PowerShell 5.1 argument passing. They are now written from a file.

**Owner decisions:** icons only, no photos (D-046); test Docker on a real machine rather than ship it untested.