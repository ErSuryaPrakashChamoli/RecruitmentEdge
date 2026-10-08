---
paths:
  - 'docker-compose.yml,docs/runbooks/queue-operations.md,app/Console/Commands/QueueDrainStatus.php,app/Services/QueueHealthService.php'
---

# Console Commands Services

## A release drains by stopping intake, scheduler and workers — never queue:restart
Phase 8.10 (P810-OP-03). queue:restart is not a drain: compose restarts the exited worker (restart: unless-stopped) on the old image. Order: php artisan down (app container; /up stays 200) → docker compose stop scheduler → queue:drain-status --wait (0 ready, 0 reserved, no scheduled task holding its overlap lock; delayed jobs may stay) → docker compose stop <every queue:work service> (SIGTERM via exec setpriv; stop_grace_period ≥ longest --timeout) → verify → backup → up -d. DeploymentTopologyTest pins this order against the compose worker list. Rollback of a release with migrations = restore the pre-release backup into an EMPTY database, never migrate:rollback. Raw SQL aliases must avoid MySQL reserved words (e.g. `delayed`): the suite runs on SQLite, which accepts them.
