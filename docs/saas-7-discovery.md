# SaaS-7 — Discovery Report: Scale, Reliability, Security Hardening & Production Architecture

**For:** the project owner, Security, Operations and Engineering — what the repository actually contains today, what is missing for commercial production, and what SaaS-7 will implement versus leave to infrastructure or owner decisions.

**Method:**
- **Reviewed:** the repository at `e57ddaa` (SaaS-6), on branch `feature/saas-7-scale-reliability`.
- **Read in full:** configuration, Docker files, runbooks, earlier security reviews and decision registers.
- **Audits:** five parallel read-only audits (queues and scheduler; tenancy crossings; egress and secrets; database and locks; operations), each finding cited `file:line` and spot-checked before inclusion.
- **Measured on throwaway databases only:**
  - the permission cache on a scratch SQLite file, at the production PHP memory limit;
  - tenant-task and queue cost on a throwaway MySQL 8.4.11 schema (`hrms_saas7_probe`, dropped).
- **Source databases** (`hrms`, `hrms_p87_perf`) were only read.

Nothing here is presented as production-verified. "Measured" means measured on the hosts above; "estimated" is marked.

## 1. Executive summary

SaaS-1 to SaaS-6 built a tenant-isolated, audited, entitled product with a careful single-host operational design: Phase 8.x queue topology, heartbeats, queue health, redaction, runbooks. SaaS-7 discovery found:

**Two measured scale blockers**

1. **Spatie's permission cache is one global map of every tenant's roles** (S1-10, previously rated Low). At the production `memory_limit=256M`:
   - building it needs about 205 MB at 100 tenants and runs out of memory before 250 tenants;
   - every web request, and every queued job, loads the whole map.

   Past roughly 150–250 tenants, the first permission check after any role change fatals for every tenant. Re-rated **High (availability)**.
2. **The scheduler enqueues about 57 jobs per tenant per hour**, most of them no-ops. Measured cost is about 34 ms each (2.2 ms enqueue, about 32 ms worker), so roughly 1.9 s of worker and database time per tenant per hour even with no data:
   - 1,000 tenants: about 0.5 worker-hours per hour;
   - 5,000 tenants: about 2.7 worker-hours per hour across four single-process workers (estimated by extrapolation).

**Reliability defects (fixable in code)**

- **Resuming a suspended tenant does not resume its work.** Jobs refused while a tenant is paused use up their attempts, then their `failed()` marks messages, automation runs and imports Failed. The SaaS-3 resume (D-S3-15) then replays jobs that find terminal state and do nothing.
- **Cache locks and rate-limit increments run on the default connection.** With `CACHE_STORE=database` they join the business transaction: a duplicate intake waits on an uncommitted lock row (up to `innodb_lock_wait_timeout`) while holding the tenant's shared lock.
- **Tenant tasks can hold a 120 s worker for up to 300 s.** `default` (documented as "always empty") receives tenant tasks every hour.
- **`intelligence:refresh` can run two overlapping loops** once a run exceeds its 120-minute lock.
- **Seven mail and notification classes have no deterministic failure path**: no tries, no backoff, no `failed()`. They include password reset and OTP mail.
- **Nobody receives platform-level health alerts.** A silent worker or stalled scheduler is alerted to "settings.manage holders" with no tenant, which resolves to nobody. A single scheduled task that keeps failing raises nothing.

**Tenant isolation: two narrow defects, no systemic break**

- `AccessReview`'s "Needs attention" filter uses an unscoped `ownership_handoffs` subquery, which leaks one bit across tenants for shared users.
- The ownership-handoff job's unique lock omits the tenant, so the same user in two tenants can lose a handoff (availability).

Every other crossing is allow-listed or tenant-filtered (A3).

**Operational gaps**

- no production configuration validation;
- trusted proxies unset;
- liveness and readiness not separated;
- no API or database-latency telemetry;
- no backups (documented, not configured);
- audit immutability enforced in the application only;
- no re-encryption path for `APP_KEY` rotation;
- export files and logs kept forever;
- the maintenance flag is per container (the site reopens on recreate);
- no CI.

**No Critical finding. The High finding is fixable in code.**

**DISCOVERY STATUS: READY FOR IMPLEMENTATION** (§27).

## 2. Current architecture

**Baseline**

| Item | Evidence |
|---|---|
| Branch / HEAD / tree | `feature/saas-7-scale-reliability` at `e57ddaa`, clean |
| Runtime | PHP 8.5.4 local / 8.5.11 image (digest-pinned, `Dockerfile:7-10`); Laravel 13.30.1; Filament 5.7.6; Livewire 4.4.2; spatie/laravel-permission 8.3.0 (teams = tenants, `config/permission.php`); Composer 2.9.5; MySQL 8.4.11 (local), `mysql:8.4` (compose, not digest-pinned) |
| Direct packages | dompdf 3.1.2, phpspreadsheet 5.9.0, phpword 1.4.0, pdfparser 2.12.5, tinker, pail, boost/pao (dev), pest 5.1.3 |
| Drivers (local `.env`, and `.env.example`) | queue `database` (retry_after 330, failed `database-uuids`); cache `database`; session `database` (unencrypted, `secure` unset); filesystem `local` (+`public`; `s3` defined, unused); mail `log`; logging `stack`→`daily` (`LOG_DAILY_DAYS=0` = forever); broadcasting `log` |
| Topology (`docker-compose.yml`) | one image: `migrate` (one-shot) → `app` (Apache mod_php, `/up`) → `queue` (communications), `queue-priority` (security, notifications), `queue-automation`, `queue-background` (documents, intelligence, integrations, exports), each **one process**, all with `default` → `scheduler` (`schedule:work`) → `db` (mysql:8.4, non-root app user, binlog on) |
| Not present | Redis/Memcached, object storage, TLS proxy, backup service, CI pipeline, external monitor, APM, error tracker |

