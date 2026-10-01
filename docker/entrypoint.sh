#!/bin/sh
# Prepares a fresh container, then hands over to the real command ("$@").
# Safe to run on every start: each step is skipped when already done.
set -e

DB_FILE=/data/database.sqlite
SEEDED_MARKER=/data/.seeded

# .env is created here, not passed as compose environment variables, on purpose:
# phpunit.xml sets APP_ENV and DB_DATABASE without force, so a real environment
# variable would beat it and the test run would wipe the persisted demo database.
if [ ! -f .env ]; then
    cp .env.example .env
    echo "DB_DATABASE=$DB_FILE" >> .env
fi

if ! grep -q '^APP_KEY=.\+' .env; then
    php artisan key:generate --force
fi

# The file lives on the /data volume so it survives container recreation.
[ -f "$DB_FILE" ] || touch "$DB_FILE"

# Migrations are idempotent, so they run every start (picks up new releases).
php artisan migrate --force

# Seed once only. Re-seeding would duplicate or overwrite what the user entered.
if [ ! -f "$SEEDED_MARKER" ]; then
    php artisan db:seed --force
    touch "$SEEDED_MARKER"
fi

exec "$@"
