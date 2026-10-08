# SaaS-7 — Scale, Reliability and Production Architecture

**For:** Engineering and Operations.

**Branch:** `feature/saas-7-scale-reliability`, from `e57ddaa` (SaaS-6).

**What SaaS-7 is:** hardening of the existing platform for many tenants — no new product feature, no new infrastructure dependency. Discovery: `docs/saas-7-discovery.md`. Decisions: `docs/saas-7-decision-register.md`. Security: `docs/saas-7-security-review.md`. Capacity: `docs/saas-7-capacity-plan.md`. Migration: `docs/saas-7-migration-plan.md`. Release status: `docs/saas-7-production-readiness.md`.

## 1. Summary by area

| Area | Done | Not done (and why) |
|---|---|---|
| C1 Secrets | `security:reencrypt` (retire an old `APP_KEY`); `ops:preflight` validates required secrets at start; redaction of webhook secrets, API tokens, bcrypt hashes, OAuth tokens, JWTs, LibreOffice stderr | A vault / KMS (D-S7-O1) |
| C2 Egress | SMTP timeout (30 s); network policy documented (§5) | Egress proxy / fixed IPs (D-S7-O2) |
| C3 Cache | Per-tenant permission map (S7-01); cache data on its own connection, cache locks on the business connection; preflight checks both | Redis (D-S7-O4) |
| C4 Queue | Paused-tenant work kept for resume (S7-02); tries/backoff/`failed()` on queued mail and notifications; tenant-task timeouts below their worker's; nothing on `default`; `DeliverWebhook` unique; `billing` queue; export retries bounded to 1 h | New workers (no evidence needed — D-S7-O5) |
| C5 Scheduler | Work probes; lazy tenant enumeration; tenant tasks really unique per tenant; budgeted passes with a resume cursor; per-tenant re-check before each run; one-pass health check; failed or stale scheduled tasks reported | — |
| C6 Noisy neighbour | Per-tenant budgets for outbound deliveries and inbound processing; circuit per failing endpoint; deferred, never dropped | Per-tenant workers (D-S7-O5) |
| C7 Database | Four tenant-led indexes proven by EXPLAIN on two large tenants (`docs/saas-7-capacity-plan.md` §3) | Read replica, instance sizing (D-S7-O13) |
| C8 Observability | `api.request`, `db.slow_query`, integration health in `/health/queue`, critical platform events mailed at once | A monitoring vendor (D-S7-O6) |
| C9 Health | `/health/live`, `/health/ready`; compose uses readiness | External probing (D-S7-O6) |
| C10 Audit | `audit:protect` triggers (append-only in the database); preflight and the integrity check report them | Running it in production — a privileged step (D-S7-O8) |
| C11 Files | — | Export file retention: no period invented (D-S7-O9; S7-13 open) |
| C12 Backup | `ops:verify-integrity` for after a restore; runbook updated | A backup system, RPO/RTO (D-S7-O7) |
| C13 Deployment | Preflight enforced by the entrypoint; `ops:migrate` (one run per database); trusted proxies and hosts from env; maintenance flag in the shared cache; workers drain with `--force` | Security headers on the panels (approved scope E-03; Info); CI and blue/green (D-S7-O12) |
| C14 Security | S7-01…S7-12 fixed; Phase D findings fixed (security review §3) | S7-13, S7-15, S7-16 (security review §6) |
| C15 Capacity | Re-measured on the 100k copy (`docs/saas-7-capacity-plan.md`) | A load test (D-S7-O13) |

## 2. How the main mechanisms work

### 2.1 Per-tenant permission map (`App\Services\Tenancy\TenantPermissionRegistrar`)

- Replaces spatie's `PermissionRegistrar` (`AppServiceProvider::register`, `extend()` — spatie binds its singleton during boot).
- The cached map holds the permissions with **the current tenant's roles only**. Key: `<permission.cache.key>.<version>.tenant.<id>.<generation>`; with no tenant, `….platform` and no roles (fail closed).
- A process that changes tenant (a worker between jobs, `TenantContext::run`) reloads the map.
- Invalidation rotates a token instead of deleting a key:
  - a role, or its permissions, changes → that role's tenant's generation (`App\Models\Role`);
  - the permissions table changes, or a change with no tenant → the global version.