**Inventory (A2)**

| Area | Count / shape |
|---|---|
| Tenant tables | 117 classified TENANT (`TenantSchema`), 3 nullable, 12 platform, 7 identity, 74 composite references |
| Queued classes | 18 jobs, 4 queued listeners, 6 mail/notification classes, Filament export jobs |
| Scheduled entries | 27 (`routes/console.php`). 14 queued tenant tasks via `tenants:dispatch`, 3 background via `tenants:run --all`, the rest platform |
| Outbound HTTP | 8 reviewed senders (AI, WhatsApp, Twilio, Google/Microsoft calendar, Zoom, webhooks) + SMTP + LibreOffice (local process) + optional breached-password check (§5) |
| API / webhooks | 14 `/api/v1` routes; inbound hooks; outbound deliveries (SaaS-6) |

**High-volume paths:**
- candidate and application intake (career site, API, inbound webhook, referrals);
- stage transitions (automation and webhook fan-out);
- communications;
- dashboard widgets (5 s polling);
- tenant scheduler fan-out.

**Long-running paths:** `intelligence:refresh` (414 s cold per 100k tenant), purge (290 s jobs, 15-minute lease), compliance export, offer-letter conversion, exports.

## 3. Current production blockers (before SaaS-7)

| ID | Blocker | Source | Type |
|---|---|---|---|
| P-01 | No backup or restore system; RPO/RTO undecided; restore drill stale (164 → 178 migrations) | P89-OPS-001 (High, open); `docs/runbooks/backup-restore.md:5-7` | Infrastructure + owner |
| P-02 | Production-copy migration rehearsal never run (SaaS-1..6) | D-S1-O1, D-S5-O12, D-S6-O14 | Release gate |
| P-03 | Payment provider, prices, GST, invoices undecided | D-S4-O1…O11 | Owner |
| P-04 | Support, deletion, retention, operator decisions | D-S5-O1…O13 | Owner |
| P-05 | API exposure, plans with API/webhooks, retention, versioning | D-S6-O1…O15 | Owner |
| P-06 | Secrets protected by `APP_KEY` only; no vault | S6-M2 | Infrastructure (+ code §4) |
| P-07 | No network egress control below the application guard | S6-M3 | Infrastructure |
| P-08 | TLS / proxy / trusted proxies / API domain | SEC-88-10; `production-environment.md:36` | Infrastructure (+ code) |
| P-09 | No external monitor or alerting route | D8.9-019/020 | Infrastructure + owner |
| P-10 | Permission cache exhausts memory at about 150–250 tenants | this report §10 | **Code (High)** |

## 4. Secrets findings (A5)

| Secret | Stored | Protection | Rotation | Leak paths |
|---|---|---|---|---|
| `APP_KEY`, `APP_PREVIOUS_KEYS` | env | — | Manual (`incident-recovery.md:90-94`). **Nothing re-encrypts**, so an old key must stay in `APP_PREVIOUS_KEYS` forever. D-S6-O7's "rotation re-encrypts" is not implemented. | Rotation also invalidates step-up codes, portal links and candidate sessions (raw HMAC with the current key: `CandidateStepUpService.php:132`, `CandidatePortalService.php:155,192`) |
| API credentials | `api_credentials.secret_hash` | SHA-256 | Rotate/revoke (owner/admin) | none |
| Webhook secrets | `integration_connections.secrets` | `encrypted:array` (`APP_KEY`) | 24 h overlap | `config.url` is plain JSON (credentials in a URL path would persist; the guard refuses userinfo only) |
| Calendar OAuth tokens | `calendar_connections.{access,refresh}_token` | `encrypted` | refresh flow; disconnect does not revoke at the provider | — |
| Inbound payloads, idempotency responses | encrypted columns | `encrypted:array` | — | — |
| MFA secret / recovery codes | `users` (Filament) | encrypted / bcrypt | re-enrol | — |
| Provider keys (AI, WhatsApp, Twilio, calendar, Zoom), mail, DB, Redis, AWS | env → config cache file | plaintext env | manual | config cache file on the image's writable path |
| Sessions | `sessions.payload` | **unencrypted** (`SESSION_ENCRYPT=false`) | — | DB backups |

**Encryption depends only on `APP_KEY`.** Anyone with the database (or a backup) and `APP_KEY` decrypts every encrypted column.

**Logs.** A central redaction tap runs on every channel except `emergency` (`config/logging.php:65-147`). Its gaps:
- key list exact-match only, missing `payload`, `signature`, `secrets`, `client_secret`, `cookie`;
- no patterns for `whsec_…`, `re_<id>_<secret>` tokens or bcrypt hashes;
- `WordToPdfConverter.php:46,55` logs raw LibreOffice stderr.

**Exports.** Excluded by a column regex (`ComplianceExportService.php:49`), which covers every secret column.

**Backups.** Include every column. Encrypted ones are safe only if `APP_KEY` is stored apart from them.

**Conclusion.** A vault or KMS is an infrastructure decision (D-S7-O1). The code-level gaps are:
- a re-encryption command (makes `APP_KEY` rotation real);
- startup validation of required secrets;
- redaction pattern gaps.

## 5. Network findings (A6)

Every outbound call, with destination and controls:

