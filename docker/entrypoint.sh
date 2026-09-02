#!/bin/sh
set -e

cd /var/www/html

if [ ! -L public/storage ]; then
    php artisan storage:link --no-interaction || true
fi

if [ "$RUN_MIGRATIONS" = "true" ]; then
    php artisan migrate --force --no-interaction
fi

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

exec "$@"
