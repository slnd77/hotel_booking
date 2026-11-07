#!/bin/sh
# Prepares the app, then runs the container's command: apache2-foreground (web),
# "php artisan schedule:work" (scheduler) or "php artisan queue:work" (queue).
set -e

cd /var/www/html

if [ -z "$APP_KEY" ]; then
    echo "APP_KEY is not set. Make one with: docker compose run --rm app php artisan key:generate --show" >&2
    exit 1
fi

mkdir -p storage/app/public storage/framework/cache storage/framework/sessions storage/framework/views storage/logs bootstrap/cache
chown -R www-data:www-data storage bootstrap/cache

php artisan storage:link --force >/dev/null 2>&1 || true

# Only the web container migrates (RUN_MIGRATIONS=true), so the three containers don't race.
if [ "${RUN_MIGRATIONS:-false}" = "true" ]; then
    php artisan migrate --force
fi

php artisan optimize

exec "$@"