| Caller | Destination | Timeout | Redirects | In a DB lock? |
|---|---|---|---|---|
| `WebhookDeliveryService` | **tenant URL** | 10 s / 3 s connect | off; pinned address; 1 KB read | no (atomic claim; HTTP outside any transaction) |
| OpenAI / Gemini providers | `*_BASE_URL` (platform) | 60 s / default connect | Guzzle default (5) | no |
| WhatsApp / Twilio | fixed | 15 s | default | no (claim commits before send) |
| Google / Microsoft calendar, Zoom | fixed | 10–15 s | default | no (`CalendarSyncService.php:69-77`) |
| SMTP | `MAIL_HOST` | `timeout => null` (`config/mail.php:48`) | n/a | no |
| Breached-password check | api.pwnedpasswords.com (off by default) | 30 s | default | no |
| LibreOffice (local process) | possible implicit fetches from DOCX | 120 s | — | no; **no network isolation** |

**Assessment**
- No external call runs inside a transaction or lock (verified).
- Only webhooks reach tenant-chosen URLs. That path is guarded (SaaS-6: 19 refusal cases, pinning, no redirects).
- Fixed-host providers carry no SSRF risk from tenant input.
- Gaps:
  - SMTP has no timeout;
  - an `HTTPS_PROXY` in the worker environment would bypass the webhook pin;
  - LibreOffice can fetch remote resources (not verified; no isolation).

**Minimum production architecture (document; infrastructure)**
- **Egress allow-list for workers:** AI hosts, provider hosts, SMTP and DNS. Webhook egress goes through a dedicated route (proxy or NAT with fixed IPs) with metadata, RFC1918 and link-local addresses denied at the network layer.
- **No egress from the web tier** except SMTP, the breached-password check and calendar OAuth.
- **LibreOffice** runs with no network.

## 6. Queue findings (A7)

**Every queued class (summary)**
- 21 of 28 classes declare tries, backoff and `failed()`.
- **Missing all three:** `CandidatePortalLink`, `CandidateStepUpCode`, `AiCopilotEmail`.
- **Missing `failed()`:** `ResetPassword`, `NoticeOfEmailChangeRequest`, `VerifyEmailChange`.
- `QueueContractTest` scans only `app/Jobs` and `app/Listeners` (`QueueContractTest.php:14-28`), so these are unenforced.
- **Filament `ExportCsv`:** `retryUntil` = now + 24 h, so `tries` is ignored and a poison chunk retries about 150 times.

**Paused-tenant replay defect (D-S3-15 regression)**
- `TenantQueueGuard` throws `TenantUnavailable` on `JobProcessing`. The worker counts it as an attempt and, when attempts run out, calls the job's `failed()`. These finalise domain state:
  - `SendCommunicationJob.php:150` marks the message Failed;
  - `RunAutomationExecutionJob:61` marks the run Failed;
  - `ImportInterviewersJob:83` deletes the upload;
  - `ConvertOfferLetterJob:60` fails the conversion;
  - the AI jobs mark their records Failed.
- `PausedTenantWork::resume` then re-queues them, but their claims find terminal state and do nothing.
- Only `ProcessInboundWebhook` and `DeliverWebhook` (SaaS-6) leave their state alone.
- **Medium (data correctness):** a suspended-then-reactivated tenant's queued messages are never sent.

**Timeouts**
- `RunTenantScheduledTask::$timeout = 300` runs on workers started with `--timeout=120`. A job's own timeout wins (`Worker.php:351-353`), so a tenant task can hold `queue-priority` (password resets, OTPs) for 300 s.
- `QueueTopologyTest` compares `retry_after` with the workers' `--timeout` only.

**Ordering and starvation**
- Workers are single-process and strict-priority.
- `queue-background` runs `documents` (LibreOffice ≤ 120 s) before `intelligence`, then `integrations`, then `exports`.
- `ProcessBillingEvent` (payment events), `DeliverWebhook`, calendar sync and purges all share `integrations`, behind AI and document work and FIFO with tenant-generated webhook volume.

**Tenant monopolisation (S1-03, made worse by SaaS-6)**
- One tenant can enqueue up to about 600 API intakes per minute, up to 10 endpoints each, which means thousands of `DeliverWebhook` jobs per minute on `integrations`.
- `integrations:sweep` re-dispatches up to 500 due deliveries every 5 minutes even while the originals wait. `DeliverWebhook` is not unique, so backlogs multiply.
- Every other tenant's webhooks, calendar sync and **billing events** wait behind that tenant.
- No class uses rate-limiting job middleware (grep).

**Infinite retries.** None in app code (`tries ≥ 1` everywhere, no `retryUntil`). The vendor export job above is the exception.

**Unconsumed queues.** None with the default configuration. The env-overridable queue names (`COMMUNICATIONS_QUEUE` etc.) move only jobs, not listeners, tenant tasks or the compose `--queue` lists (drift risk, documented).

## 7. Scheduler findings (A8)

**Overlap and single-server protection.** All 27 entries use `withoutOverlapping` and `onOneServer`. The locks live in the shared database cache.

**Volume.**
- `tenants:dispatch` loads **every usable tenant with `->get()`** (`TenantDirectory.php:23`) and enqueues one `RunTenantScheduledTask` per tenant per task.
- Measured per tenant per hour: **about 57 jobs**.

| Queue | Jobs per tenant per hour |
|---|---|
| automation | 16 |
| communications | 13 |
| intelligence | 12 |
| integrations | 12 |
| security | 2 |
| notifications | 1 |
| default | 1 |

**Measured cost** (throwaway MySQL, 5 tenants with no domain data):
- in-process, per task: 3.7–9.6 ms;
- `notifications:dispatch-alerts`: 40.5 ms and 34 queries;
- database-queue round trip: **about 32 ms per job** (200 jobs drained in 6.9 s), plus **2.2 ms per enqueue**.

So about **1.9 s per tenant per hour of no-op work**, plus about 57 `jobs` rows and 57 unique-lock rows inserted and deleted per tenant per hour.

