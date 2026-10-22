#!/bin/sh
# Start the Atlas web app inside its container (compose.yaml, service `app`).
#
# Each step is safe to repeat, so a restart after a crash simply runs it
# again:
#   1. an app key, generated into .env the first time
#   2. migrations, which do nothing when the schema is already current
#   3. config, route and view caches, rebuilt from the current environment
#   4. the web server, which takes over this process
set -e

# compose.yaml mounts the project's .env at /app/.env, so a generated key
# lands in that file and survives the container. A key passed in as an
# environment variable, as a server might, is left alone.
if [ -z "$APP_KEY" ] && ! grep -q '^APP_KEY=base64:' .env 2>/dev/null; then
    echo "No APP_KEY in .env yet: generating one."
    php artisan key:generate --force
fi

php artisan migrate --force

# Cached from the environment Docker passes in. Never run the test suite in
# this container: the cached config points at the real database, and the
# image carries no tests and no PHPUnit for exactly that reason.
php artisan optimize

exec frankenphp run --config /etc/frankenphp/Caddyfile
