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
