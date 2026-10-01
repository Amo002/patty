# PTY-15 Optional Docker setup

| Field | Value |
|---|---|
| Type | Task |
| Phase | 6 Release and submission |
| Status | In Review |
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

## Builder notes

### Verification status
**Not run.** Docker is not installed on the builder machine (`docker: command not found`), so `docker build`, `docker compose up` and the in-container test run have not been executed. The files were written carefully against the official `php:8.4-cli` image conventions. The last acceptance box (verified on Mohamad's machine with Docker Desktop) is left unticked for him.

### What was built
1. `Dockerfile`: `php:8.4-cli`, apt packages for Composer (git, unzip, libzip) and GD (libwebp, libjpeg, libpng, freetype), extensions `pdo_sqlite zip gd` (GD with WebP), composer copied from `composer:2`. `composer install` runs with dev dependencies, so the suite works in the container. Dependencies are installed before the app is copied so the layer is cached. Runs as non-root user `app` (uid 1000). `/data` is created and chowned in the image so the named volume inherits that ownership.
2. `docker/entrypoint.sh`: creates `.env` from `.env.example` and appends `DB_DATABASE=/data/database.sqlite`, generates the key if empty, touches the SQLite file on the volume, runs `migrate --force` every start (idempotent), seeds once guarded by the marker `/data/.seeded`, then `exec "$@"`.
3. `compose.yaml`: service `app`, port 8000, named volume `patty-data` on `/data`.
4. `.dockerignore`, `.gitattributes` rule `*.sh text eol=lf` (the existing `* text=auto eol=lf` already covered it; the explicit rule documents the intent), README section "Run with Docker (optional)".

### Why there is no `environment:` block in compose
`phpunit.xml` sets `APP_ENV=testing` and `DB_DATABASE=:memory:` with plain `<env>` (no `force`). PHPUnit does not override variables already in the real environment. If compose exported `DB_DATABASE=/data/...`, `docker compose run --rm app php artisan test` would run against the persisted demo database and `RefreshDatabase` would wipe it. Writing `DB_DATABASE` into `.env` instead is safe: Dotenv never overrides real env vars, and PHPUnit sets its values first. `APP_ENV=local` comes from `.env.example`, so the demo tools are on.

### Things to check when run
- `php artisan package:discover` in the build after a `--no-scripts` first install: expected to work, unverified.
- The seeders must not need anything missing in the image.
- `.env` lives in the container layer, not the volume, so `compose run --rm` creates a fresh one with a new key each time. Harmless: nothing encrypted is stored.
