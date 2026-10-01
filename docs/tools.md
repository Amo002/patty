# Tools and their limits

## AI tooling

| Tool | Used for | Limits |
|---|---|---|
| Claude Code (CLI) | Orchestrator, builders and reviewers | See permissions below |
| Claude Code `/code-review` skill | Second-opinion review of a ticket diff | Findings go into the ticket file |
| Claude Code `/simplify` skill | Cleanup pass on a finished ticket | Must not change behaviour. Tests must still pass. |
| Claude in Chrome | Manual QA screenshots of the UI, when useful | Local app only (`127.0.0.1:8000`) |
| GitHub CLI (`gh`) | Open PRs, read CI status, create releases | Never merges, never approves, never changes branch protection |

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
| Git history (force-push, rebase of pushed branches, squash) | The interviewers read the history |
| Commit trailers crediting AI | Authorship is Mohamad's. Disclosure lives in AI_LOG. |
