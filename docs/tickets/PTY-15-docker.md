# PTY-15 Optional Docker setup

| Field | Value |
|---|---|
| Type | Task |
| Phase | 6 Release and submission |
| Status | To Do |
| Weight | S |
| Builder | Sonnet 5.5 |
| Reviewer | Opus 5.5 |
| Branch | `PTY-15-docker` |
| Release | v1.0.0 |
| Depends on | PTY-12 |

## Goal

Run Patty on a machine without PHP: `docker compose up`, open the browser. Requested by Mohamad. See D-007.

## Acceptance criteria

- [ ] `Dockerfile`: official `php:8.4-cli` image, with pdo_sqlite and composer. Installs dependencies at build time.
- [ ] `compose.yaml`: one service `app`, port 8000, SQLite file on a named volume
- [ ] An entrypoint prepares `.env` and the key, migrates and seeds on first run, then runs `php artisan serve --host=0.0.0.0`
- [ ] `docker compose run --rm app php artisan test` runs the suite
- [ ] `.dockerignore` excludes `vendor`, `.env`, `database/*.sqlite` and `.git`
- [ ] README documents it as the alternative path. Composer remains the primary path.
- [ ] Verified on Mohamad's machine (Docker Desktop)

## Out of scope

nginx, php-fpm, MySQL, Redis, multi-stage production images.
