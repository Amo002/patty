# Tools and their limits

## AI tooling

| Tool | Used for | When | Limits |
|---|---|---|---|
| Claude Code (CLI) | Orchestrator, builders and reviewers | Always | See permissions below |
| `/code-review` skill | Reviewer agents review the ticket diff. Level `high` for L tickets, `medium` for S and M. | Every ticket | Findings go into the ticket file |
| `/security-review` skill | Pass over middleware, input handling and the exposed API | PTY-16, and before v1.0.0 | Findings go into the ticket file |
| `/simplify` skill | Cleanup pass | After each L ticket | Must not change behaviour. Tests must still pass. |
| `run` skill | Start the app and confirm a change works for real, not only in tests | UI tickets | Local only |
| Claude in Chrome | Screenshots at 1440 px and 820 px, console errors, GIF recordings of the main flows for the README | PTY-11, PTY-12, PTY-14 | Local app only (`127.0.0.1:8000`). No other sites. |
| `dataviz` skill | Chart guidance if the dashboard shows a stock chart | PTY-12 | |
| `fewer-permission-prompts` skill | Allowlist safe read-only commands so agents stop pausing the owner | Once | Read-only commands only |
| GitHub CLI (`gh`) | Open PRs, read CI status, create releases after the owner merges | Always | Never merges, never approves, never changes branch protection |

Available in this environment but **not used**: Canva, Shopify, Gmail, Google Calendar and Drive, Cloudflare, DaVinci Resolve, and the Docs connectors. None of them serve this project.

No Laravel Boost, MCP servers or AI packages are added to the project's dependencies.

## What agents may read

Everything in the repo, plus the private brief and context outside the repo.

## What agents may never write

| Never | Why |
|---|---|
| `.env`, any credentials, `database/*.sqlite` committed | Secrets and local state stay local |
| `main` or `develop` directly | Protected. Changes arrive by PR only. |
| Merges, approvals, releases on GitHub | The owner's job |
| `composer.json` dependencies not listed in [stack.md](stack.md) | Must be raised and recorded as a decision first |
| Existing entries in [AI_LOG.md](AI_LOG.md) or [decisions.md](decisions.md) | Append only. History is not rewritten. A wrong entry gets a correcting entry. |
| Existing migrations after they are merged | Add a new migration instead |
| `stock_movements` rows, via update or delete, from any code path | Append-only by design |
| Git history (force-push, rebase of pushed branches, squash) | The history is part of the record of how the work was done |
| Commit trailers crediting AI | Authorship is Mohamad's. Disclosure lives in AI_LOG. |
