# Runbook: Production Environment Checklist

For whoever deploys Recruitment Edge to production. Phase 8.9 (P89-OPS-011, P7-READY, SEC-88-27); SaaS-7 additions marked. **SaaS-7: `php artisan ops:preflight` checks most of this list and the entrypoint refuses to start a production container on a blocker** — run it (`--json` for monitoring) after any change. `.env.example` is a **development** template; production values come from the secret store. Every item must be confirmed before go-live and after any infrastructure change.

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
| `DB_CACHE_CONNECTION` (SaaS-7) | `mysql_cache` | cache data commits on its own connection, never inside a business transaction |
| `DB_CACHE_LOCK_CONNECTION` (SaaS-7) | `mysql` (the business connection) | a lock commits or rolls back with the work it guards (preflight blocks anything else) |
| `APP_MAINTENANCE_DRIVER` / `APP_MAINTENANCE_STORE` (SaaS-7) | `cache` / `database` | `php artisan down` holds for every container, including one recreated during a release |
| `QUEUE_CONNECTION` | `database` | the shipped worker topology |
| `DB_QUEUE_RETRY_AFTER` | `330` | above the longest worker timeout |
| `QUEUE_EXPECT_PROCESSES` | `true` | queue health reports silent workers and scheduler |
| `QUEUE_HEALTH_TOKEN` | a stored secret | external monitoring of `GET /health/queue`, and the details of `GET /health/ready` |
| `PLATFORM_NOTIFY_EMAIL` (SaaS-7) | the operations mailbox | critical platform events (silent worker or scheduler, failing scheduled task) are mailed there at once; unset, they reach nobody |
| `DB_SLOW_QUERY_MS` (SaaS-7) | `500` | queries slower than this are logged as `db.slow_query` (SQL only) |
| `MAIL_TIMEOUT` (SaaS-7) | `30` | an unreachable SMTP server cannot hold a worker |
| `WEBHOOK_TENANT_DELIVERIES_PER_MINUTE` / `WEBHOOK_TENANT_INBOUND_PER_MINUTE` (SaaS-7) | `120` / `120` (D-S7-O11) | one tenant's integrations cannot occupy the shared worker; excess waits, never dropped |
| `PREFLIGHT_ENFORCE` (SaaS-7) | unset (`true`) | `false` only as a recorded emergency override |
| `INTELLIGENCE_REFRESH_TIME_BUDGET` | `2700` (seconds; below the hour) | hourly intelligence refresh never overruns its cadence |

## Network and processes

- **TLS / proxy:** if a reverse proxy, load balancer or CDN is in front, set `TRUSTED_PROXIES` (its addresses, or `*` when only the proxy can reach the app), or every IP rate limit becomes one shared bucket and HTTPS is not detected. SaaS-7 closes SEC-88-10 in code; unset, no forwarded header is believed. Set `APP_TRUSTED_HOSTS` to the served host names. Preflight warns while either is unset.
- **Egress (SaaS-7, D-S7-O2):** workers need outbound HTTPS to tenants' webhook endpoints, the AI providers, mail and the payment provider. Never set `HTTPS_PROXY` for them — a proxy resolves hosts itself and bypasses the webhook SSRF guard's DNS pinning (S7-16).
- **Processes:** exactly the compose topology — `migrate` (one-shot), `app`, the four workers (`queue`, `queue-priority`, `queue-automation`, `queue-background`), **one** scheduler. Never also a cron `schedule:run`.
- **External monitoring (D8.9-020, D-S7-O6):** poll `GET /health/live`, `GET /health/ready` and `GET /health/queue`. In-app alerts cannot report a dead scheduler or priority worker themselves.

## Secrets and keys

- **`APP_KEY` rotation (SaaS-7):**
  1. Generate a new key. Put it in `APP_KEY` and the old one in `APP_PREVIOUS_KEYS`; deploy. Everything still decrypts, through the previous key.
  2. `php artisan security:reencrypt --dry-run`: every encrypted column's values, and how many could not be read (must be 0).
  3. `php artisan security:reencrypt`: every value is rewritten under the new key. It fails, and nothing should be removed, if any value is unreadable.
  4. Wait for the queues to drain of jobs queued before step 1 (their encrypted payloads use the old key), and for signed links issued before step 1 to expire.
  5. Remove `APP_PREVIOUS_KEYS` and deploy. Preflight warns while it is set.
- **Audit trail (SaaS-7, D-S7-O8):** `php artisan audit:protect install`, run once by a **privileged** database user (MySQL with binary logging refuses `CREATE TRIGGER` to the application user). It makes `audit_logs` append-only in the database. `audit:protect status` reports it; preflight warns while it is missing.

- AI provider keys (`GEMINI_API_KEY`, `OPENAI_API_KEY`): the Phase 7 freeze recorded a key **rotation** as a production blocker (P89-OPS-015). Confirm it was rotated and the old key revoked.
- Provider secrets (Twilio, WhatsApp, Zoom, calendars, job boards) only from the secret store.
- The development seeder's default admin password (TD-002) must never exist in production. Seed production users through identity provisioning.

## Before the first production deploy of Phase 8.9

- The production line (`main`) still lacks the Phase 8.6 delete-authorization hotfix `2fab3fd`, which also lacks SEC-86-I-01 (D8.9-027). Release this branch, or the corrected hotfix, under its own decision.
- A verified backup (`docs/runbooks/backup-restore.md`).
