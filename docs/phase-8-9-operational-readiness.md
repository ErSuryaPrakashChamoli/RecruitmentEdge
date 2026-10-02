# Phase 8.9 Operational Readiness (Discovery)

**Status:** discovery (§1–§7), then **implementation dispositions (§8)**. The discovery text is kept as the record of what was found.

Discovery itself changed nothing.
- No configuration, worker, scheduler, deployment or backup was touched.
- Production backups were not accessed.
- No RTO, RPO, SLO or retention period is proposed. Those belong to their owners (D8.9-004 … 010, 021, 025).

**Baseline:** `dce11d9`. Production was not reachable, so every statement about production is **unverified** unless a document in the repository states it.

## 1. Observability

| Capability | Today | Evidence | Gap |
|---|---|---|---|
| Request correlation | `X-Request-Id` kept or generated; stored in Context; echoed; written to `audit_logs.request_id` | `AssignRequestId.php:19-25`; `bootstrap/app.php:31` | the client can set it (SEC-88-22, deferred C) |
| Job / command correlation | `cmd:<uuid>` / `job:<uuid>` when there is no caller id; `origin_request_id` on executions and messages | `AppServiceProvider.php:272-291` | a job that inherits a request id never records its own uuid. "Which job did this?" can only be answered for failures. |
| Actor | `user_id`, polymorphic actor, `actor_kind`, `on_behalf_of_user_id` | `AuditLog.php:64-100` | — |
| Logs | `stack` → `single` (one file, **never rotated**), plain text, **debug** level; redaction tap on most channels | `config/logging.php:22,58,62-68`; `RedactSensitiveData.php` | no rotation; no JSON; `monthly` and `emergency` have no tap (P89-SEC-008) |
| Access log | Apache `combined` | `docker/apache/000-default.conf:10` | records signed-URL signatures (P89-SEC-008) |
| Health endpoints | `/up` (framework default, **no database check**); `GET /health/queue` (token or `settings.manage`, 60/min) | `bootstrap/app.php:19`; `QueueHealthController.php` | `/up` reports healthy while the database is down |
| Queue health | `queue:health-check` every 5 min: oldest job > 15 min, stuck > 30 min, heartbeat > 15 min, failures in the last hour, paused provider, `retry_after` sanity | `QueueHealthService.php:29-33,115-151` | it runs **inside the scheduler** it is meant to watch |
| Metrics / APM / error tracking | **none** (no Sentry, Bugsnag, Telescope, Pulse, Horizon, Nightwatch, Flare, Prometheus) | `composer.json` | D8.9-019 |
| Provider health | communication circuit breaker; integration "test" button; `ai_usage_logs` status and latency | `ProviderCircuitBreaker.php`; `IntegrationRegistry.php:69-96` | no continuous probe; calendar / job-board failures are log-only |

**Operator questions:**

| Question | Answerable? |
|---|---|
| What failed, when, why | yes: `failed_jobs`, status / error columns, log |
| Which request | yes: audit `request_id`; `origin_request_id` |
| Which actor | yes |
| Which job / queue for a **successful** action | **no** |
| Did a generic job eventually succeed | **no** (only messages and automation keep status) |
| Retry history | partly: `attempts`, `retry_count`; no per-attempt table |

## 2. Failure recovery

| Scenario | Behaviour today | Runbook |
|---|---|---|
| Database outage | **Total outage.** Sessions, cache, queue and failed jobs all live in MySQL. Workers exit and restart (`unless-stopped`). **The `app` service has no restart policy** (`docker-compose.yml:42-48`). The `failover` cache store exists but is unused. | none |
| Cache flush / `optimize:clear` | Clears lockouts, rate limiters, step-up codes, circuit state, heartbeat, dedupe keys, held delivery statuses, email-change signatures, overlap mutexes. Unique-job locks (`cache_locks`) survive. | the runbook *prescribes* `optimize:clear` at deploy step 5 (P89-OPS-004) |
| Queue backlog | alert after 15 min, but the alert travels on `notifications` behind the backlog; reliability sweep re-queues messages Queued > 10 min | §4, §9 |
| Worker crash | `retry_after` 330 > timeout 300 ✔; unique jobs and locked claims ✔; stuck Sending → Failed (never auto-resent) ✔; stuck automation re-queued once or failed ✔ | §3, §4 |
| Scheduler crash | `unless-stopped` ✔; every task `withoutOverlapping` + `onOneServer` ✔; killed background tasks leave locks for up to 3 h; **a dead scheduler cannot alert** (the health check runs in it) | §5 |
| Mail / SMS / WhatsApp outage | tries 5, backoff 30 s → 30 min, circuit breaker, send-time guard ✔. Auth mails (reset, OTP, portal link) have no breaker, tries 3. | §6 |
| AI provider outage | synchronous Copilot: 60 s timeout, no retry, no breaker; AI jobs: tries 2–3, `failed()` sets status | §6 (one line) |
| Calendar / job board outage | backoff up to 1 h; Failed when retries run out; log only | §6 |
| Object storage | none in use; local volume only | none |
| Interrupted deployment | `set -e` + `migrate --force` at app start; a failed migration stops `app` (no restart); MySQL DDL is not transactional; workers start once `app` has *started*, not *migrated* | §1 ("never roll back migrations") |