**Whole-population scans every tick**
- `queue:health-check` (every 5 min) runs `tenantProblems()` once per tenant: a JSON-path scan of `jobs` and two of `failed_jobs` each (`QueueHealthService.php:194`, unindexed). Cost is O(N × table) inline in the scheduler.
- `billing:sweep` (hourly, inline) locks billing for every non-terminal tenant in sequence. It is bounded per tenant but N-linear, and it delays the other tasks of the same `:00` run.
- `intelligence:refresh` (hourly, `withoutOverlapping(120)`, one tenant at a time, 2,700 s budget each). The total is unbounded in N, and a run over 120 minutes starts a second overlapping loop from tenant 1.
- **Per-task failures are invisible.** `problems()` alerts only on a global scheduler silence over 15 minutes.

## 8. Noisy-neighbour findings (A9)

| Vector | Today's limit | Cross-tenant effect |
|---|---|---|
| API | 120/min per credential, 600/min per tenant (SaaS-6) | DB load per request (rate-limit row lock serialises one tenant's requests, harmless across tenants) |
| Outbound webhooks | none on queue volume | **one tenant can delay every tenant's `integrations` work, including billing events** |
| Inbound webhooks | 300/min per connection | the same queue |
| Automation | 200 runs per tenant per 5 min dispatched; 500 per rule per day | single `queue-automation` process shared |
| Exports | 10,000 rows per export, chunks of 100; no concurrent-export cap | up to 100 jobs per export on `exports` (last in priority) |
| AI | per-user chat limits; no tenant quota for indexing | `intelligence` queue |
| Scheduler | none | O(N) fan-out (§7) |
| Dashboard | 5 s polling, uncached widgets | per open tab; DB load |

**Infrastructure protection vs commercial quota.** Commercial API quotas stay a SaaS-3 decision (D-S6-O11). Protection here means:
- per-tenant processing budgets that **defer** (never drop) a tenant's excess integration work;
- bounded per-tenant re-dispatch.

## 9. Database findings (A10, A11)

**Indexes.**
- SaaS-1 added `{t}_tenant_idx(tenant_id)` plus per-tenant uniques and `(tenant_id, fk)` indexes.
- Every older filter or sort index (status, stage, dates) is still cross-tenant. This was deliberately deferred to SaaS-7 (`saas-1-migration-plan.md:98,116`).
- InnoDB makes `tenant_idx` behave as `(tenant_id, id)`, so the API's id cursor is cheap (SaaS-6 measured page 22 at 29.3 ms).
- Candidates (to prove with EXPLAIN on a two-large-tenant copy; none added blindly):
  - `candidate_applications (tenant_id, status, current_stage)`: API filters, dashboards;
  - `candidates` / `candidate_applications (tenant_id, updated_at)`: API `updated_since` sync;
  - `candidate_stage_histories (tenant_id, created_at)`: period analytics;
  - `audit_logs (tenant_id, created_at)`: the tenant audit view.

**Queries.**
- Name search on candidates and applications is `LIKE '%x%'` with offset pagination and COUNT. Exact identifiers are short-circuited.
- Dashboard widgets poll every 5 s uncached; the leaderboard computes per recruiter.
- `CandidatePolicy::isInScope` adds about 150 queries per 50-row page for hierarchy-scoped users.

These are product-performance items. SaaS-7 measures and documents them; it does not rewrite the UI.

**Locks.**
- **Clean:**
  - no external HTTP inside a lock or transaction (verified);
  - lock order is consistent (application → interview/offer; tenant → membership; tenant → billing rows);
  - no path locks X then the tenant.
- **Findings:**

| Finding | Severity | Disposition |
|---|---|---|
| Cache locks and rate-limit increments on the default connection join the business transaction (`config/cache.php:42-48`, `CareerApplicationService.php:102`) | Medium | Dedicated cache connection (code + config) |
| Offer release renders the PDF inside the application/offer row-lock transaction (`OfferService.php:163-210`) | Medium | Single-row locks; documented, not changed (would alter SaaS-era atomicity) |
| `code_sequences` row lock serialises all intake per tenant until commit | Low | Measured intake p50 32 ms ⇒ about 30/s per tenant ceiling, above the 10/s API limit; documented |
| Tenant-row X lockers (requisition create, billing, invitations) queue behind intake S locks | Low | documented |

## 10. Cache findings (A4 and A3 §4)

**Permission cache (S1-10)**
- `PermissionRegistrar` caches **one map: every permission with every role of every tenant** (`App\Models\Role` has no tenant scope, by design).
- Every web request loads and hydrates it. Every queued job reloads it (`AppServiceProvider.php:379`, Phase 8.9 P89-SEC-003).
- Any role change in any tenant flushes it for all.

Measured (scratch SQLite, roles cloned from `RolePermissionSeeder`; about 6 roles and 252 role-permission rows per tenant):

| Tenants | Roles | Pivot rows | Cached value | Build (fresh process) | Load from cache |
|---|---|---|---|---|---|
| 100 | 596 | 25,719 | 0.37 MB | 2.9 s, **205 MB peak** | 18–21 ms, +4 MB |
| 250 | 1,496 | 64,569 | 0.97 MB | **fails: memory exhausted at 256 MB** | — (never cached) |
| 500 / 1,000 / 2,000 | — | — | — | fails at 256 MB (at 768 MB too, at 1,000 tenants) | — |

**Leak or staleness across tenants.** None: user roles are loaded per team (`tenant_id`), so another tenant's roles in the map never match. Workers clear the in-memory copy per job. **The issue is size, not leakage.**

**Other cache keys**
- 10 allow-listed global keys; all platform data or keyed by globally unique ids.
- Rate-limiter keys outside `TenantCache` hold counters only.
- One collision: the handoff unique lock (§1).
- `S6-L2` (rate limits per process): the **database** store *is* shared across processes. Only `array` / `file` stores are not, and neither may be used in production. This needs a startup check.

## 11. Observability findings (A12)

**Exists**
- request id on every log line, audit row and Apache line;
- job context;
- `queue.job_processed` with duration;
- worker and scheduler heartbeats;
- `QueueHealthService` (depth, oldest job, failures, stuck work, paused providers);
- `platform_events` with critical mail;
- per-tenant integration health columns;
- structured events (`api.*`, `webhooks.*`, `billing.*`, `platform.*`, `tenancy.*`, `identity.*`).

**Missing**
- API request outcome and latency;
- slow-query visibility;
- database or cache latency;
- webhook failure trend;
- per-task scheduler failure;
- platform-level alert recipients (§1).

Logs are plain text; JSON is available only on `stderr`.

| Operator question | Today |
|---|---|
| Application healthy? | `/up` (DB only), `/health/queue` (token) |
| Which tenants affected? | logs carry `tenant_id`; platform tenant page shows failed jobs |
| Which queue failing? | yes (`/health/queue`, `queue:drain-status`) |
| Which integration failing? | per tenant only |
| Webhook failures increasing? | **no** |
| API errors increasing? | **no** |
| Database latency increasing? | **no** |
| Cache failing? | **no direct check** |
| Workers overloaded? | backlog depth/age only |
| Scheduled tasks stuck? | global silence only |

## 12. Monitoring findings (A13)

There is no vendor and none is to be added (brief). The required signals and their sources (what SaaS-7 must make emit-able):

| Signal | Source after SaaS-7 |
|---|---|
| App error rate, latency | Apache `%D` + status (exists); exceptions to log |
| API 4xx/5xx/401/403/429, latency | new structured `api.request` line |
| Queue depth, oldest, failures, retry exhaustion | `/health/queue` (exists) |
| Worker utilisation | heartbeats + `queue.job_processed` durations |
| Webhook success/failure/retries/latency | `webhooks.delivery` lines + new platform integration summary in `/health/queue` |
| DB slow queries / lock waits | new `db.slow_query` line; MySQL `performance_schema` (infra) |
| DB connections/CPU/storage | infrastructure |
| Cache availability | readiness check |
| Billing webhook/reconciliation failures | `billing.*` lines (exist) |
| Purge/export failures | `platform_events` (exist) |

The integration boundary is a log shipper plus an uptime probe on the readiness endpoint and `/health/queue` (D-S7-O6).

### Health checks (A14)

| Endpoint | Checks | Access | Gap |
|---|---|---|---|
| `GET /up` | DB `select 1` | public | doubles as liveness and readiness; no cache or storage check |
| `GET /health/queue` | full queue snapshot | bearer `QUEUE_HEALTH_TOKEN` | `.env.example:53` wrongly says an empty token means "administrators only" (it means always 401) |
| `ops:heartbeat` | worker/scheduler heartbeat age | container check | — |

**Required:**
- liveness (no dependencies);
- readiness (DB, cache read/write, storage writable), public minimal status only;
- details stay behind the token.

## 13. Backup findings (A15)

- The repository contains **no backup system**: no service, script, binlog configuration or PITR (`backup-restore.md:5`).
- The runbook documents a manual, encrypted `mysqldump` plus volume tar, and restores into an empty database.
- One drill was run in Phase 8.10: 69/69 tables, 0 differences (`phase-8-10-release-readiness.md:82-93`), at 164 migrations. The schema is now at 178.
- **RPO/RTO are owner decisions** (D8.9-007/008, carried as D-S7-O7). No values are invented here.
- **Tenant-level restore:** feasible only by restoring a full copy elsewhere and extracting one tenant's rows. Composite keys make rows self-contained per tenant. No tooling exists.
- **What can live in code:** a post-restore integrity check (foreign-key orphans, tenancy verification, audit protection, migration state) to validate any restore.

## 14. Disaster recovery findings (A16)

| Failure | Today | Effect |
|---|---|---|
| Single host / region | no DR site, replica or standby | full outage until rebuild + restore |
| Database | single `db` container | full outage; data since last manual backup lost |
| Cache (= database) | same as database | — |
| Queue (= database) | same; jobs table in the backup | in-flight jobs replayed after restore (idempotent claims) |
| Object storage | local volume `storage-data` | files lost without a volume backup |
| Secret store | `.env` file | `APP_KEY` loss = encrypted columns unrecoverable |
| Deployment failure | rollback by image tag; migrations never rolled back | runbook |

The recovery model is documented in SaaS-7. Multi-region is out of scope.

## 15. Deployment findings (A17, A18, A24)

**Build and start**
- Digest-pinned images (except `mysql:8.4`).
- One-shot `migrate`, then health-ordered start.
- `config`/`route`/`view`/`event:cache` on every start.
- `setpriv exec` so SIGTERM reaches workers.
- 330 s grace for workers and scheduler; rollback by image tag.

**Gaps**

| Gap | Detail | Fix |
|---|---|---|
| No production configuration validation | `APP_DEBUG=true`, a missing `APP_KEY`, the `log` mailer, `array`/`file` cache, an insecure cookie or `DB_PASSWORD=secret` all start silently | **code: preflight + entrypoint** |
| Trusted proxies unset | `bootstrap/app.php` has none: behind a load balancer every IP limit is one bucket, `isSecure()` is false, HSTS is never sent | **code: env-configured** |
| Security headers | only portal, careers and auth pages; not the signed-in panels or the API | **code** |
| Maintenance flag uses the `file` driver in non-shared `storage/framework` | `docker compose up -d` reopens the site before the release finishes (`queue-operations.md:54`) | **config: cache driver on the shared store** |
| No CI | no automated test, build or audit | infrastructure / owner |
| No log size limits on Docker | — | infrastructure |
| Zero-downtime | single `app` container (D8.9-023) | infrastructure |

**PHP (A24)**
- `memory_limit 256M`, `max_execution_time 120`, upload 20 MB.
- Opcache on, with `validate_timestamps=0`.
- Workers: `--max-time=3600`.
- These values are adequate, except that the permission map (§10) breaks the 256 MB limit. No change is recommended without measurement.

## 16. File and storage findings (A19)

**Tenant paths.** Every tenant upload path uses `TenantStorage` (`tenants/{id}/…`, architecture-tested).

**Private files.** Signed relative URLs with a 5-minute TTL, bound to user and tenant. Owning record, policy and audit are checked on every download.

**Gaps**

| Gap | Detail | Status |
|---|---|---|
| `storage:audit` detects orphans but never removes them | — | owner retention decision |
| Filament export files are **never deleted**: tenant PII stays on disk forever | the download window is 24 h | **code: technical retention** |
| Three upload fields have no explicit `maxSize`: both photo fields, AI documents | Livewire's 12 MB temporary-upload rule bounds them | Info |
| Legacy S1-07 physical move | needs a production-copy rehearsal of storage (D-S5-O12) | stays deferred |

**Not present:** malware scanning, object storage, replication.

## 17. Audit findings (A20)

**Application level.** Eloquent `updating`/`deleting` throw (`AuditLog.php:75-76`). Query-builder and `DB::table` writes bypass it; none exist in `app/` today, but the backfill migration used one.

**Database level.** Nothing; the app user holds ALL. Retention: kept forever (D-S5-O5).

**Can a trigger ship as a migration?**
- The compose database user is non-root, and MySQL 8.4 has binlog on with `log_bin_trust_function_creators=0` (verified locally).
- In that setup MySQL refuses `CREATE TRIGGER` to a non-SUPER user, so **a trigger cannot be created by an ordinary migration**.

**Decision.** Triggers are installed by an explicit privileged command, and their presence is reported by preflight and readiness. Grants (no UPDATE/DELETE on `audit_logs` for the app user) remain an infrastructure step (D-S7-O8).

**Trigger compatibility (checked).** Foreign-key cascades do not fire triggers, purge retains `audit_logs`, and no app path updates or deletes it.

## 18. Retention findings (A21)

| Data | Mechanism | Period | Type |
|---|---|---|---|
| Failed jobs / batches | scheduled prune | 720 h | technical |
| Cache rows, reset tokens | scheduled | expiry | technical |
| Skipped automation runs | scheduled | 90 d | technical |
| Billing webhook payloads | scheduled (row kept) | 90 d | technical |
| Idempotency keys | `integrations:sweep` | 24 h | technical (D-S6-O13) |
| Webhook events/deliveries/inbound payloads | `integrations:sweep` | 30 d | technical (D-S6-O12) |
| Compliance exports | `platform:sweep` | 7 d | technical (D-S5-O6) |
| **Filament export files** | **none** | forever | technical — **gap** |
| **Application logs** | `daily`, `LOG_DAILY_DAYS=0` | forever | technical (D-S7-O9) |
| Audit, candidate PII, communications, AI logs, `platform_events` | none | forever | **legal** (R-1…R-13, D-S5-O5) — not invented |

## 19. Dependency findings (A23)

- **`composer audit`:** 0 advisories, 0 abandoned.
- **`npm audit`:** 0 vulnerabilities (137 dependencies, 6 production).
- **Direct packages behind** (all semver-compatible): laravel/framework 13.30.1 → 13.34.0; filament 5.7.6 → 5.9.0; phpspreadsheet 5.9.0 → 5.10.0; dev: boost, pest, pint, pao.
- **No upgrade is required** by security or compatibility, so none is planned (brief: no arbitrary upgrades).
- No CI runs audits (D-S7-O12).

## 20. Security findings (A22)

**Reviewed and found sound (no new issue):**
- CSRF (exempt only `webhooks/*`; the API is bearer-token);
- SQL injection (no raw input in raw SQL; 78 raw expressions all on scoped queries);
- mass assignment (no `$guarded = []`, no `request()->all()` into models);
- command injection (`WordToPdfConverter` uses a process argument array, no shell);
- deserialization (no `unserialize`);
- XSS (20 `{!! !!}`, each escaped or sanitised: CDATA escape, `AiMarkdown` strips HTML, offer-letter body sanitised, `nl2br(e())`);
- path traversal (private files resolved through owning records);
- webhooks and API (SaaS-6 review).

**New or open findings:**

| ID | Finding | Severity |
|---|---|---|
| S7-01 | Permission cache exhausts memory at about 150–250 tenants (availability) | **High** |
| S7-02 | Paused-tenant work finalised as Failed, never resumed | Medium |
| S7-03 | Cache locks join business transactions (`database` store, same connection) | Medium |
| S7-04 | Platform-level health alerts reach nobody; per-task scheduler failures unalerted | Medium |
| S7-05 | No production config validation; trusted proxies unset; headers only on public pages | Medium |
| S7-06 | Unscoped `ownership_handoffs` subquery (one-bit cross-tenant disclosure) | Low |
| S7-07 | Handoff unique lock collides across tenants (availability) | Low |
| S7-08 | Tenant tasks exceed worker timeouts; `default` used | Low |
| S7-09 | Mail/notification classes without a failure path; vendor export 24 h retries | Low |
| S7-10 | Redaction gaps (`whsec_`, `re_` tokens, bcrypt, raw LibreOffice stderr) | Low |
| S7-11 | `APP_KEY` rotation cannot retire the old key (no re-encryption) | Medium |
| S7-12 | Audit immutability enforced by the application only (S5-R1) | Medium |
| S7-13 | Export files kept forever | Low |
| S7-14 | Upload fields without explicit size (bounded at 12 MB) | Info |
| S7-15 | Forgot-password timing difference (S2-A4) | Low |
| S7-16 | `HTTPS_PROXY` would bypass webhook DNS pinning; LibreOffice not network-isolated | Low (infrastructure) |

## 21. Capacity model (A25)

All volumes below are **assumptions**, not measurements; the measured inputs are marked M.

**Per-tenant assumptions (one mid-size customer):** 15 active staff; 20 open requisitions; about 170 new candidates per month; 3 stage changes per application; 30% of tenants use the API (average 2 requests/min, peaks of 60/min); 20% use outbound webhooks (2 endpoints); 10% use inbound sources. Storage: 1 MB per candidate.

| Quantity per hour (derived) | 10 | 100 | 500 | 1,000 | 5,000 |
|---|---|---|---|---|---|
| Scheduler tenant-task jobs (57/tenant, M cost 34 ms) | 570 (19 s) | 5.7k (3.2 min) | 28.5k (16 min) | 57k (32 min) | 285k (2.7 h) |
| API requests (average) | 360 | 3.6k | 18k | 36k | 180k (50/s) |
| Webhook events → deliveries | ~10 → 20 | 100 → 200 | 500 → 1k | 1k → 2k | 5k → 10k |
| Permission map build (M) | <0.5 s | 2.9 s / 205 MB | **OOM** | **OOM** | **OOM** |
| Candidate rows (per year) | 20k | 204k | 1.0M | 2.0M | 10M |
| File storage (per year) | 20 GB | 200 GB | 1 TB | 2 TB | 10 TB |

**Measured per-operation costs** (SaaS-6, one 100k tenant):
- API auth: 11.8 ms;
- candidates page of 100: 28.7 ms;
- intake: 32.2 ms;
- inbound ingest: 6.1 ms.

At 50 requests/s, one Apache container and one MySQL with the API mix are plausible but **untested**: no load test exists (D-S7-O13).

**Ceilings before SaaS-7**
1. The permission map (≈ 150–250 tenants).
2. The scheduler fan-out on single-process workers (~1,000 tenants).
3. One `queue-automation` process.
4. `intelligence:refresh` hourly loop (fewer than about 9 large tenants per hour).

The capacity plan (`docs/saas-7-capacity-plan.md`) restates these with SaaS-7 measurements.

## 22. Migration rehearsal plan (A26)

**Production-copy procedure** (to be run by the release owner; never against production itself):

1. **Snapshot**
   - Take an encrypted backup per the runbook; confirm it restores into an empty instance.
   - Record `migrate:status`, `SHOW TABLE STATUS`, row counts and table sizes.
2. **Copy**
   - Restore the backup into an isolated `hrms_prodcopy` with the production MySQL version and configuration (`innodb_buffer_pool_size`, binlog).
   - Never point the application at production.
3. **Baseline**
   - CRC32 checksum of every column of every table (the SaaS-6 `rehearse.php`).
   - Effective entitlements and status of every tenant.
   - `tenancy:verify --all`; foreign-key orphan scan.
4. **Run**
   - `php artisan migrate --force` with timing per migration.
   - Watch `performance_schema.data_locks` and `SHOW PROCESSLIST` for metadata locks, with a second session issuing reads.
   - Index migrations use online DDL (`ALGORITHM=INPLACE, LOCK=NONE`).
5. **Validate**
   - checksums of every pre-existing table unchanged;
   - new tables and indexes present;
   - `tenancy:verify` 0 violations;
   - foreign-key orphans 0;
   - entitlements identical;
   - `ops:preflight` and readiness green;
   - the smoke scripts (API, webhooks, purge on a scratch tenant).
6. **Decide**
   - Rollback only if a validation fails; then restore the backup (migrations are never rolled back in production, per the runbook).
   - Record timings to size the maintenance window.

R1, R1b and R2 (development, mixed-state and 100k copies) follow the same script during SaaS-7. **The production-copy run remains a release gate.**

## 23. SaaS-4 / SaaS-5 / SaaS-6 carry-forward gates (A27)

These are consolidated in `docs/saas-7-production-readiness.md` (Phase G):
- D-S4-O1…O11
- D-S5-O1…O13
- D-S6-O1…O15
- S1-03, S1-10, S2-A4, S5-R1, S6-M2, S6-M3, S6-L2
- third-party penetration test (SaaS-1)

None is implemented automatically where it is an owner or infrastructure decision.

## 24. Required owner decisions (Phase B)

| ID | Decision | Safe implementation default | Blocks implementation? |
|---|---|---|---|
| D-S7-O1 | Production secrets manager (vault/KMS) | Env injected by the orchestrator; required secrets validated at start; re-encryption command for `APP_KEY` rotation | No |
| D-S7-O2 | Network egress model (proxy, NAT, fixed IPs) | Application guard stays (SaaS-6); minimum network policy documented | No |
| D-S7-O3 | API domain / TLS termination | Env-configured trusted proxies and hosts; HSTS when secure | No |
| D-S7-O4 | Shared cache infrastructure (Redis or database) | Database store on a **dedicated connection**; preflight refuses `array`/`file` in production | No |
| D-S7-O5 | Worker/queue infrastructure (processes per worker, Redis queue) | Current topology; billing events moved off the tenant-shared queue; per-tenant integration budgets | No |
| D-S7-O6 | Monitoring/alerting vendor and on-call | Structured log events, readiness probe and `/health/queue` as the boundary; platform health problems become critical platform events | No |
| D-S7-O7 | Backup tool, RPO, RTO, retention, restore cadence | Runbook + post-restore integrity command; **values not invented** | No (release gate) |
| D-S7-O8 | Audit immutability model (grants, triggers, log shipping) | Triggers installed by a privileged command; grants documented | No |
| D-S7-O9 | Retention policy (logs, exports, legal records) | Technical defaults only: export files 7 d, logs 30 d recommended; legal records untouched | No |
| D-S7-O10 | Production-copy migration rehearsal | Procedure §22; not run | No (release gate) |
| D-S7-O11 | Per-tenant workload limits (values) | Conservative defaults in config | No |
| D-S7-O12 | Deployment strategy (CI, blue/green, maintenance window) | Preflight in entrypoint; maintenance flag on the shared store; runbook | No |
| D-S7-O13 | Database scaling (instance size, replicas, load test) | Indexes proven by EXPLAIN only; no replica | No |

**No decision blocks implementation.** Every one has a safe default that changes no commercial or legal policy.

## 25. Recommended implementation (Phase C)

Ordered by severity and evidence.

**C1 Secrets**
- `security:reencrypt` (re-encrypt every encrypted column with the current key, so a previous key can be retired);
- startup validation of required secrets;
- redaction patterns (`whsec_`, `re_` tokens, bcrypt, client secrets) and LibreOffice stderr.

**C2 Egress**
- SMTP timeout;
- documented network policy. The application guard already exists.

**C3 Cache**
- **tenant-scoped permission cache** (per-tenant key plus a global version; invalidation per role's tenant; process-safe tenant switching);
- dedicated database connection for cache and locks;
- preflight store check.

**C4 Queue**
- every job/listener/mail/notification gets tries, backoff and a `failed()` that leaves paused-tenant work intact (contract test extended to Mail and Notifications);
- tenant-task timeout below its worker's timeout;
- `default` emptied;
- `DeliverWebhook` unique per delivery;
- billing events on their own queue on the priority worker;
- vendor export retry window bounded.

**C5 Scheduler**
- tenant fan-out only to tenants with work (per-task probes, superset-safe), enumerated in chunks;
- one-pass tenant health check;
- `tenants:run --all` time budget with a rotating cursor (no overlapping loops);
- per-task failure and staleness problems.

**C6 Noisy neighbour**
- per-tenant processing budgets for outbound deliveries and inbound processing; excess **deferred** to the sweep, never dropped.

**C7 Database**
- tenant-led composite indexes **only where EXPLAIN on a two-large-tenant copy proves the use case**.

**C8 Observability**
- `api.request` and `db.slow_query` structured lines;
- integration summary in `/health/queue`;
- platform health problems recorded as critical platform events.

**C9 Health**
- `/health/live`, `/health/ready` (minimal public payload); compose uses readiness.

**C10 Audit**
- trigger-based immutability via `audit:protect`, reported by preflight and readiness.

**C11 Files**
- Filament export file retention (technical, configurable).

**C12 Backup**
- `ops:verify-integrity` (orphans, tenancy, audit protection, migrations) for post-restore validation;
- runbook refresh.

**C13 Deployment**
- `ops:preflight` (enforced by the entrypoint in production);
- trusted proxies and hosts from env;
- security headers on the panels and API;
- maintenance flag on the shared store.

**C14 Security**
- fix S7-01…S7-13 (code-level).

**C15 Capacity**
- re-measure on the R2 copy (permission map, fan-out, health check, indexes, API).

**Tenancy fixes**
- scoped `AccessReview` subquery;
- tenant-keyed handoff unique id;
- architecture-test blind spots narrowed.

## 26. Deferred work

| Item | Reason | Dependency | Recommended phase |
|---|---|---|---|
| Redis cache/queue | infrastructure decision | D-S7-O4/O5 | when worker scale-out is needed |
| Vault/KMS envelope encryption | infrastructure | D-S7-O1 | infrastructure phase |
| Egress proxy / fixed IPs / WAF | infrastructure | D-S7-O2 | infrastructure phase |
| Backup automation, PITR, DR site | infrastructure + owner | D-S7-O7 | before production |
| CI pipeline, image build, dependency audit automation | infrastructure | D-S7-O12 | before production |
| Load test (concurrent users, API at 50/s) | needs a production-like environment | D-S7-O13 | before general availability |
| Candidate/application search (full-text), dashboard caching, policy N+1 | product performance, UI-level | — | product backlog |
| Offer PDF outside the row lock; `code_sequences` lock scope | changes earlier-phase atomicity | — | later reliability pass |
| S1-07 legacy file move | production-copy storage rehearsal | D-S5-O12 | release |
| Legal retention periods | Legal | R-1…R-13, D-S5-O5 | owner |
| Third-party penetration test | external | — | before general availability |
| Service accounts | identity redesign | D-S6-O2 | identity phase |

## 27. Risks

| Risk | Mitigation |
|---|---|
| A tenant-scoped permission cache changes authorisation plumbing | Same Spatie semantics; per-tenant key only; tested for invalidation, cross-tenant isolation and tenant switching inside one process (including a MySQL race) |
| A work probe that misses a tenant with work skips real work | Probes are supersets (any row that the task could act on); tested per task with mutants; a full fallback dispatch remains available (`tenants:dispatch --all-tenants`) |
| Budgets defer integration work | Deferred work stays due and the sweep re-dispatches it; nothing is dropped; tested |
| Preflight blocks a misconfigured production start | Intended; clear messages; one documented emergency override |
| Triggers on `audit_logs` | Privileged command, idempotent, removable; foreign-key cascades unaffected; purge retains audit rows |
| New indexes on large tables | Online DDL; timed on R2; only with EXPLAIN proof |

**Stop conditions.** None met:
- tenant isolation holds; the two narrow defects are fixed below;
- no Critical finding; the High finding is fixable;
- no migration risks existing data;
- no secret is exposed through an application path.

**DISCOVERY STATUS: READY FOR IMPLEMENTATION**
