# syntax=docker/dockerfile:1

# Atlas NetCare: one image for the web app, the queue worker and the scheduler.
# See docs/decisions/ADR-0012-docker-compose-stack.md.
#
# Four stages, so the final image carries no Composer, no Node and no
# front-end sources:
#
#   base     PHP 8.3 served by FrankenPHP, plus the extensions Atlas needs
#   vendor   Composer dependencies from the lock file, without dev packages
#   assets   the Vite build of the CSS and JavaScript
#   app      the code, the vendor folder and the built assets together

# ------------------------------------------------------------------ base
FROM dunglas/frankenphp:1.13.2-php8.3-alpine AS base

# pdo_pgsql for PostgreSQL (ADR-0011). pcntl so `queue:work` and
# `schedule:work` stop cleanly when Docker asks them to. OPcache ships with
# the image already.
RUN install-php-extensions pdo_pgsql pcntl

WORKDIR /app

# ------------------------------------------------------------------ vendor
FROM base AS vendor

# zip only for Composer to unpack downloads; it doesn't reach the final image.
RUN install-php-extensions zip
COPY --from=composer:2.10.3 /usr/bin/composer /usr/bin/composer

# The lock file first, so the dependency layer is reused until it changes.
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-scripts --no-autoloader --prefer-dist --no-interaction --no-progress

COPY . .
RUN composer dump-autoload --optimize --no-dev --no-scripts

# ------------------------------------------------------------------ assets
FROM node:24.22.1-alpine AS assets
WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci --no-audit --no-fund

# Tailwind finds classes by scanning these files, so all of them must be
# here for the build. tailwind.config.js lists the same paths.
COPY vite.config.js tailwind.config.js postcss.config.js ./
COPY resources ./resources
COPY app ./app
COPY --from=vendor /app/vendor/laravel/framework/src/Illuminate/Pagination/resources/views ./vendor/laravel/framework/src/Illuminate/Pagination/resources/views

RUN npm run build

# ------------------------------------------------------------------ app
FROM base AS app

COPY . .
COPY --from=vendor /app/vendor ./vendor
COPY --from=assets /app/public/build ./public/build

# Composer's post-install scripts were skipped in the vendor stage; this is
# the one Laravel needs. It writes bootstrap/cache/packages.php.
RUN php artisan package:discover --ansi \
    && chmod +x docker/app/start.sh

# Plain HTTP on port 80 inside the container. Caddy would otherwise make its
# own certificate for "localhost", which browsers don't trust.
ENV SERVER_NAME=:80

EXPOSE 80

# The web app. The worker and the scheduler run the same image with their own
# command, set in compose.yaml.
CMD ["docker/app/start.sh"]
