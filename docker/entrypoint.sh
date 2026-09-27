#!/bin/sh
set -e

cd /var/www/html

# Shared by the app, scheduler and queue containers (see docker-compose.yml). Every step here is
# idempotent, so it is safe for several containers to run it against the same volumes.

# Named volumes (storage/app, storage/logs) can come up root-owned; Apache serves as www-data.
if [ "$(id -u)" = "0" ]; then
    mkdir -p storage/app/public storage/logs storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache
    chown -R www-data:www-data storage bootstrap/cache
fi

if [ ! -L public/storage ]; then
    php artisan storage:link --no-interaction || true
fi

# Only the web container sets RUN_MIGRATIONS=true; scheduler/queue containers never migrate.
if [ "$RUN_MIGRATIONS" = "true" ]; then
    php artisan migrate --force --no-interaction
fi

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# Long-running artisan workers (scheduler/queue) run as www-data so any log or cache files they
# create stay writable by Apache. Phase 8.7 (D8.7-020): setpriv replaces this process with the
# worker (no `su` shell in between), so Docker's SIGTERM reaches the worker, which finishes its
# current job and exits within stop_grace_period.
if [ "$(id -u)" = "0" ] && [ "$1" = "php" ]; then
    exec setpriv --reuid=www-data --regid=www-data --init-groups "$@"
fi

exec "$@"
