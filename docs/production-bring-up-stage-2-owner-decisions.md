# Production Bring-Up — Stage 2: Owner Decision Sheet

**For:** the project owner, the release owner, Infrastructure/DBA, Security and Finance — to complete.

**Source of truth:** `docs/production-bring-up-stage-1.md` at commit `0630542`. Decision IDs (`BU-O…`) are Stage 1's. Facts added here are verified against the repository at `0630542` and cited.

**This sheet decides nothing.** It presents each decision so it can be answered. Every decision below is **OPEN**.

## How to complete it

**Status values:**
- **OPEN** — not yet decided.
- **DECIDED** — the owner has chosen.
- **DEFERRED** — postponed, with a target date and the gate it still blocks.
- **NOT APPLICABLE** — the feature or situation will not occur; record why.

**Never use PASS for a decision.**

**Each decision has the same response block:**

| Field | Meaning |
|---|---|
| Decision ID | The Stage 1 ID |
| Decision | The question to answer |
| Owner | Who decides (with whom) |
| Status | OPEN / DECIDED / DEFERRED / NOT APPLICABLE |
| Options | What the repository supports, with consequences |
| Selected option | **To complete** |
| Reason | **To complete** |
| Effective date | **To complete** |
| Evidence required | What must exist for the decision to count as taken |
| Blocks | S = staging, R = production-copy rehearsal, P = pilot (first production deployment), G = go-live to customers, F = only before enabling that feature |
| Dependencies | Decisions or facts it needs first |
| Notes | Facts that matter for the choice |

**After a decision:**
- **Record it in the phase register that owns it** (`docs/saas-*-decision-register.md` or `docs/production-readiness-decision-register.md`), naming who decided, when and what.
- **Engineering then converts it** into a setting, a runbook step or a code task. A decision that needs code goes back through Gate 0.

---

# PART 1 — TRANCHE 1A: INFRASTRUCTURE DECISIONS REQUIRED BEFORE STAGING

**None of these is decided.** No vendor is recommended: the repository requires none and documents none. Where the repository supports an approach, it is described with its consequence. Specifications are given only where the code fixes them.

## BU-O01 — Hosting provider and container runtime

| Context | |
|---|---|
| Exact decision | Where staging and production run, and which container runtime/orchestrator runs the image; whether production runs the repository's compose stack |
| Why it is required | Nothing can be built, run in a container, rehearsed or deployed without it. The compose release has never been executed (discovery A20) |
| Minimum application requirement | Runs the repository image (`Dockerfile`: PHP 8.5.11, Apache on port 80). Processes: one-shot `migrate` (`ops:migrate`), `app`, four queue workers, **exactly one** scheduler (`schedule:work`). Start order: migrate → app healthy (`/health/ready`) → workers healthy → scheduler. SIGTERM reaches workers with ≥ 330 s grace. A persistent volume for `storage/app` and `storage/logs` shared by every app-image container. MySQL 8.4 reachable |
| Option (a) — supported | **Docker Compose with `docker-compose.yml` as shipped.** Consequence: the runbooks apply as written; single host unless the volume is shared; Gate 5 must prove it (never executed) |
| Option (b) — supported | **Another orchestrator running the same image and topology.** Consequence: each `docker compose` step in the runbooks needs its equivalent (infrastructure to define); health checks, start order, grace periods and the shared volume must be reproduced; several hosts need a shared filesystem (files are local-disk only) |
| Not supported without change | A platform that cannot run long-running worker and scheduler processes; a platform without a persistent shared filesystem (object storage would need code: `PrivateFileController.php:42`) |
| Additional code change | (a) none; (b) none in the application (deployment manifests are infrastructure) |

| Field | Entry |
|---|---|
| Decision ID | BU-O01 |
| Decision | Hosting provider and container runtime/orchestrator for staging and production; compose stack or not |
| Owner | Project owner + infrastructure lead |
| Status | OPEN |
| Options | (a) Docker Compose as shipped · (b) another orchestrator reproducing the topology — see context above |
| Selected option | |
| Reason | |
| Effective date | |
| Evidence required | Decision record; Gate 2 inventory showing the image running with the topology above |
| Blocks | S, R, P, G |
| Dependencies | — |
| Notes | Stage 1 BU-I01–I05, I11–I13. D8.9-026 (topology unknown) |

## BU-O02 — Secrets manager and APP_KEY custody

| Context | |
|---|---|
| Exact decision | Which system holds staging and production secrets and injects them; who holds `APP_KEY`, and where it is backed up apart from data backups |
| Why it is required | Preflight blocks a production start without a valid `APP_KEY` and a non-default `DB_PASSWORD`. Encrypted columns, queued payloads, cookies and signed links depend on `APP_KEY`; a lost key cannot be recovered. The rehearsal needs production's key |
| Minimum application requirement | Secrets arrive as **environment variables at container start** (configuration is cached on start). Names: `APP_KEY` (+ `APP_PREVIOUS_KEYS` during a rotation), `DB_USERNAME`, `DB_PASSWORD`, `QUEUE_HEALTH_TOKEN`, `MAIL_USERNAME`, `MAIL_PASSWORD`, and provider secrets only for enabled features (AI, Twilio, WhatsApp, Google, Microsoft, Zoom). The same `APP_KEY` in every container; never regenerated |
| Option (a) — supported | **A secret manager or orchestrator mechanism that injects environment variables.** Consequence: no code change; custody, access control and audit are the tool's |
| Option (b) — supported | **The compose `.env` file on the host** (`env_file: .env`). Consequence: secrets in a host file; custody and access control are manual |
| Not supported without change | The application fetching secrets from a vault API at runtime |
| Additional code change | (a), (b) none; runtime vault fetching would be code |

| Field | Entry |
|---|---|
| Decision ID | BU-O02 |
| Decision | Secrets manager/injection mechanism; `APP_KEY` custodian and separate backup |
| Owner | Project owner + infrastructure lead + security |
| Status | OPEN |
| Options | (a) secret manager injecting environment variables · (b) compose `.env` on the host |
| Selected option | |
| Reason | |
| Effective date | |
| Evidence required | Decision record; `APP_KEY` custody record (custodian, storage, backup location — never the value); preflight `app_key` passes on staging |
| Blocks | S, R, P, G |
| Dependencies | BU-O01 (where secrets are injected) |
| Notes | D-S7-O1 ≈ D-S6-O7. Rotation procedure: `docs/runbooks/app-key-and-secret-rotation.md` |

## BU-O03 — Domains, DNS and TLS termination