## 3. Deployment

| Topic | Finding |
|---|---|
| Image | Three-stage build: composer `--no-dev` → Vite → `php:8.3-apache` runtime with LibreOffice. `opcache.validate_timestamps=0`; `memory_limit 256M`. **No `HEALTHCHECK`.** |
| Build context | `.dockerignore` does not exclude `storage/app/*`, and the build does `COPY . .`. Local files can end up in image layers (P89-SEC-005). |
| Topology (compose) | 1 `app` (port 8080, `RUN_MIGRATIONS=true`), 1 scheduler, 3 single-process workers, MySQL 8.4 (`MYSQL_ALLOW_EMPTY_PASSWORD`, P89-SEC-006). Only the database has a health check. |
| Image tag | unversioned, so rollback means rebuilding old code (D8.9-022) |
| Entrypoint | `chown -R` over storage on **every start of every container** (slow on a large volume, P89-OPS-008); `storage:link`; migrations on `app` only; config / route / view / event caches; `exec setpriv` so signals reach workers |
| Drain | `queue:restart`, then wait for reserved rows to reach 0; drain `communications` before message-handling changes |
| Grace | workers and scheduler 330 s; **app: none** (default 10 s cuts long LibreOffice or Copilot requests) |
| Maintenance mode | `file` driver, per container. `down` on `app` does not stop workers. Not used by the current runbook. |
| Zero downtime | not possible as built: single `app` container recreated on deploy (D8.9-023) |
| TLS / proxy / CDN | not in the repository. SEC-88-10 stays deferred on the stated topology ("Apache serves directly"). Re-open it if a proxy is added. |
| Production `.env` | unverified (SEC-88-27 deferred C). Freeze prerequisites `SESSION_SECURE_COOKIE`, `APP_DEBUG=false` and the shared cache store are unconfirmed (P89-OPS-011). |
| Production code | `main` / `production` @ `9cba8e3` lack hotfix `2fab3fd` (Phase 8.6 SEC-1, Critical, **carried forward**), and the hotfix lacks SEC-86-I-01 (P89-OPS-012, D8.9-027) |

## 4. Backup and disaster recovery

**Not found anywhere in the repository:**
- backup scripts or a scheduled backup;
- `spatie/laravel-backup`;
- `mysqldump`;
- binlog / point-in-time configuration;
- a restore procedure;
- a restore test;
- a DR plan;
- RTO / RPO definitions.

The runbook says only "Back up the database." Files on `storage-data` have no backup or replication. The retention decision (`phase-8-8-retention-decision.md:89`, R-13) explicitly parks backup *retention* as a policy question.

| What must be recoverable | Store today | Backup today |
|---|---|---|
| Relational data (candidates, applications, offers, audit, …) | MySQL `db-data` volume | **none found** |
| Queue state, sessions, cache | same MySQL | none (most is transient, but the queue holds unsent messages) |
| Resumes, documents, offer letters, exports | `storage-data` volume | **none** |
| Encryption key (`APP_KEY`) | `.env` | unknown. Losing it makes queued encrypted payloads, encrypted columns (calendar tokens, MFA secrets) and sessions unreadable. |
| Logs | `storage-logs` volume | none (single unrotated file) |

**Encryption at rest:** not applied to the database or files, except calendar tokens and MFA secrets. `SESSION_ENCRYPT=false`.