- Each rotation happens **when the change is made and again when its transaction ends — commit or rollback**. A map rebuilt in between (by another worker from the committed state, or by the changing request from its uncommitted change) was stored under a generation nobody reads any more.
- spatie's own model listener (`RefreshesPermissionCache`) is disabled on `Role`: it invalidated the *current* tenant, not the role's.

### 2.2 Cache connections

| | Connection | Why |
|---|---|---|
| Cache data (`DB_CACHE_CONNECTION`) | `mysql_cache` (same database, its own connection) | Counters, permission generations and the maintenance flag commit on their own, never inside a business transaction, and never wait on one |
| Cache locks (`DB_CACHE_LOCK_CONNECTION`) | `mysql` (the business connection) | A lock commits — or rolls back — with the work it guards: unique-job locks, the applicant lock, the automation daily-message cap |

Where a lock guards a decision made inside a transaction, the caller takes it **before** opening the transaction (`CareerApplicationService::oneAtATime`, used by the API and inbound-webhook intake). It then waits while holding no row lock, and its transaction's snapshot starts after the previous holder committed. `ops:preflight` blocks both misconfigurations (`cache_connection`, `cache_locks`).

### 2.3 Scheduler

- `tenants:dispatch <task>`:
  - For the frequent tasks (`TenantWorkProbes::PROBED`), only tenants holding a row the task could act on are queued. A probe is a superset: one `DISTINCT tenant_id` query per table.
  - Tenants are enumerated lazily (500 at a time).
  - Each job is queued through a `PendingDispatch` inside its tenant, so its unique lock (per task and tenant, one hour) is taken: a run still queued or running is not queued again.
  - `--all-tenants` skips the probe.
- `tenants:run <task> --all --budget=<seconds>`:
  - No tenant is started once the budget is spent. The next run resumes after the last finished tenant (cursor `tenancy:run-cursor:<task>` in the shared cache); a complete pass clears it.
  - Each tenant is re-read **with a shared lock** just before its work runs, and skipped (`tenancy.task_skipped`) if it no longer allows background work.
- `queue:health-check`:
  - every tenant's problems in one grouped pass;
  - platform problems become platform events; critical ones (silent worker or scheduler, failed or stale scheduled task, retry-after risk) are mailed immediately with `sendNow`.

### 2.4 Integration fairness

- `WebhookDeliveryService::deliver()` asks `deferral()` first:
  - an endpoint with `circuit_failures` consecutive failures gets one trial per `circuit_cooloff_seconds`;
  - past the tenant's `tenant_deliveries_per_minute`, the delivery waits.
- A deferred delivery keeps its status, counts no attempt and gets a later `next_attempt_at`; the five-minute sweep sends it. Only a delivery that is **still due** is rewritten, so an attempt another worker just made keeps its retry schedule.
- `InboundWebhookProcessor::process()` does the same with `tenant_inbound_per_minute`: the event stays `received`.
- The budgets are `RateLimiter` counters on the shared cache, keyed per tenant (`TenantCache::key`).

### 2.5 Paused-tenant work

- A tenant job refused by the queue guard because its tenant is suspended or closed throws `TenantUnavailable`.
- Its `failed()` now returns without touching the work (the message stays Queued, the automation run Pending, …). The job waits in `failed_jobs`.
- When the tenant becomes usable again, SaaS-3's `PausedTenantWork` re-queues exactly those jobs. Rehearsed end to end on MySQL (database queue and a real worker): migration plan §4.

## 3. Configuration reference (new in SaaS-7)