| Context | |
|---|---|
| Exact decision | Staging and production host names; DNS; where TLS terminates; the proxy/load balancer and its addresses |
| Why it is required | Staging and production preflight block unless `APP_URL` is `https://` and `SESSION_SECURE_COOKIE=true`. Links, emails and signed URLs use `APP_URL` |
| Minimum application requirement | One public HTTPS host per environment = `APP_URL`. TLS terminated **in front of** the container (it serves HTTP on port 80 only). The proxy forwards `X-Forwarded-*`; its addresses go into `TRUSTED_PROXIES`. **`APP_TRUSTED_HOSTS` = the public host(s) + `localhost`** (else the compose healthcheck is refused — Stage 1 C-4). Staff panel, careers, portal, API and inbound webhooks are paths on that host. The calendar OAuth callback is built from `APP_URL` (only if calendars are enabled) |
| Option (a) — supported | **One host for everything** (as built). Consequence: none beyond the above |
| Option (b) — supported | **Extra host names pointing at the same application** (for example for the API). Consequence: add them to `APP_TRUSTED_HOSTS`; generated links and emails still use `APP_URL` (forced root URL outside `local`). Whether a separate API domain is wanted is BU-O46 |
| Not supported without change | TLS inside the container (the image's Apache vhost listens on port 80 only) |
| Additional code change | (a), (b) none; TLS in the container needs an image change |

| Field | Entry |
|---|---|
| Decision ID | BU-O03 |
| Decision | Host names, DNS, TLS termination point and proxy for staging and production |
| Owner | Project owner + infrastructure lead |
| Status | OPEN |
| Options | (a) one host for all paths · (b) additional host names for the same application |
| Selected option | |
| Reason | |
| Effective date | |
| Evidence required | DNS records; certificates; proxy configuration; preflight `app_url`, `session_secure`, `trusted_hosts_app_url` pass; container healthy |
| Blocks | S, P, G |
| Dependencies | BU-O01 |
| Notes | D-S7-O3 ≈ D8.10-023. Phase 8.11 also asks for Apache `ServerName` and a default reject vhost |

## BU-O04 — Network egress model

| Context | |
|---|---|
| Exact decision | Which outbound connections the application and workers may make, through which path; whether fixed source IPs are offered (for customers to allow-list) |
| Why it is required | Mail, providers and webhooks leave from the workers. S7-16 (proxy/LibreOffice) stays open without network controls. Webhook validation and the pen test's SSRF items need the real path |
| Minimum application requirement | Outbound HTTPS from app and workers to: the SMTP relay; AI providers if enabled; Twilio, Meta Graph, Google, Microsoft, Zoom if configured; tenant webhook URLs if webhooks are enabled; DNS. Build time: package registries and `fonts.bunny.net`. **Webhook delivery must not go through `HTTPS_PROXY`** (it bypasses DNS pinning) |
| Option (a) — supported | **Direct egress, application SSRF guard only** (as built). Consequence: S7-16 stays open; no network-level control |
| Option (b) — supported | **Egress firewall/allow-list, or NAT with fixed source IPs.** Consequence: no code change. Provider hosts must be allowed. Tenant webhook URLs are arbitrary public hosts: an allow-list either permits broad HTTPS, or webhooks cannot be enabled |
| Not supported without change | An outbound proxy for webhook delivery |
| Additional code change | (a), (b) none; proxy-routed webhooks would be code |

| Field | Entry |
|---|---|
| Decision ID | BU-O04 |
| Decision | Network egress model; fixed source IPs or not |
| Owner | Project owner + infrastructure lead + security |
| Status | OPEN |
| Options | (a) direct egress with the application SSRF guard · (b) egress firewall/allow-list or NAT with fixed IPs |
| Selected option | |
| Reason | |
| Effective date | |
| Evidence required | Egress rules document; staging test of SMTP and (if enabled) a webhook delivery through the real path |
| Blocks | S (webhook tests), P, G |
| Dependencies | BU-O01; BU-O49 if webhooks are enabled |
| Notes | D-S7-O2 = D-S6-O15. Isolating LibreOffice from the network also closes part of S7-16 |

## BU-O05 — Monitoring/error-tracking vendor + test alert mailbox

| Context | |
|---|---|
| Exact decision | Which tools watch uptime, errors, latency, database and queues; where alerts go; a test alert mailbox for staging |
| Why it is required | No monitoring exists (stop conditions 11–12). A production container refuses to start without `PLATFORM_NOTIFY_EMAIL`. Staging must prove an alert arrives (BU-V17) |
| Minimum application requirement | An external poller of `/health/live`, `/health/ready`, `/health/queue` (bearer `QUEUE_HEALTH_TOKEN`). A mailbox at `PLATFORM_NOTIFY_EMAIL`: critical platform events (silent worker or scheduler, failing scheduled task, failed purge) are mailed through SMTP. Log collection. MySQL metrics |
| Options supported by the repository | **Health endpoints:** any HTTP poller. **Log channels** (`config/logging.php`, chosen with `LOG_STACK`): `daily` (default, files under `storage/logs`), `monthly`, `single`, `stderr`, `syslog`, `errorlog`, `papertrail`, `slack`. **Email alerts** via `PLATFORM_NOTIFY_EMAIL`. **Queue metrics:** `/health/queue` JSON |
| Consequences | Log-based error detection relies on the log tool's own alerting. Webhook failure counts on `/health/queue` do not raise an alert (PR-05) |
| Additional code change | None for polling, log channels and email. A vendor error-tracking SDK is a dependency change |

| Field | Entry |
|---|---|
| Decision ID | BU-O05 |
| Decision | Monitoring and error-tracking tooling; log collection; staging test alert mailbox |
| Owner | Project owner + infrastructure lead |
| Status | OPEN |
| Options | HTTP polling of the health endpoints · a log channel above · email alerts · a vendor SDK (dependency change) |
| Selected option | |
| Reason | |
| Effective date | |
| Evidence required | Monitors configured for staging; BU-V17 record: a stopped worker produced a critical email at the test mailbox |
| Blocks | S, P, G |
| Dependencies | BU-O01; an SMTP relay (BU-I34); BU-O39 for production recipients |
| Notes | D-S7-O6 ≈ D8.9-019 |

## BU-O06 — Backup tool, location, key custody, RPO and RTO

| Context | |
|---|---|
| Exact decision | Backup method and tool; frequency; retention; off-host location; encryption key custody; RPO; RTO; DR approach; restore-test cadence; point-in-time recovery (binlog) or not |
| Why it is required | No backup system exists. The release procedure forbids migrating without a verified backup. 25 forward-only migrations make the backup the only rollback. The rehearsal's source is a backup |
| Minimum application requirement | Database and the storage volume backed up **at the same point in time**; a consistent database snapshot (InnoDB transactional); encrypted; off-host; `APP_KEY` stored apart; restore into an **empty** database; verification per `backup-restore-verification.md` |
| Option (a) — supported | **The documented procedure** (`backup-restore.md` §2: `mysqldump --single-transaction …`, `tar`, `gpg`, `sha256sum`), run by an infrastructure scheduler. Consequence: no code change; scripted or manual operation |
| Option (b) — supported | **Any backup tool giving the same guarantees** (consistent database + same-point files). Consequence: no code change |
| Option (c) — supported | **Binary-log point-in-time recovery** in addition. Consequence: an RPO below the backup interval; infrastructure settings (`log_bin`, expiry) |
| Additional code change | None |

| Field | Entry |
|---|---|
| Decision ID | BU-O06 |
| Decision | Backup method, frequency, retention, location, key custody; RPO; RTO; DR; restore-test cadence; PITR |
| Owner | Project owner + infrastructure lead + DBA (retention with Legal) |
| Status | OPEN |
| Options | (a) documented procedure · (b) an equivalent tool · (c) binlog PITR in addition |
| Selected option | |
| Reason | |
| Effective date | |
| Evidence required | Policy record with every value above; the first verified backup; a restore record marked VERIFIED |
| Blocks | S (staging restore test), R, P, G |
| Dependencies | BU-O01, BU-O02 (key custody) |
| Notes | D-S7-O7 = D8.9-007/008/009a–d/010/028; P810-OP-06 (binlog). Backup retention also falls under BU-O37 |

## BU-O07 — CI runner, image registry and deployment strategy

| Context | |
|---|---|
| Exact decision | Which CI runs tests and builds images; where images are stored for rollback by tag; how releases are executed |
| Why it is required | No CI exists; the image has never been built; rollback by tag needs a registry; the regression must be repeated on the released commit |
| Minimum application requirement | **CI toolchain:** PHP 8.5, Composer, Node ≥ 20.19 or ≥ 22.12, a MySQL 8.4 service. **It runs:** the SQLite full suite, the MySQL full suite, the concurrency suite (`phpunit.concurrency.xml`), `composer audit`, `npm audit`, and the image build. **Full runs outside 18:30–24:00 UTC** (10 date-sensitive tests). **Images:** tagged by commit, previous tags retained |
| Deployment strategy supported | **Drained maintenance window** (`queue-operations.md` §1): stop intake, stop the scheduler, drain, stop workers, back up, migrate, start, verify, reopen |
| Not supported for this release | Blue/green or rolling deployment with old and new code side by side. The 25 forward-only migrations mean the previous image must never run on the new schema |
| Additional code change | None in the application. A CI definition file would be a new repository file (needs approval) |

| Field | Entry |
|---|---|
| Decision ID | BU-O07 |
| Decision | CI runner, image registry, deployment strategy |
| Owner | Project owner + infrastructure lead + release owner |
| Status | OPEN |
| Options | CI tool (any able to run the toolchain above) · registry (any retaining tags) · strategy: drained window (the only one supported for this release) |
| Selected option | |
| Reason | |
| Effective date | |
| Evidence required | A CI run on the frozen commit (all suites green); the image digest in the registry; the pipeline definition |
| Blocks | S, P, G |
| Dependencies | BU-O01 |
| Notes | D-S7-O12 ≈ D8.10-005, D8.9-022/023, P810-OP-09 |

## BU-O08 — Database sizing for staging and production

| Context | |
|---|---|
| Exact decision | The MySQL instances for staging and production: managed or self-hosted, size, storage, `max_connections`, buffer pool, binlog, replicas |
| Why it is required | Preflight requires MySQL; the rehearsal needs production's configuration; the load test needs a production-like database |
| Minimum application requirement | **Engine:** MySQL **8.4**; utf8mb4/utf8mb4_unicode_ci; strict mode; InnoDB; `GET_LOCK`. **Users:** a least-privilege application user; a separate user able to `CREATE TRIGGER` if BU-O38 chooses triggers. **Time zone:** per BU-O16. **Connections:** each PHP process may hold **two** (`mysql` and `mysql_cache`), so `max_connections` must exceed 2 × (web processes + 4 workers + scheduler + migrate) plus administration. **Data size:** from BU-O15 — the repository holds no production sizing figure |
| Option (a) — supported | **A single primary** (as built) |
| Not supported without change | A read replica: the code has no read/write split configured, so using one needs configuration and validation |
| Consequences | A managed service must permit the privileged trigger user if BU-O38 chooses triggers. Sizing without BU-O15's facts is a guess; Gate 6 measures it |
| Additional code change | None for a single primary; configuration and validation for a replica |

| Field | Entry |
|---|---|
| Decision ID | BU-O08 |
| Decision | Staging and production MySQL instance type, size and configuration |
| Owner | Project owner + infrastructure lead + DBA |
| Status | OPEN |
| Options | Managed or self-hosted single MySQL 8.4 primary meeting the minimum above; a replica only with a configuration change |
| Selected option | |
| Reason | |
| Effective date | |
| Evidence required | Instance specification record; `SELECT VERSION()` and `SHOW VARIABLES` output; preflight `database_driver` passes; Gate 6 results |
| Blocks | S, R, P, G |
| Dependencies | BU-O01; BU-O15 (sizes); BU-O38 (privileged user) |
| Notes | D-S7-O13 ≈ D8.9-013; Phase 8.11 OP-05 (Apache workers vs `max_connections`) |

---

# PART 2 — TRANCHE 1B: RELEASE DECISIONS (GATE 0)

## The two documented release orders

The SaaS-1 decision register (D-S1-O1, carried into D-S2-O5) records the question:

- **A. Phase 8.11 first, then SaaS-1 through SaaS-7.**
- **B. One combined release.**

It also records an engineering recommendation. **That recommendation is not a decision, and this sheet does not choose.**

| Consequence for | A. Phase 8.11 first, then SaaS-1…7 | B. Combined release |
|---|---|---|
| Releases | Two: R1 `9cba8e3` → Phase 8.11 candidate **`226bc7d`**; R2 → SaaS candidate (`95f85d5` code, tested `cf9082c`) | One: `9cba8e3` → SaaS candidate |
| Migrations | R1: **89** (75 → 164), **17 forward-only** (Phases 4–8.5: permission grants, data changes, two destructive schema changes). R2: **15** (164 → 179), **8 forward-only** (tenant backfill, ownership enforcement, membership move, user-column contraction, legacy plan, billing grants, platform expansion, support-grant backfill) | **104** (75 → 179), **25 forward-only** |
| Production-copy rehearsal | Two: R1 from today's production; R2 from production as R1 left it | One, covering all 104 |
| Regression testing | R1 needs a complete regression on `226bc7d` under the current rules. **Its last record (2026-10-04):** SQLite 2,136 / 2,136, but **8 MySQL-only test failures**, classed pre-existing (P810-RC-01/02). P810-RC-01, a Low product defect in automation configuration on MySQL, was fixed only on the SaaS line (`0e8d865`). R2's regression exists (`cf9082c`) | The regression exists (`cf9082c`: SQLite 2,793 / 2,793; MySQL 2,792 passed + 1 skipped; concurrency 74 / 74) |
| Rollback | Each release has its own pre-release backup and restore. Rolling back R2 returns to Phase 8.11, not to `9cba8e3` | One restore returns to `9cba8e3`; the larger delta means a larger rollback |
| Deployment | Two maintenance windows and two drained releases. **R1 needs Gate 1a infrastructure, its own backup and its own facts, but none of the SaaS pre-pilot decisions** (Tenant #1 values and owner, billing, operators, retention) | One window. All SaaS pre-pilot decisions are needed before it |
| Pilot | R1 is not a SaaS pilot: it is a single-organisation upgrade. The SaaS pilot happens at R2 | The release is the pilot |
| Production Critical (Part 3) | Closed at R1 | Closed at the single release |

**Within A**, the Phase 8.11 record offers its own choice for R1 (`docs/phase-8-11-production-release.md` §2): deploy candidate `226bc7d`, or deploy only the Release A hotfix (`2fab3fd` + `599f0c5`). The hotfix closes the production Critical but leaves SEC-001/004/015 and DI-01/02/04 open in production. It also cannot be built from its own Dockerfile (PHP 8.3 against a lock needing ≥ 8.4.1, D8.10-021). If A is chosen, BU-O13 must record which.

## BU-O13 — Release line and commit

| Field | Entry |
|---|---|
| Decision ID | BU-O13 |
| Decision | Which code line production will run, and the exact commit(s) to release; review and merge of the `feature/saas-*` branches into that line |
| Owner | Project owner + release owner |
| Status | OPEN |
| Options | **Under A:** R1 = `226bc7d` (or the Release A hotfix only, per Phase 8.11 §2), then R2 = the SaaS candidate. **Under B:** the SaaS candidate (`95f85d5` code; tested as `cf9082c`). Either way: which branch receives the merge (`main`, `production`) — nothing is merged today |
| Selected option | |
| Reason | |
| Effective date | |
| Evidence required | Decision record naming the commit hash(es); a complete regression on each released commit (`cf9082c` exists for the SaaS candidate) |
| Blocks | R, P, G |
| Dependencies | BU-O14; BU-O15 (what production runs today) |
| Notes | PRD-01 = D8.9-027 = D8.10-002/003/021; G-09. The remote holds `main` = `production` = `test` = `9cba8e3`; none of the later work is pushed |

## BU-O14 — Release order

| Field | Entry |
|---|---|
| Decision ID | BU-O14 |
| Decision | Release Phase 8.11 first and SaaS-1…7 later (A), or one combined release (B) |
| Owner | Project owner + release owner |
| Status | OPEN |
| Options | A. Phase 8.11 first, then SaaS-1…7 · B. Combined release — consequences in the table above |
| Selected option | |
| Reason | |
| Effective date | |
| Evidence required | Decision record; the rehearsal plan(s) and regression run(s) matching the choice |
| Blocks | R, P, G |
| Dependencies | BU-O15 |
| Notes | D-S1-O1 = D-S2-O5 |

## BU-O15 — Production facts

These must be supplied **read-only, without secret values**. The list is `docs/phase-8-10-release-readiness.md` §6, plus four SaaS additions.

| # | Fact | How (read-only) |
|---|---|---|
| 1 | Deployed commit or image tag | Running image tag, or `git rev-parse HEAD` on the host |
| 2 | Migration state | `php artisan migrate:status`, or `SELECT migration, batch FROM migrations ORDER BY id` |
| 3 | Schema | `mysqldump --no-data --skip-comments --no-tablespaces <db>` |
| 4 | Table sizes | `SELECT table_name, table_rows, data_length, index_length FROM information_schema.tables WHERE table_schema = DATABASE()` |
| 5 | Core counts | `SELECT COUNT(*)` on `candidates`, `candidate_applications`, `recruitment_requisitions`, `employees`, `users` |
| 6 | Audit volume | `SELECT COUNT(*), MIN(created_at) FROM audit_logs` |
| 7 | Document volume | `SELECT COUNT(*) FROM candidate_documents`; offer letters; `du -sh` of the storage volume |
| 8 | Queue backlog | `SELECT queue, COUNT(*), MIN(available_at) FROM jobs GROUP BY queue`; `SELECT COUNT(*) FROM failed_jobs` |
| 9 | Roles and permissions | `SELECT name FROM roles`; `SELECT name FROM permissions` |
| 10 | Database version and configuration | `SELECT VERSION()`; `SHOW VARIABLES` for `innodb_buffer_pool_size`, `max_connections`, `log_bin`, `binlog_expire_logs_seconds`, `gtid_mode`, `sql_mode` |
| 11 | **Database time zone (PR-02)** | `SELECT @@global.time_zone, @@session.time_zone, @@system_time_zone` |
| 12 | PHP and runtime | `php -v`; `php -m`; how PHP is installed |
| 13 | Container runtime state | Runtime and orchestrator versions; running services; image tags; how images are built and deployed |
| 14 | Configuration names (no values) | `QUEUE_CONNECTION`, `CACHE_STORE`, `SESSION_DRIVER`, `APP_MAINTENANCE_DRIVER`, `QUEUE_EXPECT_PROCESSES`, `APP_ENV`, `APP_DEBUG`; the front end (proxy or TLS terminator) |
| 15 | **`APP_KEY` custody** | Whether production's `APP_KEY` exists and who holds it — never the value |
| 16 | **Seeded default admin (TD-002)** | Whether the account that `database/seeders/AdminUserSeeder.php` creates exists in production, and whether its password was changed (yes/no only) |
| 17 | **Backups existing today** | Whether any backup of production exists, where, and whether it was ever restored |

| Field | Entry |
|---|---|
| Decision ID | BU-O15 |
| Decision | Provide the production facts above |
| Owner | Infrastructure/DBA (supplies), release owner (accepts) |
| Status | OPEN |
| Options | Supply all facts · supply a subset (any missing fact keeps the rehearsal from being production-representative) |
| Selected option | |
| Reason | |
| Effective date | |
| Evidence required | A dated facts sheet with each item, collected read-only |
| Blocks | R, P, G |
| Dependencies | Production access by the infrastructure owner |
| Notes | D8.9-026. If production does not run `9cba8e3`, the release delta changes (Stage 1 §1.1) |

## BU-O20 — Tenant #1 values (`TENANT_ONE_*`)

The backfill migration reads these **once**, when it turns the existing organisation into Tenant #1. **The slug appears in every staff, careers and portal URL** (`/admin/{slug}`, `/careers/{slug}`, `/portal/{slug}`).

| Variable | Default in code (`config/tenancy.php`) | Rule |
|---|---|---|
| `TENANT_ONE_SLUG` | `main` | Use the same rule provisioning enforces: 3–63 lower-case letters, digits and hyphens, starting and ending with a letter or digit. Not `admin`, `portal`, `careers`, `invitations`, `organisations`, `api`, `platform`, `webhooks`, `files`, `health`, `livewire`. **The backfill migration does not check this** (`ProvisioningRequest.php:27-32`) |
| `TENANT_ONE_NAME` | `APP_COMPANY_NAME`, else `APP_NAME`, else "Recruitment Edge" | Non-empty |
| `TENANT_ONE_LEGAL_NAME` | none | Optional |
| `TENANT_ONE_TIMEZONE` | `METRICS_BUSINESS_TIMEZONE`, else `Asia/Kolkata` | An IANA time-zone name |
| `TENANT_ONE_LOCALE` | `en` | — |
| `TENANT_ONE_CURRENCY` | `INR` | A currency code |
| `TENANT_ONE_COUNTRY` | `IN` | A country code |

| Field | Entry |
|---|---|
| Decision ID | BU-O20 |
| Decision | The seven `TENANT_ONE_*` values |
| Owner | Project owner |
| Status | OPEN |
| Options | Keep each default · set each value (table above) |
| Selected option | |
| Reason | |
| Effective date | |
| Evidence required | The values recorded; set in the rehearsal environment and then in production before the migration |
| Blocks | R, P, G |
| Dependencies | BU-O14 (the backfill runs in the SaaS release) |
| Notes | D-S1-O2 = PRD-02. Changing the slug after the migration is not a documented operation |

---

# PART 3 — SECURITY DECISIONS

## Security status, as corrected

| Severity | Open | Notes |
|---|---|---|
| **Critical** | **1 on the production line** (if it runs `9cba8e3`); 0 in the release candidate | See SEC-PROD below. Stage 1's "Critical 0" counted the release candidate only |
| **High** | **1: SEC-88-02 (HIGH — OPEN)**; 0 other | Stage 1 corrected the earlier "High 0" (C-1). Preserved here |
| Medium | 1: S7-12 (audit triggers; BU-O38) | — |
| Low | 4: S7-13, S7-15, S7-16, PR-02 | — |
| Info | 6: S7-14, S7-25, PR-01, PR-04, PR-05, PR-06 | — |

## SEC-88-02 — Candidate personal data has no retention, erasure or anonymisation path

**Status: HIGH — OPEN.** The owner deferred it on 2026-10-01 (decision R-14, class C). The deferral leaves it open and High. Not downgraded, not closed.

| Risk record | Entry |
|---|---|
| Issue | Candidate personal data has no retention, erasure or anonymisation path (`docs/phase-8-8-retention-decision.md`) |
| Owner decision | **To complete:** reconfirm the deferral for customer go-live, or set retention and erasure rules (R-1…R-13) |
| Risk acceptance? | **To complete** (yes/no; who accepts; until when) |
| Required control | Retention periods per data class, an erasure/anonymisation path, legal hold — **code work** once Legal decides the rules |
| Target date | **To complete** |
| Blocking gate | G (customer go-live); pilot only if the pilot handles external candidates' data without the owner's acceptance |
| Evidence required | Signed risk acceptance with an end date, **or** the retention decision plus the implemented control with tests |

| Field | Entry |
|---|---|
| Decision ID | SEC-88-02 (under BU-O37) |
| Decision | Accept the risk until a date, or require the control before go-live |
| Owner | Project owner + Legal + security |
| Status | OPEN |
| Options | Continue the deferral with a dated risk acceptance · require retention/erasure before go-live (code work after the rules are decided) |
| Selected option | |
| Reason | |
| Effective date | |
| Evidence required | As in the risk record |
| Blocks | G |
| Dependencies | BU-O37 (retention rules); R-1…R-13 |
| Notes | Earlier references: P88-BACKLOG-001; D8.8-008…012, 031…033 |

## SEC-PROD — Critical exposure on the current production line

**Status: CRITICAL — OPEN on the production line, if production runs `9cba8e3`** (UNKNOWN until BU-O15 fact 1). The issue is the Phase 8.6 delete-authorization gap (SEC-1) and SEC-86-I-01 (P89-OPS-012). It is closed in the SaaS candidate, in the Phase 8.11 candidate `226bc7d`, and in the hotfix `599f0c5` (PR-03: hotfix tests pass 6 / 6 on the candidate). **It stays live in production until one of them is deployed** (`docs/rms-final-release-candidate.md:107`; `docs/phase-8-10-security-review.md` §5).

| Risk record | Entry |
|---|---|
| Owner decision | **To complete:** which release closes it and when (follows from BU-O13 / BU-O14) |
| Risk acceptance? | **To complete:** the exposure continues until that release |
| Required control | Deploy a line that contains the fix |
| Target date | **To complete** |
| Blocking gate | None of S/R/P/G (it exists in production today); its closure date depends on R1 (option A) or the single release (option B) |
| Evidence required | BU-O15 fact 1 (what runs); the release record of the closing deployment |

## Other security decisions (response blocks in Parts 4 and 5)

| Topic | Decision | Where |
|---|---|---|
| AI key rotation confirmation | BU-O43 (P89-OPS-015 = P7-FRZ-1) | Part 4 |
| Seeded/default admin password exclusion | BU-O44 (TD-002) | Part 4 |
| Audit immutability | BU-O38 (S7-12, Medium) | Part 4 |
| Previous-key retention after a compromise | BU-O41 (PRD-12) | Part 5 |
| Incident notification and severity | BU-O40 (PRD-13; D8.9-021) | Part 4 |
| Alert recipient and on-call | BU-O39 (PRD-09) | Part 4 |

---

# PART 4 — TRANCHE 1C: PILOT DECISIONS

## BU-O16 — Database session time zone

| Field | Entry |
|---|---|
| Decision ID | BU-O16 |
| Decision | The MySQL session time zone for production |
| Owner | Project owner + DBA |
| Status | OPEN |
| Options | `DB_TIMEZONE=+00:00` · the server's default zone set to UTC · keep the server's zone and accept that database-side `NOW()` differs from application UTC. Changing it on existing data changes how the 448 TIMESTAMP columns read |
| Selected option | |
| Reason | |
| Effective date | |
| Evidence required | Rehearsal TIMESTAMP comparison (Gate 4); preflight `db_timezone` and the integrity check's offset after the change |
| Blocks | P, G |
| Dependencies | Gate 4 rehearsal; BU-O15 fact 11 |
| Notes | PRD-04 (PR-02). The application zone is fixed at UTC |

## BU-O17 — Maintenance, rollback-decision and watch windows

| Field | Entry |
|---|---|
| Decision ID | BU-O17 |
| Decision | Length and timing of the maintenance window; the rollback-decision window; the post-release watch window |
| Owner | Project owner + release owner |
| Status | OPEN |
| Options | Values set from the rehearsal's measured durations |
| Selected option | |
| Reason | |
| Effective date | |
| Evidence required | Rehearsal timings (Gate 4) and dry-run timings (Gate 5) |
| Blocks | P, G |
| Dependencies | Gates 4 and 5 |
| Notes | PRD-10 ≈ D8.9-023. A release with migrations is a drained maintenance window |

## BU-O18 — Smoke tests through a maintenance bypass

| Field | Entry |
|---|---|
| Decision ID | BU-O18 |
| Decision | Whether testers smoke-test behind a maintenance bypass before reopening |
| Owner | Release owner |
| Status | OPEN |
| Options | Use `php artisan down --retry=60 --secret=<random>` (testers open `/<random>` once) · no bypass: smoke-test right after reopening |
| Selected option | |
| Reason | |
| Effective date | |
| Evidence required | Release checklist entry |
| Blocks | P, G |
| Dependencies | — |
| Notes | PRD-11. The documented drain does not use a bypass |

## BU-O19 — Old links and onboarding communication

| Field | Entry |
|---|---|
| Decision ID | BU-O19 |
| Decision | What happens to pre-SaaS links (they gain the tenant slug); how staff learn about invitations and self-set passwords |
| Owner | Project owner |
| Status | OPEN |
| Options | Old links: accept 404s · a reviewed redirect for Tenant #1 (code work). Communication: release notes and an HR guide (recorded recommendation), or another channel |
| Selected option | |
| Reason | |
| Effective date | |
| Evidence required | Release notes; redirect tests if chosen |
| Blocks | P, G |
| Dependencies | BU-O20 (the slug) |
| Notes | D-S1-O3; D-S2-O2 |

## BU-O21 — Tenant #1 owner

| Field | Entry |
|---|---|
| Decision ID | BU-O21 |
| Decision | Who owns Tenant #1 |
| Owner | Project owner |
| Status | OPEN |
| Options | Any active member of Tenant #1 who holds the CHRO role |
| Selected option | |
| Reason | |
| Effective date | |
| Evidence required | `php artisan tenants:owner <slug> <email> --operator=<admin> --reason=…` output; audit `ownership_assigned`; `ops:verify-integrity` no longer warns `identity.usable_tenant_without_active_owner` |
| Blocks | P, G |
| Dependencies | BU-O20; BU-O33 (an operator runs the command) |
| Notes | PRD-03. The migration sets no owner |

## BU-O22 — Tenant-wide MFA

| Field | Entry |
|---|---|
| Decision ID | BU-O22 |
| Decision | Require MFA for every member of Tenant #1 from day one |
| Owner | Project owner + security |
| Status | OPEN |
| Options | Yes (tenant setting) · no (privileged roles and operators are already enforced) |
| Selected option | |
| Reason | |
| Effective date | |
| Evidence required | Tenant setting recorded; a member without MFA is sent to enrolment |
| Blocks | P, G |
| Dependencies | — |
| Notes | D-S2-O4 |

## BU-O24 — Tenant #1 branding, billing contact, support policy

| Field | Entry |
|---|---|
| Decision ID | BU-O24 |
| Decision | Tenant #1's branding, billing contact and support-access stance |
| Owner | Project owner |
| Status | OPEN |
| Options | Keep the configured values · set them |
| Selected option | |
| Reason | |
| Effective date | |
| Evidence required | Tenant detail shows the values |
| Blocks | P, G |
| Dependencies | BU-O21; BU-O34 |
| Notes | Discovery A27 |

## BU-O25 — Billing at launch

| Field | Entry |
|---|---|
| Decision ID | BU-O25 |
| Decision | How billing works at the pilot and at launch |
| Owner | Project owner + Finance |
| Status | OPEN |
| Options | **No billing** for the pilot. **Manual or contract billing:** works without a provider (`billing:subscription … --source=manual\|contract`; `billing:payment record …`). **An online payment provider:** none exists — the fake adapter is refused outside `local`/`testing` — so this needs an adapter, webhook verification, tests and a runbook (code work) |
| Selected option | |
| Reason | |
| Effective date | |
| Evidence required | Decision record; staging validation of the chosen path (BU-V12) |
| Blocks | P, G |
| Dependencies | BU-O26 (prices) for any billed customer |
| Notes | PRD-06; D-S4-O1 |

## BU-O31 — Billing contacts and operators

| Field | Entry |
|---|---|
| Decision ID | BU-O31 |
| Decision | Billing contact rules; who operates `billing:*` in production |
| Owner | Project owner + Finance |
| Status | OPEN |
| Options | Contact: one active member (default) · other rules. Operators: platform administrators (default) · named finance operators (recorded recommendation: two) |
| Selected option | |
| Reason | |
| Effective date | |
| Evidence required | Names recorded; platform roles granted |
| Blocks | P (if billed), G |
| Dependencies | BU-O25; BU-O33 |
| Notes | D-S4-O10; D-S4-O11 |

## BU-O33 — Platform operators

| Field | Entry |
|---|---|
| Decision ID | BU-O33 |
| Decision | Who the platform operators are (administrator, support, compliance roles), and who may run `platform:operator`, `identity:disable`, `plans:*`, `tenants:*` |
| Owner | Project owner + security |
| Status | OPEN |
| Options | Named people per role (recorded recommendation: two named administrators) |
| Selected option | |
| Reason | |
| Effective date | |
| Evidence required | `php artisan platform:operator list` output; MFA enrolled for each operator |
| Blocks | P, G |
| Dependencies | — |
| Notes | D-S1-O5 = D-S2-O1 = D-S5-O7; D-S3-O6 |

## BU-O34 — Support access

| Field | Entry |
|---|---|
| Decision ID | BU-O34 |
| Decision | Maximum support-access duration; whether the tenant always approves; break-glass for incidents |
| Owner | Project owner + security |
| Status | OPEN |
| Options | Keep defaults (480 min; requests lapse after 72 h; tenant approves; no break-glass) · change them |
| Selected option | |
| Reason | |
| Effective date | |
| Evidence required | Settings recorded; support workflow validated on staging (BU-V13) |
| Blocks | P, G |
| Dependencies | BU-O33 |
| Notes | D-S5-O1; D-S5-O2 |

## BU-O35 — Ownership transfer

| Field | Entry |
|---|---|
| Decision ID | BU-O35 |
| Decision | Who may request an ownership transfer, and what evidence is needed |
| Owner | Project owner |
| Status | OPEN |
| Options | Keep default (platform administrators, with a reason; new owner an active CHRO) · change it |
| Selected option | |
| Reason | |
| Effective date | |
| Evidence required | Policy recorded |
| Blocks | P, G |
| Dependencies | BU-O33 |
| Notes | D-S5-O3 |

## BU-O38 — Audit immutability

| Field | Entry |
|---|---|
| Decision ID | BU-O38 |
| Decision | Install the database triggers that make `audit_logs` append-only, or accept application-only immutability |
| Owner | Project owner + security |
| Status | OPEN |
| Options | **Install** `php artisan audit:protect install`. Needs a privileged database user (BU-O08); closes S7-12. **Accept application-only**, with a dated risk acceptance; S7-12 (Medium) stays open. **External log shipping** of the audit trail (infrastructure) |
| Selected option | |
| Reason | |
| Effective date | |
| Evidence required | `php artisan audit:protect status` = installed, **or** a signed acceptance |
| Blocks | P, G |
| Dependencies | BU-O08 |
| Notes | PRD-05 = D-S7-O8 = D-S5-O10 |

## BU-O39 — Alert recipient and on-call

| Field | Entry |
|---|---|
| Decision ID | BU-O39 |
| Decision | The `PLATFORM_NOTIFY_EMAIL` address; on-call people and rota; escalation |
| Owner | Project owner + infrastructure lead |
| Status | OPEN |
| Options | An operations mailbox · vendor routing (BU-O05) · named people with a rota |
| Selected option | |
| Reason | |
| Effective date | |
| Evidence required | A valid address (production preflight blocks without it); a test alert received by the on-call (BU-V17) |
| Blocks | S (test mailbox), P, G |
| Dependencies | BU-O05; SMTP (BU-I34) |
| Notes | PRD-09 = D-S5-O8 = D-S7-O6 ≈ D8.9-020/025 |

## BU-O40 — Incident notification and severity

| Field | Entry |
|---|---|
| Decision ID | BU-O40 |
| Decision | When and how tenants, individuals and authorities are notified of an incident; the incident severity model |
| Owner | Project owner + Legal + security |
| Status | OPEN |
| Options | To be defined by the owner, Legal and Security. The runbooks record the facts needed and invent no duty |
| Selected option | |
| Reason | |
| Effective date | |
| Evidence required | A written policy referenced by `security-incident.md` |
| Blocks | P, G |
| Dependencies | — |
| Notes | PRD-13; D8.9-021 |

## BU-O43 — AI production policy and key rotation

| Field | Entry |
|---|---|
| Decision ID | BU-O43 |
| Decision | Whether AI is enabled in production, under which provider terms, region and models; **confirmation that the previously exposed AI key was rotated and the old key revoked** |
| Owner | Project owner + security |
| Status | OPEN |
| Options | AI stays disabled (keys unset; the application degrades to "not configured") · enable after provider terms are approved. Separately: confirm the rotation (yes/no, date, by whom) |
| Selected option | |
| Reason | |
| Effective date | |
| Evidence required | Rotation confirmation from the provider console (date, by whom); provider terms record if enabling |
| Blocks | P (rotation confirmation), G |
| Dependencies | — |
| Notes | D8.10-014; P89-OPS-015 = P7-FRZ-1 |

## BU-O44 — Seeded default admin password

| Field | Entry |
|---|---|
| Decision ID | BU-O44 |
| Decision | Confirm the development seeder's account and default password never exist in production, and how that is checked |
| Owner | Project owner + infrastructure/DBA |
| Status | OPEN |
| Options | Verify absence (or a changed password) via BU-O15 fact 16 · remove or disable the account (`identity:disable` exists) if present |
| Selected option | |
| Reason | |
| Effective date | |
| Evidence required | BU-O15 fact 16 recorded; the rehearsal copy checked the same way |
| Blocks | R, P, G |
| Dependencies | BU-O15 |
| Notes | TD-002. The seeder is `database/seeders/AdminUserSeeder.php` |

## BU-O52 — Scale, SLOs and load-test criteria

| Field | Entry |
|---|---|
| Decision ID | BU-O52 |
| Decision | Supported scale (tenants, staff concurrency); page and queue SLOs; load-test pass criteria; soak duration |
| Owner | Project owner + release owner |
| Status | OPEN |
| Options | Adopt the repository's example criteria (capacity plan §6: p95 API < 300 ms; no queue older than 15 min; no `db.slow_query` above 1 s) · set other values. **The staff-concurrency figure has no default anywhere** |
| Selected option | |
| Reason | |
| Effective date | |
| Evidence required | Criteria recorded before Gate 6 |
| Blocks | P, G (Gate 6 precedes the pilot) |
| Dependencies | BU-O15 (data volumes) |
| Notes | D8.9-001…006, D8.9-011, D-S7-O13 |

## BU-O53 — Pilot tenant and sign-off owner

| Field | Entry |
|---|---|
| Decision ID | BU-O53 |
| Decision | Which internal tenant pilots the release, and who signs it off |
| Owner | Project owner |
| Status | OPEN |
| Options | An internal tenant named by the owner (no customer is chosen by this sheet) |
| Selected option | |
| Reason | |
| Effective date | |
| Evidence required | Tenant slug and sign-off owner recorded |
| Blocks | P |
| Dependencies | — |
| Notes | PRD-07; discovery A28 |

## BU-O54 — Pilot acceptance criteria and duration

| Field | Entry |
|---|---|
| Decision ID | BU-O54 |
| Decision | The pilot's duration and the criteria for accepting it |
| Owner | Project owner + pilot sign-off owner |
| Status | OPEN |
| Options | Values for each heading: critical hiring flows complete end to end; no Sev-1/Sev-2 incident (severity per BU-O40); integrity and tenancy checks clean daily; alert path and a restore proven; sign-off. Duration: a number of days or weeks |
| Selected option | |
| Reason | |
| Effective date | |
| Evidence required | Criteria and duration recorded before the pilot starts |
| Blocks | P |
| Dependencies | BU-O40; BU-O53 |
| Notes | No criteria or duration exist anywhere in the repository |

---

# PART 5 — TRANCHE 1D: GO-LIVE AND COMMERCIAL DECISIONS

## BU-O23 — Tenant #1 legacy plan transition

| Field | Entry |
|---|---|
| Decision ID | BU-O23 |
| Decision | When, and to which plan, Tenant #1 leaves `legacy` |
| Owner | Project owner + Finance |
| Status | OPEN |
| Options | Stay on `legacy` (every feature, unlimited; no API/webhooks) · move to a catalog plan (`tenants:plan <slug> <plan> --reason=`) on a date |
| Selected option | |
| Reason | |
| Effective date | |
| Evidence required | Plan assignment recorded |
| Blocks | G |
| Dependencies | BU-O26 |
| Notes | D-S3-O5 |

## BU-O26 — Plan catalog, prices, currencies

| Field | Entry |
|---|---|
| Decision ID | BU-O26 |
| Decision | Which plans exist, what each includes, their prices and currencies |
| Owner | Project owner + Finance |
| Status | OPEN |
| Options | Adopt, change or replace the development catalog (`starter`/`growth`/`enterprise`); prices per plan, currency and interval (none seeded); currencies (default INR and USD) |
| Selected option | |
| Reason | |
| Effective date | |
| Evidence required | Catalog and price list recorded; `plans:sync` / `billing:price` applied on staging |
| Blocks | G |
| Dependencies | BU-O25 |
| Notes | D-S3-O1; D-S4-O2; D-S4-O9 |

## BU-O27 — Trial policy

| Field | Entry |
|---|---|
| Decision ID | BU-O27 |
| Decision | Trial length, what happens at its end (grace, read-only), whether a payment method is collected, whether billing starts automatically |
| Owner | Project owner + Finance |
| Status | OPEN |
| Options | Keep defaults (access closes, data kept; no payment method; first invoice at trial end; length set at provisioning, 1–90 days; recorded recommendation 14 days) · change them |
| Selected option | |
| Reason | |
| Effective date | |
| Evidence required | Policy recorded |
| Blocks | G |
| Dependencies | BU-O25 |
| Notes | D-S3-O3; D-S4-O3 |

## BU-O28 — Cancellation, plan change, proration, refunds, dunning

| Field | Entry |
|---|---|
| Decision ID | BU-O28 |
| Decision | Cancellation timing; plan-change rules; proration; refund policy; dunning (grace, retries, notices) |
| Owner | Project owner + Finance |
| Status | OPEN |
| Options | Keep defaults (period end by tenant, immediate by platform; **no proration**; platform-only refunds; 7-day grace, retry every 24 h, in-app notice) · change them (some changes are code work, e.g. proration) |
| Selected option | |
| Reason | |
| Effective date | |
| Evidence required | Policy recorded; settings applied |
| Blocks | G |
| Dependencies | BU-O25 |
| Notes | D-S4-O4; D-S4-O5; D-S4-O6 |

## BU-O29 — GST / tax

| Field | Entry |
|---|---|
| Decision ID | BU-O29 |
| Decision | Tax treatment of invoices (GSTIN, place of supply, rates, e-invoicing) |
| Owner | Project owner + Finance (with a tax adviser) |
| Status | OPEN |
| Options | Defined by Finance. Today no tax is calculated; tax fields and the customer's tax id exist. Calculation is code work |
| Selected option | |
| Reason | |
| Effective date | |
| Evidence required | Tax decision recorded; implementation tested |
| Blocks | G (before the first Indian invoice) |
| Dependencies | BU-O25; BU-O30 |
| Notes | D-S4-O7 |

## BU-O30 — Invoice numbering

| Field | Entry |
|---|---|
| Decision ID | BU-O30 |
| Decision | Invoice number format and series |
| Owner | Finance |
| Status | OPEN |
| Options | Keep default (one global series `RE/{FY}/{n}`, financial year from April; `BILLING_INVOICE_PREFIX`, `BILLING_INVOICE_YEAR_STARTS_MONTH`) · change prefix/year start · separate series per entity or GSTIN (code work) |
| Selected option | |
| Reason | |
| Effective date | |
| Evidence required | Accountant confirmation recorded |
| Blocks | G |
| Dependencies | BU-O29 |
| Notes | D-S4-O8 |

## BU-O32 — Limit semantics

| Field | Entry |
|---|---|
| Decision ID | BU-O32 |
| Decision | Do draft and on-hold requisitions count toward the active limit; does the owner take a seat |
| Owner | Project owner |
| Status | OPEN |
| Options | Keep defaults (yes; yes) · change them |
| Selected option | |
| Reason | |
| Effective date | |
| Evidence required | Decision recorded |
| Blocks | G |
| Dependencies | BU-O26 |
| Notes | D-S3-O2; D-S3-O4 |

## BU-O36 — Deletion and purge policy

| Field | Entry |
|---|---|
| Decision ID | BU-O36 |
| Decision | Deletion grace period; purge authorisation; restoration after a purge; identities left without membership |
| Owner | Project owner + Legal |
| Status | OPEN |
| Options | Keep defaults (30-day grace; two operators approve; no restoration except from backups; identities kept, closable with `identity:disable`) · change them |
| Selected option | |
| Reason | |
| Effective date | |
| Evidence required | Policy recorded; deletion/purge validated on staging (BU-V14) |
| Blocks | G |
| Dependencies | BU-O37 |
| Notes | D-S5-O4; D-S5-O9; D-S5-O11; D-S5-O13 |

## BU-O37 — Retention policy

| Field | Entry |
|---|---|
| Decision ID | BU-O37 |
| Decision | Retention per data class: logs, data exports, compliance exports, audit, backups, tenant data after cancellation and after deletion, candidate personal data (with SEC-88-02), webhook and idempotency data |
| Owner | Project owner + Legal |
| Status | OPEN |
| Options | Per class: a period, or "kept" with a recorded reason. **Today:** logs kept forever (`LOG_DAILY_DAYS=0`); data exports forever; compliance exports 7 days; audit forever; webhooks 30 days; idempotency 24 h; no erasure path. New periods for exports/candidate data are code work |
| Selected option | |
| Reason | |
| Effective date | |
| Evidence required | Retention schedule recorded; settings applied; SEC-88-02 record completed |
| Blocks | P (logs, backups), G (all) |
| Dependencies | SEC-88-02; BU-O06 |
| Notes | R-1…R-13; D-S7-O9; D-S5-O5/O6; D-S3-O7; X-8; D-S6-O12/O13; D8.9-024/030 |

## BU-O41 — Previous-key retention after a compromise

| Field | Entry |
|---|---|
| Decision ID | BU-O41 |
| Decision | How long a previous `APP_KEY` stays in `APP_PREVIOUS_KEYS` after a suspected compromise |
| Owner | Security + project owner |
| Status | OPEN |
| Options | Remove at once (outstanding portal and booking links break, up to 14 days) · keep until links expire (the leaked key stays valid for links meanwhile) |
| Selected option | |
| Reason | |
| Effective date | |
| Evidence required | Policy referenced by `app-key-and-secret-rotation.md` |
| Blocks | G |
| Dependencies | BU-O02 |
| Notes | PRD-12 |

## BU-O42 — Customer suspension policy

| Field | Entry |
|---|---|
| Decision ID | BU-O42 |
| Decision | When a customer tenant is suspended (non-payment, incident, request), and with what notice |
| Owner | Project owner + Finance |
| Status | OPEN |
| Options | To be defined. Billing already suspends automatically on unpaid, cancelled or expired subscriptions (`tenant-suspension.md`) |
| Selected option | |
| Reason | |
| Effective date | |
| Evidence required | Policy recorded |
| Blocks | G |
| Dependencies | BU-O28 |
| Notes | No Stage 1 source ID other than the runbook |

## BU-O55 — Remaining runbooks

| Field | Entry |
|---|---|
| Decision ID | BU-O55 |
| Decision | When to write the billing webhook runbook (after a provider exists) and extend the partial outage runbooks |
| Owner | Project owner + release owner |
| Status | OPEN |
| Options | Before go-live · after monitoring exists · not applicable (if no online billing) |
| Selected option | |
| Reason | |
| Effective date | |
| Evidence required | Runbooks committed |
| Blocks | G |
| Dependencies | BU-O25; BU-O05 |
| Notes | PRD-16 |

## BU-O56 — Minor defaults

| Field | Entry |
|---|---|
| Decision ID | BU-O56 |
| Decision | Accept or change: invitation lifetime (72 h); no public developer access; idempotency retention (24 h); per-tenant workload limits (120 deliveries and 120 inbound per minute; circuit after 5 failures, 300 s) |
| Owner | Project owner |
| Status | OPEN |
| Options | Accept each default · change it (setting) |
| Selected option | |
| Reason | |
| Effective date | |
| Evidence required | Decision recorded |
| Blocks | G |
| Dependencies | — |
| Notes | D-S2-O3; D-S6-O9; D-S6-O13; D-S7-O11 |

## API and webhooks — ONLY REQUIRED IF API/WEBHOOKS ARE ENABLED

The API and webhooks are off for every tenant; no plan grants them. Mark these **NOT APPLICABLE** if they stay off, with that decision recorded.

### BU-O46 — API exposure and model

| Field | Entry |
|---|---|
| Decision ID | BU-O46 |
| Decision | API exposure (domains, resources), authentication model, credential lifecycle, rate limits, versioning, documentation exposure |
| Owner | Project owner + security |
| Status | OPEN |
| Options | Keep the implemented defaults (reads + applicant intake; member-owned credentials; 90 days default, 365 max; 120/min per credential, 600/min per tenant, 300/min per inbound connection; `/api/v1`; OpenAPI in the repository only) · change them |
| Selected option | |
| Reason | |
| Effective date | |
| Evidence required | Decision record; BU-V10 on staging |
| Blocks | F |
| Dependencies | BU-O03 |
| Notes | D-S6-O1/O2/O3/O4/O8/O10 |

### BU-O47 — API and webhook plans and price

| Field | Entry |
|---|---|
| Decision ID | BU-O47 |
| Decision | Which plans include the API and webhooks, and their price |
| Owner | Project owner + Finance |
| Status | OPEN |
| Options | Include in named plans · per-tenant platform override only (default) |
| Selected option | |
| Reason | |
| Effective date | |
| Evidence required | Catalog updated |
| Blocks | F |
| Dependencies | BU-O26 |
| Notes | D-S6-O11 |

### BU-O48 — CORS

| Field | Entry |
|---|---|
| Decision ID | BU-O48 |
| Decision | Browser origins allowed to call `/api/*` |
| Owner | Project owner + security |
| Status | OPEN |
| Options | Named integrator origins (`CORS_ALLOWED_ORIGINS`) · none (empty) · any origin (default; preflight warns; no credentials either way) |
| Selected option | |
| Reason | |
| Effective date | |
| Evidence required | Setting recorded; preflight `cors_any_origin` cleared or accepted |
| Blocks | F |
| Dependencies | BU-O46 |
| Notes | PRD-08 (PR-01) |

### BU-O49 — Webhook policy

| Field | Entry |
|---|---|
| Decision ID | BU-O49 |
| Decision | Webhook retries, event catalogue, data retention |
| Owner | Project owner |
| Status | OPEN |
| Options | Keep defaults (7 attempts over ≈ 21 h, then failed; 4 thin events; 30 days) · change them |
| Selected option | |
| Reason | |
| Effective date | |
| Evidence required | Decision record; BU-V11 on staging |
| Blocks | F |
| Dependencies | BU-O04 |
| Notes | D-S6-O5/O6/O12 |

### BU-O50 — Webhook failure alerting; revocation and replay limits

| Field | Entry |
|---|---|
| Decision ID | BU-O50 |
| Decision | Whether webhook failures alert anyone, at what thresholds; whether bulk revocation and replay limits are needed |
| Owner | Project owner + security |
| Status | OPEN |
| Options | Keep as is (counts visible, no alert; per-credential and per-tenant controls; replay unlimited and audited) · add alerting thresholds (monitoring/code work) · add limits (code work) |
| Selected option | |
| Reason | |
| Effective date | |
| Evidence required | Decision record |
| Blocks | F |
| Dependencies | BU-O05 |
| Notes | PRD-14 (PR-05); PRD-15 (PR-06) |

### BU-O51 — Provider accounts per tenant

| Field | Entry |
|---|---|
| Decision ID | BU-O51 |
| Decision | Whether messaging/calendar provider accounts stay shared across tenants |
| Owner | Project owner |
| Status | OPEN |
| Options | Keep shared platform accounts and circuit (default) · per-tenant accounts (code work) |
| Selected option | |
| Reason | |
| Effective date | |
| Evidence required | Decision record |
| Blocks | F |
| Dependencies | — |
| Notes | D-S1-O6 |

## Decisions Stage 1 did not place in a tranche

Stage 1's register gives these "Blocks" values, but its tranche lists (Gate 1) omit them. **The grouping is not changed here.** They are listed so they are not lost; the owner may place them.

| ID | Stage 1 "Blocks" | Where the "Blocks" value would place it |
|---|---|---|
| BU-O09 Shared cache technology | none | No tranche (default: database store) |
| BU-O10 Worker capacity and memory limits | P, G | Alongside 1C |
| BU-O11 Container log-driver limits | P, G | Alongside 1C |
| BU-O12 WAF | G | Alongside 1D |
| BU-O45 Interview time-zone model | F | Conditional, like BU-O46–O51 |

### BU-O09 — Shared cache technology

| Field | Entry |
|---|---|
| Decision ID | BU-O09 |
| Decision | Keep the database cache store or move to another shared cache |
| Owner | Project owner + infrastructure lead |
| Status | OPEN |
| Options | Database store on MySQL (as built; enough at measured scale) · Redis (image and dependency change, re-validation) |
| Selected option | |
| Reason | |
| Effective date | |
| Evidence required | Decision record |
| Blocks | — |
| Dependencies | Gate 6 results |
| Notes | D-S7-O4 |

### BU-O10 — Worker capacity and memory limits

| Field | Entry |
|---|---|
| Decision ID | BU-O10 |
| Decision | Worker replicas and container memory limits |
| Owner | Infrastructure lead |
| Status | OPEN |
| Options | Compose topology (4 single-process workers, no memory limit) · set limits and replicas (`queue-automation` stays single unless a locked limit check is added first — capacity plan) |
| Selected option | |
| Reason | |
| Effective date | |
| Evidence required | Settings recorded; Gate 6 results |
| Blocks | P, G |
| Dependencies | BU-O01; Gate 6 |
| Notes | D-S7-O5 |

### BU-O11 — Container log-driver limits

| Field | Entry |
|---|---|
| Decision ID | BU-O11 |
| Decision | Container log rotation limits |
| Owner | Infrastructure lead |
| Status | OPEN |
| Options | Set limits for the runtime's log driver · rely on application daily files (`storage-logs` volume) |
| Selected option | |
| Reason | |
| Effective date | |
| Evidence required | Settings recorded |
| Blocks | P, G |
| Dependencies | BU-O01; BU-O37 |
| Notes | P810-OP-07 |

### BU-O12 — WAF

| Field | Entry |
|---|---|
| Decision ID | BU-O12 |
| Decision | Whether a WAF sits in front of the application |
| Owner | Project owner + security |
| Status | OPEN |
| Options | No WAF (not required by the code) · a WAF (infrastructure) |
| Selected option | |
| Reason | |
| Effective date | |
| Evidence required | Decision record; pen-test scope updated |
| Blocks | G |
| Dependencies | BU-O03 |
| Notes | SaaS-7 security review §9 |

### BU-O45 — Interview time-zone model

| Field | Entry |
|---|---|
| Decision ID | BU-O45 |
| Decision | The interview time-zone model, before self-scheduling, calendar or Zoom sync are enabled |
| Owner | Project owner |
| Status | OPEN |
| Options | Keep manual scheduling only (default) · decide the model and enable those features |
| Selected option | |
| Reason | |
| Effective date | |
| Evidence required | Decision record |
| Blocks | F |
| Dependencies | — |
| Notes | D8.10-006 |

---

# PART 7 — DECISION PRIORITY

Grouping exactly as Stage 1 (Gate 1 tranches).

| Tranche | When | Decisions |
|---|---|---|
| **1A — before staging** | Now | BU-O01, O02, O03, O04, O05 (with a test alert mailbox), O06, O07, O08 |
| **1B — before rehearsal** | In parallel with 1A | BU-O13, O14, O15, O20 |
| **1C — before pilot** | Before Gate 8 | BU-O16, O17, O18, O19, O21, O22, O24, O25, O31, O33, O34, O35, O38, O39, O40, O43, O44, O52, O53, O54 |
| **1D — before customer go-live** | Before Gate 9 | BU-O23, O26, O27, O28, O29, O30, O32, O36, O37 (with SEC-88-02), O41, O42, O55, O56; BU-O46–O51 only if API/webhooks are enabled |
| Not placed by Stage 1 | See Part 5, last section | BU-O09, O10, O11, O12, O45 |

**Two items need dates even though they block no future gate:** SEC-PROD (production Critical) and SEC-88-02 have risk records in Part 3.

---

# OWNER DECISIONS REQUIRED NOW

Only the decisions that must be made before the next technical gate can begin.

**Before staging can be built (Gate 2):**

1. **BU-O01** — Hosting provider and container runtime
2. **BU-O02** — Secrets manager and `APP_KEY` custody
3. **BU-O03** — Domains, DNS and TLS termination
4. **BU-O04** — Network egress model
5. **BU-O05** — Monitoring/error-tracking vendor + test alert mailbox
6. **BU-O06** — Backup tool, location, key custody, RPO and RTO
7. **BU-O07** — CI runner, image registry and deployment strategy
8. **BU-O08** — Database sizing for staging and production

**Release decisions, in parallel (before the production-copy rehearsal):**

9. **BU-O13** — Release line and commit
10. **BU-O14** — Release order (A. Phase 8.11 first · B. Combined)
11. **BU-O15** — Production facts (17 items, read-only)
12. **BU-O20** — Tenant #1 values (`TENANT_ONE_*`)

**Status of all twelve: OPEN.**