**Decisions required (none invented here):**
- RTO (D8.9-007);
- RPO (D8.9-008);
- backup policy (D8.9-009);
- DR strategy (D8.9-010);
- DR test cadence (D8.9-028);
- backup retention (Legal, with R-13; deferred).

## 5. Files and storage (operational view)

| Topic | Finding |
|---|---|
| Disks | `local` (private, `serve=false`, signed `files/private` route since 8.8); `public` (employee photos, P88-BACKLOG-007); `s3` defined, unused |
| Limits | resumes 5 MB; documents 10 MB; AI documents 12 MB (Livewire default); PHP 20M |
| Processing | DomPDF; LibreOffice synchronous in the release request (120 s); no OCR; **no malware scanning** |
| Orphans | none removed. Deleting a row leaves the file. Files stored before the transaction can orphan on rollback. |
| Growth | ≈ 1 TB at 1M candidates (PROJECTED, PF-88-07); exports never deleted (expiry deferred with SEC-88-02) |
| Development host | 231 test-written offer-letter PDFs plus fixtures in `storage/app` (P89-DQ-016, P89-SEC-005) |

## 6. Runbook coverage

**Present:** `docs/runbooks/queue-operations.md` only, covering:
- deploy;
- drain;
- failed jobs;
- stuck work;
- scheduler re-runs;
- provider outages.

**Missing:**
- database outage;
- backup and restore;
- disaster recovery;
- disk full;
- log rotation;
- key rotation (`APP_KEY`, the Gemini key from Phase 7);
- TLS / proxy;
- storage;
- deploy rollback;
- cache-flush consequences;
- incident severity and escalation.

## 7. Operational findings (P89-OPS)

| ID | Sev | Component | Evidence (verified) | Impact | Current control | Direction | Impl. dep. | Decision dep. |
|---|---|---|---|---|---|---|---|---|
| **P89-OPS-001** | **High** | Backup / DR | none found (§4) | data loss on volume or host failure cannot be bounded | none | Operations defines policy, then implements and **tests restores** | infrastructure | D8.9-007/008/009/010/028 |
| **P89-OPS-002** | **High** | Alerting | platform alerts go through `notifications` on the `queue` worker; health check runs in the scheduler; check skipped when the heartbeat key is missing (`QueueHealthService.php:138`) | a dead worker or scheduler cannot report itself | `platform.alert` log line; `/health/queue` polling | external heartbeat monitor; external channel; split `notifications` (ED-05) | small + infra | D8.9-020 |
| P89-OPS-003 | High *(planning)* | Capacity | production volumes, growth rate and concurrency unknown | no basis for a supported-scale statement | runbook claim of ~100k / 500 | Operations supplies the facts | none | D8.9-001/026 |
| P89-OPS-004 | Medium | Deploy runbook | step 5 `optimize:clear` → `cache:clear` deletes the whole `cache` table | every deploy resets lockouts, OTPs, circuit state, heartbeat, dedupe keys (duplicate alerts after deploy) | none | use `config:clear` / `route:clear` / `view:clear` and not `cache:clear` | runbook text | Operations |
| P89-OPS-005 | Medium | Deployment model | migrations at container start; worker/migration race; unversioned tag; no health checks; no `app` restart policy or grace | partial schema; failed deploy leaves no running app; no rollback artefact | runbook drain steps | versioned images; separate migration step; health checks | infra | D8.9-022/023 |
| P89-OPS-006 | Medium | Logging / monitoring | `single` file never rotated; debug level; no APM; `/up` without a database check | disk fill; slow diagnosis; false healthy | redaction tap | rotation, level, structured logs, DB health check | config | D8.9-019/030 |
| P89-OPS-007 | Medium | Workers | one process per worker group; 128 MB default; strict priority starvation | throughput ceiling; OTP and reset expiry during bursts | 3 isolated groups | scaling plan (P89-PERF-006) | infra | D8.9-018 |
| P89-OPS-008 | Low | Entrypoint | `chown -R` over storage on every container start | slow starts on a large volume, delaying recovery | none | change ownership once, or scope it | trivial | — |
| P89-OPS-009 | Medium | Housekeeping (technical tables) | expired `cache` rows; `job_batches`; `password_reset_tokens`; export files; webhook events; limit-skipped automation rows; none pruned | unbounded growth of non-legal technical data | `failed_jobs` 720 h | ED-08 for technical tables only; anything with legal meaning waits for the retention phase | small | Engineering (not retention) |
| P89-OPS-010 | Medium | Runbooks | §6 gaps | improvised incident response | queue runbook | write the missing runbooks after the D8.9 decisions | docs | D8.9-021/025 |
| P89-OPS-011 | Medium | Production configuration | production `.env`, topology, PHP / MySQL config, TLS / proxy unverified; `.env.example` has dev defaults; dev seeder password (TD-002) | security and performance assumptions may not hold | 8.8 freeze prerequisites list | Operations confirms; checklist | none | D8.9-026 |
| P89-OPS-012 | **High** *(carried forward)* | Production release | hotfix `2fab3fd` not in `main` / `production`; it also lacks SEC-86-I-01 | the 8.6 SEC-1 delete-authorization gap may be live in production | none | release decision | release | D8.9-027 |
| P89-OPS-013 | Medium | Testing | concurrency tests run on SQLite only; no multi-process load test has ever run (8.7-KL-1) | MySQL lock and snapshot behaviour unverified | code review | MySQL concurrency harness (ED-10); load test per D8.9-011 | medium | D8.9-011 |
| P89-OPS-014 | Low | Development queue | one job on the dev `default` queue, stale for 17 days (8.8-U3) | noise in health checks; unknown payload | none | inspect and decide; **not deleted in discovery** | none | Engineering |
| P89-OPS-015 | Medium | Secrets | Gemini key rotation (Phase 7 production blocker) never tracked since | possible exposed key still in use | none | Security / Operations confirms rotation | none | Security |

