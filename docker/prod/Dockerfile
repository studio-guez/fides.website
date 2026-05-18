# syntax=docker/dockerfile:1.7

############################
# Stage 1: PHP vendor build
############################
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock* ./
COPY database/ database/
COPY artisan ./
RUN composer install \
      --no-dev \
      --prefer-dist \
      --no-progress \
      --no-interaction \
      --no-scripts \
      --optimize-autoloader

############################
# Stage 2: Frontend build
############################
FROM node:20-alpine AS frontend
WORKDIR /app
COPY package.json package-lock.json* ./
RUN if [ -f package-lock.json ]; then npm ci; else npm install; fi
COPY resources/ resources/
COPY vite.config.* ./
COPY public/ public/
RUN npm run build

############################
# Stage 3: Runtime (php-fpm)
############################
FROM php:8.3-fpm-bookworm AS runtime

ARG WWWUSER=1000
ARG WWWGROUP=1000

# System deps + PHP extensions Statamic needs
RUN apt-get update && apt-get install -y --no-install-recommends \
        ca-certificates curl git unzip \
        libicu-dev libonig-dev libxml2-dev libzip-dev \
        libjpeg-dev libpng-dev libwebp-dev libfreetype6-dev \
        sqlite3 libsqlite3-dev \
        supervisor \
    && docker-php-ext-configure gd --with-jpeg --with-webp --with-freetype \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath exif gd intl mbstring pcntl pdo_sqlite zip \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

# Match host UID/GID so bind-mounted SQLite + storage are writable from the container
RUN groupmod -o -g "${WWWGROUP}" www-data \
 && usermod  -o -u "${WWWUSER}"  -g www-data www-data

# PHP / FPM config
COPY docker/prod/php.ini       /usr/local/etc/php/conf.d/zz-app.ini
COPY docker/prod/php-fpm.conf  /usr/local/etc/php-fpm.d/zz-app.conf

WORKDIR /var/www/html

# Copy app source, vendor, and built assets
COPY --chown=www-data:www-data . /var/www/html
COPY --from=vendor   --chown=www-data:www-data /app/vendor          /var/www/html/vendor
COPY --from=frontend --chown=www-data:www-data /app/public/build    /var/www/html/public/build

# Drop dev-only files that should never live in the production image
RUN rm -rf /var/www/html/tests /var/www/html/node_modules \
           /var/www/html/.github /var/www/html/docker \
           /var/www/html/phpunit.xml /var/www/html/.env \
           /var/www/html/.env.example

# Ensure runtime dirs exist with correct permissions.
# In production these are bind-mounted from the host, but we want sane defaults
# in case the image is run standalone.
RUN mkdir -p storage/framework/cache/data storage/framework/sessions \
             storage/framework/views storage/logs bootstrap/cache \
 && chown -R www-data:www-data storage bootstrap/cache \
 && chmod -R ug+rwX storage bootstrap/cache

COPY --chmod=0755 docker/prod/entrypoint.sh /usr/local/bin/entrypoint.sh

# Publish vendor assets that the skeleton's composer post-install scripts would
# normally publish. We ran `composer install --no-scripts` in the vendor stage
# (because `statamic:install` needs the full app tree, which isn't present
# there), so we replay the required steps here against the assembled app.
#
# - `statamic:install` publishes `public/vendor/statamic` (CP assets, etc.).
# - `vendor:publish --tag=laravel-assets --force` publishes Laravel assets.
# Run as root, then hand ownership back to www-data so the user switch below
# still results in writable runtime dirs.
RUN php artisan statamic:install --no-interaction \
 && php artisan vendor:publish --tag=laravel-assets --ansi --force \
 && chown -R www-data:www-data /var/www/html/public /var/www/html/storage /var/www/html/bootstrap/cache

USER www-data

EXPOSE 9000
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["php-fpm", "-F"]
