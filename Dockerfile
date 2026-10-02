# Alternative way to run Patty without PHP on the host (D-007). The primary
# path is still `composer setup && php artisan serve`.
FROM php:8.4-cli

# git/unzip/libzip: Composer needs them to fetch and unpack packages.
# pdo_sqlite is not installed here: the official image already has it compiled in, and
# rebuilding it would need the sqlite headers (the first version of this file failed on that).
# No GD: the app ships no seed photos (D-046), and the brand PNG script is a one-off dev tool.
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libzip-dev \
    && docker-php-ext-install -j"$(nproc)" zip \
    && rm -rf /var/lib/apt/lists/*

COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Run as a normal user so files written to the volume are not root-owned.
# /data is created here so the named volume inherits this ownership on first use.
RUN useradd --create-home --uid 1000 app \
    && mkdir -p /data /var/www/html \
    && chown app:app /data /var/www/html

WORKDIR /var/www/html
USER app

# Dependencies first, so this layer is cached until composer.lock changes.
# Dev dependencies stay installed because the suite runs inside the container.
COPY --chown=app:app composer.json composer.lock ./
RUN composer install --no-interaction --no-progress --no-scripts --prefer-dist

COPY --chown=app:app . .

# Permissions are set here, not inherited from the build context: a Windows checkout can carry
# read-only folder attributes (which arrive as non-writable modes) and loses the executable bit.
# Laravel must write to storage/ and bootstrap/cache; .dockerignore drops their contents, so the
# folders are recreated. LF line endings for the script are enforced by .gitattributes.
USER root
RUN mkdir -p storage/logs storage/framework/cache/data storage/framework/sessions storage/framework/views bootstrap/cache \
    && chown -R app:app storage bootstrap/cache \
    && chmod -R u+rwX storage bootstrap/cache \
    && chmod +x docker/entrypoint.sh
USER app

RUN composer dump-autoload --optimize \
    && php artisan package:discover --ansi

EXPOSE 8000
ENTRYPOINT ["docker/entrypoint.sh"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
