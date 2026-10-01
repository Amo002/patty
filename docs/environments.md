# Environments

The brief says no deployment is needed, so there are no servers. We do not pretend otherwise.

| Environment | Where | Branch | Database | Purpose |
|---|---|---|---|---|
| local | Developer machine, `php artisan serve` or `docker compose up` | any `PTY-N-*` | `database/database.sqlite` | Building and manual QA |
| test | Pest, locally and in GitHub Actions | any | in-memory SQLite, fresh per test | Automated proof |
| dev | Integration branch | `develop` | none (not deployed) | Every merged ticket lands here. Must always be green. |
| prod | Release branch | `main` | none (not deployed) | Only tagged releases. What the reviewers clone. |

## Why nothing runs against "real" data

- Tests use a fresh in-memory database per test (`RefreshDatabase`). No test depends on another test or on seed data it did not create.
- The local database is disposable: `php artisan migrate:fresh --seed` rebuilds it with the Classic Burger demo data at any time.
- Manual QA is done on a freshly seeded local database, so results are reproducible.

## If this were deployed (next steps, not built)

`develop` would auto-deploy to a staging server and `main` tags to production. SQLite would become MySQL or Postgres with the same migrations. Environment values would come from the host's secrets, not from `.env` files.
