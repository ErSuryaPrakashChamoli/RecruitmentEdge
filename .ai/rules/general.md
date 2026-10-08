---
paths:
  - composer.json
  - docker-compose.yml
---

# General

## phpoffice/phpword and phpoffice/phpspreadsheet require ext-gd
This environment doesn't have PHP's gd extension by default. `composer require phpoffice/phpword phpoffice/phpspreadsheet` fails platform-req checks until it's installed (`sudo apt-get install -y php8.5-gd` on this box, or the matching php-version package). Composer can't be run with sudo from an agent's sandboxed Bash tool (needs an interactive TTY for the password) — this has to be done by the user in their own terminal, or the packages swapped for a lighter dependency-free alternative.

## Every queue must have a worker (three-worker topology, Phase 8.7)
Workers: `queue` = communications,notifications,default (--timeout=120); `queue-automation` = automation,default (--timeout=120); `queue-background` = intelligence,integrations,default (--timeout=300). DB_QUEUE_RETRY_AFTER (330, also in .env.example) must exceed the longest timeout (config queue.worker_max_timeout); every worker and the scheduler have stop_grace_period 330s and the entrypoint execs them via setpriv (never `su -c`, which swallows SIGTERM). A new job, queued listener, queued notification or mailable on a new queue name must be added to a worker here and in docs/runbooks/queue-operations.md — tests/Feature/Lifecycle/QueueTopologyTest.php reads docker-compose.yml, app/Jobs, app/Listeners, app/Notifications and app/Mail and fails otherwise. Slow provider work (AI, embeddings, calendar, job boards) goes on intelligence/integrations, never beside candidate messages.

## Compose healthcheck uses /health/ready; migrations via ops:migrate
The maintenance flag lives in the shared cache (APP_MAINTENANCE_STORE=database), so every container — including one recreated mid-release — stays down until `php artisan up`. Any route used as a container healthcheck must be exempt from maintenance mode (bootstrap/app.php preventRequestsDuringMaintenance except: health/live, health/ready), or workers (depends_on app healthy) never start during a release. The `migrate` service runs `ops:migrate` (MySQL GET_LOCK per database), never plain `migrate`; `--isolated` cannot work on a fresh database because its mutex needs the cache tables.
