# Alternative way to run Patty without PHP on the host (D-007). The primary
# path is still `composer setup && php artisan serve`.
FROM php:8.4-cli

# git/unzip/libzip: Composer needs them to fetch and unpack packages.
# libwebp/libjpeg/libpng/freetype: the seed images (later ticket) use GD with WebP.
# pdo_sqlite is the database driver; the official image already bundles sqlite3.
RUN apt-get update \
    && apt-get install -y --no-install-recommends git unzip libzip-dev libwebp-dev libjpeg-dev libpng-dev libfreetype6-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg --with-webp \
    && docker-php-ext-install -j"$(nproc)" pdo_sqlite zip gd \
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
RUN composer dump-autoload --optimize \
    && php artisan package:discover --ansi

# LF line endings are enforced by .gitattributes; chmod because Windows checkouts lose the bit.
USER root
RUN chmod +x docker/entrypoint.sh
USER app

EXPOSE 8000
ENTRYPOINT ["docker/entrypoint.sh"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