## 8. Implementation dispositions (Phase 8.9 implementation)

**Engineering's boundary.** Engineering changed the application, the compose stack, configuration defaults and runbooks. It did **not**:
- touch production;
- create a backup system;
- set an RTO, RPO, SLO, severity model or retention period.

Each of those stays with its owner.

| ID | Sev | Disposition | What changed | Evidence | Remaining |
|---|---|---|---|---|---|
| **P89-OPS-001** | **High** | **OPEN — Operations** (runbook support delivered) | `docs/runbooks/backup-restore.md`:<br>• what must be protected: database, files, `APP_KEY`, configuration and release tag;<br>• consistent `mysqldump --single-transaction` plus a same-point file archive;<br>• restore steps and post-restore checks;<br>• DR from host loss.<br>It states plainly that **no backup system exists** and that nothing is verified. | runbook | No backup is taken, stored or restore-tested anywhere. RTO / RPO / policy / DR / restore-test cadence: D8.9-007/008/009/010/028. **Production-blocking until Operations implements and tests it.** |
| **P89-OPS-002** | **High** | **FIXED in the application**; external monitor **OPEN (D8.9-020)** | • Security and alert traffic moved to their own `queue-priority` worker (`security,notifications`; ED-05).<br>• Every worker process writes a heartbeat; `queue:health-check` and `/health/queue` report a silent worker or a scheduler that never reported (`QUEUE_EXPECT_PROCESSES`).<br>• `ops:heartbeat` is the container health check for every worker and the scheduler.<br>• `/up` checks the database. | `4607de3`, `88c10b8`, `1aecb33`, `40b3e3f`; `ObservabilityTest`, `QueueHealthTest`, `DeploymentTopologyTest` | Something outside the stack must poll `/up` and `/health/queue` and page someone. Choosing that monitor and its channel is D8.9-020; none is installed. |
| P89-OPS-003 | High *(planning)* | **OPEN — Product / Operations** | Benchmarks at 100k / 500k / 1M on the final code (`phase-8-9-performance.md` §9) give owners facts to decide from. | performance doc | Production volumes, growth and concurrency are still unknown (D8.9-001/026). **No supported-scale claim is made.** |
| P89-OPS-004 | Medium | **FIXED** | The deploy runbook no longer prescribes `optimize:clear`. It lists exactly what clearing the cache destroys and the safe `config:clear` / `route:clear` / `view:clear` / `event:clear`. | `1aecb33`; `queue-operations.md` §1 | none |
| P89-OPS-005 | Medium | **FIXED** (compose) | • A one-shot `migrate` service runs first; nothing else starts on a failed migration.<br>• `app` gets a restart policy, a 130 s grace and an `/up` health check, and depends on migrations having completed.<br>• Workers depend on a healthy app and are healthy on their heartbeat; the scheduler depends on healthy workers.<br>• `RUN_MIGRATIONS=false` everywhere.<br>• The image is tagged `recruitment-edge-app:${APP_IMAGE_TAG}`, so rollback is by tag. | `1aecb33`; `DeploymentTopologyTest` (compose parsed as YAML — Docker is not installed on this host, so the stack was not started) | Zero downtime is not provided: one `app` container (D8.9-023). A container that is only `unhealthy` (hung) is **not** restarted by Docker Compose; see `incident-recovery.md` §4. |
| P89-OPS-006 | Medium | **FIXED** (except tooling) | • Logs: `daily` by default, level `info` in production, redaction on every file channel.<br>• Apache log without query strings, with request id and duration.<br>• Every job logs `queue.job_processed` (class, queue, attempt, duration) and carries `job` context.<br>• `/up` is database-aware. | `88c10b8`; `ObservabilityTest` | No APM, error tracker or JSON log format (D8.9-019). Log deletion (`LOG_DAILY_DAYS`) waits for retention R-13. |
| P89-OPS-007 | Medium | **PARTIAL** | Four isolated worker groups instead of three; OTP, reset and alerts no longer queue behind candidate messages. Scaling by replicas is documented, and every job is safe with several workers. | `4607de3`; `queue-operations.md` §2 | One process per group by default; throughput ceilings in `phase-8-9-performance.md` §5 remain (D8.9-018). The automation limit check assumes **one** automation process (`.ai/rules/automation.md`). |
| P89-OPS-008 | Low | **FIXED** | The entrypoint changes ownership once per volume (`.ownership-fixed` marker), or on demand with `FIX_STORAGE_OWNERSHIP=true`. | `1aecb33` | none |
| P89-OPS-009 | Medium | **FIXED** (technical tables) | Daily pruning:<br>• expired `cache` rows (`cache:prune-expired`);<br>• expired reset tokens (`auth:clear-resets`);<br>• finished batches past the failed-job window (`queue:prune-batches`);<br>• limit-skipped automation runs, in batches.<br>`storage:audit` (read-only) reports file growth and orphans. | `9ef9c1b`, `a5984ab`, `1a321c9`; `TechnicalHousekeepingTest`, `StorageAuditTest` | Export files and webhook events are **not** pruned: retention, SEC-88-02. |
| P89-OPS-010 | Medium | **ADDRESSED** | New: `backup-restore.md`, `production-environment.md`, `incident-recovery.md` (database outage, disk full, dead or hung worker or scheduler, failed deploy and rollback, key rotation, proxy / TLS change). Revised: `queue-operations.md`. | runbooks | Incident severity and escalation (D8.9-021) and the support boundary (D8.9-025) are owner decisions. |
| P89-OPS-011 | Medium | **OPEN — Operations** (checklist delivered) | `production-environment.md` lists every setting production must have and why. `.env.example` gained the new variables. | runbook | Nothing about production was verified (D8.9-026). |
| **P89-OPS-012** | **High** *(carried forward)* | **OPEN — release decision** | Re-checked: `main` / `production` @ `9cba8e3` still lack `2fab3fd`, and the hotfix still lacks SEC-86-I-01 (`phase-8-9-security-review.md` §6.3). Not merged; not deployed. | git | **Production-blocking:** D8.9-027 (Security + Operations). |
| P89-OPS-013 | Medium | **FIXED** (harness); load test **OPEN** | A MySQL 8.4 concurrency harness (`tests/Concurrency`, `phpunit.concurrency.xml`, ED-10) proves the lifecycle and incentive races, the lock order and last-CHRO protection on real InnoDB locking. | `b55683e`, `3797c71`; concurrency suite: 8 / 8 in seven consecutive runs on the final code, 8 / 8 failing on the baseline | No multi-process load test was run (D8.9-011). |
| P89-OPS-014 | Low | **UNCHANGED** | The development database's stale job was left untouched, as in discovery (development state, not production). | — | Engineering may inspect and clear it on the development host. |
| P89-OPS-015 | Medium | **OPEN — Security / Operations** | Listed in `production-environment.md` (rotate the AI key and revoke the old one). | runbook | Confirmation of the Gemini key rotation. |

**Production-blocking items not resolvable by engineering:**
- P89-OPS-001: no verified backup or restore;
- P89-OPS-012: the production release decision for the delete-authorization hotfix.

P89-OPS-002 also needs its external monitor (D8.9-020) before production relies on it.