| Variable | Default | Meaning |
|---|---|---|
| `DB_CACHE_CONNECTION` | unset (compose: `mysql_cache`) | Connection for cache data |
| `DB_CACHE_LOCK_CONNECTION` | unset (compose: `mysql`) | Connection for cache locks — keep it the business connection |
| `APP_MAINTENANCE_DRIVER` / `APP_MAINTENANCE_STORE` | `file` (compose: `cache` / `database`) | Maintenance flag shared by every container |
| `TRUSTED_PROXIES` | unset (no forwarded header believed) | `*` or a comma-separated list of proxy addresses |
| `APP_TRUSTED_HOSTS` | unset | Comma-separated hosts the application answers |
| `QUEUE_HEALTH_TOKEN` | empty | Bearer token for `/health/queue` and readiness details |
| `DB_SLOW_QUERY_MS` | 500 | `db.slow_query` threshold; 0 disables |
| `MAIL_TIMEOUT` | 30 | SMTP timeout in seconds |
| `WEBHOOK_TENANT_DELIVERIES_PER_MINUTE` | 120 | Outbound deliveries per tenant per minute |
| `WEBHOOK_TENANT_INBOUND_PER_MINUTE` | 120 | Inbound events processed per tenant per minute |
| `PREFLIGHT_ENFORCE` | `true` | `false` lets a production container start despite a preflight blocker (emergency only; record it) |

## 4. Operational commands (new in SaaS-7)

| Command | Use |
|---|---|
| `php artisan ops:preflight [--json] [--strict]` | Validate the configuration. Run by the entrypoint in production; non-zero exit on a blocker |
| `php artisan ops:migrate [--wait=900]` | Run the migrations, one run per database at a time (compose `migrate` service) |
| `php artisan ops:verify-integrity [--json]` | After a restore: foreign-key orphans, tenancy, pending migrations, audit protection |
| `php artisan audit:protect {install\|remove\|status}` | Database-level append-only audit trail (privileged user) |
| `php artisan security:reencrypt [--dry-run]` | Re-encrypt every encrypted column under the current `APP_KEY` before removing a previous key |
| `php artisan tenants:dispatch <task> [--all-tenants]` | Queue a tenant task (probe skipped with `--all-tenants`) |
| `php artisan tenants:run <task> --all [--budget=<s>]` | Run a tenant task in-process for every usable tenant, within a time budget |
| `GET /health/live`, `GET /health/ready` | Liveness; readiness (database, cache, storage) |

## 5. Network policy (minimum; D-S7-O2 decides the rest)

- Workers need outbound HTTPS to tenants' webhook endpoints, the AI providers, mail and the payment provider. Nothing else.
- Do not set `HTTPS_PROXY`/`HTTP_PROXY` for workers that deliver webhooks: a proxy resolves the host itself and bypasses the SSRF guard's DNS pinning (S7-16).
- LibreOffice (offer letters) needs no network, but it runs in the `queue-background` worker, which also delivers webhooks and so needs egress. Isolating it means a separate `documents` worker without egress (D-S7-O2, D-S7-O5; S7-16).
- The database is never exposed beyond the compose network.

## 6. Tests

| Suite | Files (SaaS-7) |
|---|---|
| SQLite feature/unit | `tests/Feature/Hardening/*` (isolation fixes, permission cache, operations, scheduler, queue, observability), `tests/Unit/Hardening/QueueFailurePathTest.php`, updates to tenancy architecture, reliability, API and deployment-topology tests |
| MySQL concurrency | `tests/Concurrency/ScaleReliabilityRaceTest.php` (13 races); the harness gives a contender fresh connections and a fresh shared cache |

Counts and results: `docs/saas-7-production-readiness.md` and the final report.

## 7. Known limitations

- No load test: throughput beyond the measured per-operation costs is a projection (`docs/saas-7-capacity-plan.md`).
- A delivery checked before an endpoint's disable commits may still go out: no lock is held over an HTTP call (RULE 10). The race test documents it.
- The audit triggers protect only once installed by a privileged user.
- Backup, restore cadence, RPO/RTO and retention remain owner decisions; nothing is pruned automatically.
