# Runbook: Production Environment Checklist

For whoever deploys Recruitment Edge to production. Phase 8.9 (P89-OPS-011, P7-READY, SEC-88-27). `.env.example` is a **development** template; production values come from the secret store. Every item must be confirmed before go-live and after any infrastructure change.

> **Status:** this repository cannot see production. None of the items below has been verified against the production environment (D8.9-026).

## Application

| Setting | Production value | Why |
|---|---|---|
| `APP_ENV` | `production` | fail-closed authorization paths, no debug tooling |
| `APP_DEBUG` | `false` | no stack traces or configuration to visitors |
| `APP_KEY` | a stored secret, never regenerated (`APP_PREVIOUS_KEYS` when rotating) | encrypted columns, queue payloads, signed links — see the backup runbook |
| `APP_URL` | the public HTTPS URL | signed links and portal emails |
| `APP_IMAGE_TAG` | the release (e.g. git commit) | rollback by tag |
| `SESSION_SECURE_COOKIE` | `true` (HTTPS) | staff and candidate session cookies over TLS only |
| `LOG_STACK` / `LOG_DAILY_DAYS` | `daily` / per the retention decision R-13 (`0` keeps all until then) | rotated logs; retention is a legal decision |
| `LOG_LEVEL` | `info` (the production default when unset) | no debug noise or detail in logs |
| `MAIL_MAILER` | a real transport (not `log` / `array`) | candidate and staff mail actually leaves |

## Data stores and queues

| Setting | Production value | Why |
|---|---|---|
| `DB_PASSWORD` | a stored secret (not the compose default `secret`) | the database account the application uses |
| MySQL root | random (compose: `MYSQL_RANDOM_ROOT_PASSWORD`) or managed by the platform | never an empty root password (P89-SEC-006) |
| `CACHE_STORE` | `database` (shared by every container) | locks, circuit breaker, heartbeats, dedupe |
| `QUEUE_CONNECTION` | `database` | the shipped worker topology |
| `DB_QUEUE_RETRY_AFTER` | `330` | above the longest worker timeout |
| `QUEUE_EXPECT_PROCESSES` | `true` | queue health reports silent workers and scheduler |
| `QUEUE_HEALTH_TOKEN` | a stored secret | external monitoring of `GET /health/queue` |
| `INTELLIGENCE_REFRESH_TIME_BUDGET` | `2700` (seconds; below the hour) | hourly intelligence refresh never overruns its cadence |

## Network and processes

- **TLS / proxy:** SEC-88-10 was deferred on "Apache serves production directly". If a reverse proxy, load balancer or CDN is put in front, configure trusted proxies first, or every IP rate limit becomes one shared bucket. Re-open SEC-88-10.
- **Processes:** exactly the compose topology — `migrate` (one-shot), `app`, the four workers (`queue`, `queue-priority`, `queue-automation`, `queue-background`), **one** scheduler. Never also a cron `schedule:run`.
- **External monitoring (D8.9-020):** poll `GET /up` and `GET /health/queue`. In-app alerts cannot report a dead scheduler or priority worker themselves.

## Secrets and keys

- AI provider keys (`GEMINI_API_KEY`, `OPENAI_API_KEY`): the Phase 7 freeze recorded a key **rotation** as a production blocker (P89-OPS-015). Confirm it was rotated and the old key revoked.
- Provider secrets (Twilio, WhatsApp, Zoom, calendars, job boards) only from the secret store.
- The development seeder's default admin password (TD-002) must never exist in production. Seed production users through identity provisioning.

## Before the first production deploy of Phase 8.9

- The production line (`main`) still lacks the Phase 8.6 delete-authorization hotfix `2fab3fd`, which also lacks SEC-86-I-01 (D8.9-027). Release this branch, or the corrected hotfix, under its own decision.
- A verified backup (`docs/runbooks/backup-restore.md`).
