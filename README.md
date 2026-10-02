# Patty

[![CI](https://github.com/Amo002/patty/actions/workflows/ci.yml/badge.svg?branch=develop)](https://github.com/Amo002/patty/actions/workflows/ci.yml)

Inventory and purchasing for a single-branch burger restaurant: ingredients, suppliers, recipes, purchase orders with partial deliveries, POS sales, and live stock.

> Work in progress. The full README (data model, decisions, AI usage, next steps) lands in PTY-14. Until then, see [`docs/`](docs/).

## Run it

Requires PHP 8.3+ and Composer.

```sh
git clone https://github.com/Amo002/patty.git
cd patty
composer setup        # install, .env, key, SQLite database, migrate and seed
php artisan serve     # http://127.0.0.1:8000
```

## Test it

```sh
php artisan test
```

## Project docs

| Doc | What |
|---|---|
| [docs/README.md](docs/README.md) | Start here: reading order for all docs |
| [docs/architecture.md](docs/architecture.md) | The big picture: layers, flows, protection, how it joins an ERP |
| [docs/brief.md](docs/brief.md) | The problem and what success looks like |
| [docs/requirements.md](docs/requirements.md) | Functional and non-functional requirements with acceptance criteria |
| [docs/scope.md](docs/scope.md) | What is in, and what is deliberately out |
| [docs/data.md](docs/data.md) | Data model and invariants |
| [docs/decisions.md](docs/decisions.md) | Decisions taken, alternatives rejected, and why |
| [docs/questions/](docs/questions/) | Where the brief was unclear |
| [docs/tickets/BOARD.md](docs/tickets/BOARD.md) | Ticket board |
| [docs/AI_LOG.md](docs/AI_LOG.md) | How AI was used, and where it got things wrong |

## Security

There is no login, because the brief asks for none. That risk is contained rather than ignored. The full threat model is in [docs/security.md](docs/security.md) (S1 to S17).

- `php artisan serve` binds to `127.0.0.1`, so only this machine can reach the app.
- **Cross-site requests are blocked.** Every API write must be sent as JSON, and CORS grants no other origin. A malicious page open in the same browser therefore cannot post to the local API, including the local-only demo reset.
- Stock history is append-only in code and in the database (triggers refuse UPDATE and DELETE on `stock_movements`).
- Public ids are ULIDs; integer ids never leave the server.
- Every query uses bindings, and the UI renders API data as text only.
- Errors never show a trace or SQL, only a message and a request id.
- The POS sales endpoint can require a shared key (`POS_API_KEY`) and is rate limited.
- The demo data endpoints exist only when `APP_ENV=local`.

Reviewed in PTY-21 on 2026-10-02 with Opus 5.5. The review fixed four findings (one in the Docker setup) and accepted two with written reasons; see [the ticket](docs/tickets/PTY-21-security-review.md). `composer audit` runs in CI on every pull request.