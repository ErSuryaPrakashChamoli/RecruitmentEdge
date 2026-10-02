---
paths:
  - 'docker-compose.yml,docker/**,app/Console/Commands/HeartbeatCheck.php,app/Services/WorkerHeartbeat.php,config/logging.php'
---

# Commands Services

## Start order, health and logs
Phase 8.9 (P89-OPS-002/005/006/008).

**Start order.** In compose the order is:
1. the one-shot `migrate` service;
2. `app` — healthy on /up, which runs `select 1`;
3. the workers — healthy via `ops:heartbeat worker --queues=<their list>`;
4. `scheduler` — healthy via `ops:heartbeat scheduler`.

Every container has RUN_MIGRATIONS=false. Never migrate from a serving container.

**Heartbeat.** Each worker process beats on Looping, through the WorkerHeartbeat singleton, at most once a minute.

**Deploys.** Images are tagged with APP_IMAGE_TAG. Never run optimize:clear or cache:clear in a deploy: it wipes lockouts, OTPs and dedupe keys. Use config:clear, route:clear and view:clear.

**Logs.** Logs rotate daily and default to info level in production. Apache logs carry no query string or Referer. Never log secrets, tokens or PII — ids only.
