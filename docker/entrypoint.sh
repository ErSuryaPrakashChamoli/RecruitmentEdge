#!/bin/sh
set -e

cd /var/www/html

# Shared by the app, scheduler and queue containers (see docker-compose.yml). Every step here is
# idempotent, so it is safe for several containers to run it against the same volumes.

# Named volumes (storage/app, storage/logs) can come up root-owned; Apache serves as www-data.
# Phase 8.9 (P89-OPS-008): the directories are fixed on every start, but the recursive walk over
# every stored file runs once per volume (a marker file records it) — not on every start of every
# container, which grew with the document volume. After restoring files as root, set
# FIX_STORAGE_OWNERSHIP=true for one start (or delete the marker) to walk them again.
if [ "$(id -u)" = "0" ]; then
    mkdir -p storage/app/public storage/app/private storage/logs storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache
    chown www-data:www-data storage storage/app storage/app/public storage/app/private storage/logs storage/framework storage/framework/cache storage/framework/sessions storage/framework/views bootstrap/cache

    for volume in storage/app storage/logs; do
        if [ "$FIX_STORAGE_OWNERSHIP" = "true" ] || [ ! -f "$volume/.ownership-fixed" ]; then
            chown -R www-data:www-data "$volume"
            touch "$volume/.ownership-fixed" && chown www-data:www-data "$volume/.ownership-fixed"
        fi
    done

    chown -R www-data:www-data storage/framework bootstrap/cache
fi

if [ ! -L public/storage ]; then
    php artisan storage:link --no-interaction || true
fi

# Phase 8.9: with docker compose the one-shot `migrate` service runs the migrations before anything
# else starts, and every container has RUN_MIGRATIONS=false. RUN_MIGRATIONS=true remains for a
# single-container deployment without that ordering.
if [ "$RUN_MIGRATIONS" = "true" ]; then
    php artisan migrate --force --no-interaction
fi

php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# SaaS-7 (C13): a production container refuses to start on an unsafe configuration (missing or
# malformed APP_KEY, APP_DEBUG on, http APP_URL, insecure cookie, per-process cache, sync queue, log
# mailer, default database password, …) — see `php artisan ops:preflight`. PREFLIGHT_ENFORCE=false
# is the emergency override: record its use in the change log and remove it at once.
if [ "$APP_ENV" = "production" ] && [ "${PREFLIGHT_ENFORCE:-true}" != "false" ]; then
    if ! php artisan ops:preflight; then
        echo "Preflight failed: refusing to start this container (see the problems above)." >&2
        exit 1
    fi
fi

# Long-running artisan workers (scheduler/queue) run as www-data so any log or cache files they
# create stay writable by Apache. Phase 8.7 (D8.7-020): setpriv replaces this process with the
# worker (no `su` shell in between), so Docker's SIGTERM reaches the worker, which finishes its
# current job and exits within stop_grace_period.
if [ "$(id -u)" = "0" ] && [ "$1" = "php" ]; then
    exec setpriv --reuid=www-data --regid=www-data --init-groups "$@"
fi

exec "$@"
