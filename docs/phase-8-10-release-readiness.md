# Phase 8.10 Release Readiness (Workstream A)

**For:** the project owner, Operations and Security. Use it to decide and run the production releases.

**Status: NOT PRODUCTION READY. Workstream A is DECISION-GATED, not complete (§9).**
- Production: **NOT DEPLOYED / NOT CHANGED.**
- Push: **NOT DONE.**
- Nothing was merged into a production branch.

**Labels:** **FACT** = observed or read in code. **INFERENCE** = reasoned, not executed. **OWNER** = a business or operations decision.

## 0. Status at a glance

| Item | Status |
|---|---|
| D8.10-020: dependency security | **COMPLETE.** `laravel/framework` 13.30.1, `league/commonmark` 2.10.3 (`ae48029`); `composer audit` clean; no unrelated package changed. Kept for the Phase 8.10 release path (Release B). |
| D8.10-002: production authorization release | **APPROVED IN PRINCIPLE** to prepare an isolated release (Release A). **Prepared and verified (§2); NOT deployed.** Deployment is blocked by the gates in §9. Its build route is open (D8.10-021). |
| D8.10-005: Docker-capable runner | **BLOCKED.** No runner available. No container tooling was installed. |
| P810-OP-01: image build | **NOT VERIFIED.** Code fixed (`bd32662`); the image has never been built. |
| P810-OP-02: upgrade rehearsal | **REHEARSAL SUCCESSFUL — PRODUCTION BASELINE NOT VERIFIED** (§4) |
| P810-OP-03: worker drain | **FIXED in the procedure and tooling; verified at Laravel level on the development host; NOT verified with Docker** (§5) |
| P89-OPS-001: backup / restore | **OPEN.** The engineering procedure is proven on the development host. Owner decisions and a production backup are missing (§3). |
| P89-OPS-012: production authorization gap | **OPEN in production.** Release A is prepared and verified; not deployed. |
| D8.9-026: production facts | **OWNER / INFRASTRUCTURE INPUT REQUIRED.** Production is not accessible from engineering (§6). |

## 1. Release structure (kept separate)

| | Release A: production authorization patch | Release B: Phase 8.10 dependency and runtime release |
|---|---|---|
| Content | `9cba8e3` + `2fab3fd` + `599f0c5` (branch `hotfix/p810-production-authorization`) | `feature/sep_25_hrm`: Phases 4–8.9, the Phase 8.10 fixes, PHP 8.5.11 image (`bd32662`), `laravel/framework` 13.30.1 / `league/commonmark` 2.10.3 (`ae48029`) |
| Purpose | Close the production-line Filament missing-policy-method bypass (Phase 8.6 SEC-1, Critical) | The full release |
| Migrations | **none** | 89 (75 → 164) |
| Dependencies | **unchanged.** The production line's own lock: `laravel/framework` 13.29.0 and `league/commonmark` 2.10.0, which **still carry the P810-SEC-015 advisories** | patched |
| Status | prepared and verified; deployment gated | blocked (§9) |

**Combining A and B is not inferred.** If Operations chooses a combined release, record it as an explicit decision with the exact commit range.

**Release A build route (FACT; decision D8.10-021).**
- The production line's Dockerfile (`9cba8e3`) pins PHP 8.3. That is the same defect as P810-OP-01, so Release A **cannot be built from its own Dockerfile**.
- Release A can therefore be produced only by:
  - (a) the mechanism that builds production today, which is unknown (D8.9-026); or
  - (b) adding the Dockerfile correction to Release A. That is a non-policy change, so it needs an explicit decision.
- Either route must be built and tested on a Docker-capable runner (D8.10-005).

## 2. Release A: production authorization patch (P89-OPS-012, D8.10-002)

### 2.1 Exact diff (FACT)

| Step | Change |
|---|---|
| `9cba8e3` → `2fab3fd` | 25 files, **+404 / −0**: 22 policy files, the new trait `app/Policies/Concerns/ForbidsDeletion.php`, `tests/Feature/Security/DeleteAuthorizationTest.php`, `tests/Unit/PolicyActionCoverageTest.php` |
| `2fab3fd` → `599f0c5` | 2 files, **+50 / −0**: `app/Policies/CandidateJoiningPolicy.php` (adds `create()`: `joining.confirm`), `tests/Feature/Security/JoiningCreateAuthorizationTest.php` |
| Total | **26 files, +454 / −0. Only `app/Policies/**` and `tests/**`. No migration, route, configuration, model, Filament or dependency change. No existing line removed or altered.** |

**What it changes.**
- **Policies.** Explicit `delete`, `deleteAny`, `forceDelete`, `forceDeleteAny`, `restore` and `restoreAny` rules. Hiring facts (candidates, applications, interviews, offers, joinings) can no longer be deleted (`ForbidsDeletion`). Requisitions can be soft-deleted by their manager but never force-deleted. Master data `*Any` rules require `settings.manage`. Plus the explicit `CandidateJoiningPolicy::create()`.
- **Authorization behaviour.** On the production line, Filament allows any ability whose policy method is missing (`helpers.php` `get_authorization_response`; no strict mode, no fail-closed gate). Each added method replaces that default with an explicit rule.
- **Filament actions.** Delete, ForceDelete, Restore and their bulk forms on the resources above now follow the explicit rules.

### 2.2 Verification (FACT)

The production line's own lock was installed (`laravel/framework` 13.29.0), and assets were built from the identical `package-lock.json`.

| Run | Result |
|---|---|
| Policy-coverage audit: `9cba8e3` / `2fab3fd` | 62 / 11 exercised abilities without a policy method. The 11 left after `2fab3fd` are all bounded (§2.3). |
| **The four hotfix tests** (`DeleteAuthorizationTest` ×3, `PolicyActionCoverageTest` ×1) on **unpatched `9cba8e3`** | **4 failed / 4** |
| `JoiningCreateAuthorizationTest` on unpatched `9cba8e3` | 1 of 2 failed (the no-permission case: `canCreate()` answered true) |
| The four hotfix tests + `JoiningCreateAuthorizationTest` on **patched `599f0c5`** | **6 passed / 6**, 25 assertions |
| Mutation: `create()` removed from `599f0c5` | `JoiningCreateAuthorizationTest` fails |
| Full production-line suite on `599f0c5` (fresh export, production lock) | **646 passed, 2,393 assertions** |

### 2.3 Residual missing methods after the patch (all bounded; FACT)

- **Joining create.** Bounded by `viewAny` (`joining.confirm`) on every resource page: tested 403 / 200. It is also explicit now, through `599f0c5`.
- **Slab bulk delete.** `viewAny` = `incentives.configureRules`, the same permission as single delete.
- **Interview feedback** (no policy). Reachable only inside an interview the user may view (`interviews.manage` plus scope).
- **7 read-only history relation managers.** Only inside a record already visible to the user.

## 3. A1: backup and restore (P89-OPS-001)

### 3.1 Engineering-proven (FACT; development host, throwaway databases, synthetic data; not re-run)

| Proven | Evidence |
|---|---|
| Backup format | Consistent dump (`mysqldump --single-transaction --routines --triggers --hex-blob --no-tablespaces --set-gtid-purged=OFF --default-character-set=utf8mb4`) plus a same-point `tar.gz` of `storage/app` |
| Encryption | GnuPG symmetric AES-256 with modification detection; key held outside the backup |
| Manifest | SHA-256 per artefact; the release record (last migration) |
| Verification | Restore into a separate database and directory: **69 / 69 tables, 91,132 / 91,132 rows, 0 differences** (schema, row counts, per-column checksums); **204 / 204 files** identical; **0** missing database-to-file references |
| Restore procedure | Restore into an **empty** database. The dump only drops the tables it contains. |
| Failure handling | Tampered byte: SHA-256 FAILED, and GnuPG "message has been manipulated". Wrong key: "Bad session key". Unsafe target: refused. |

### 3.2 Production-required (not done; not engineering decisions)

| Required | Owner decision |
|---|---|
| RTO | **D8.9-007** (Operations + Product) |
| RPO, including whether binlog-based point-in-time recovery is needed (P810-OP-06) | **D8.9-008** (Operations + Product) |
| Backup **frequency** | **D8.9-009a** (Operations) |
| Backup **retention** | **D8.9-009b** (Operations + Legal, R-13) |
| Backup **storage location** (off-host) | **D8.9-009c** (Operations) |
| Encryption **key custody** (who holds it, where, rotation) | **D8.9-009d** (Operations + Security) |
| **DR strategy** (site, standby) | **D8.9-010** (Operations) |
| **Restore-test cadence** | **D8.9-028** (Operations) |
| Where the backup runs (the MySQL 8.4 client sits next to the database; the application image has none) | Operations, following D8.9-009 |

**P89-OPS-001 is complete only when** both of these exist:
- a production backup taken under the decided policy;
- a restore into a production-equivalent environment with the §3.1 checks passing.

Neither exists.

## 4. A4: upgrade rehearsal (P810-OP-02)

**REHEARSAL SUCCESSFUL — PRODUCTION BASELINE NOT VERIFIED.** The full evidence is in `phase-8-10-verification.md` §4. It was not re-run.

**Results (FACT):**
- **Migrations.** 89 / 89 applied (75 → 164), exit 0, 32.75 s, peak 115 MB.
- **Schema.** The final schema is identical to a fresh Phase 8.10 install: 121 tables, 0 differences in columns, indexes, unique keys, foreign keys and CHECK constraints.
- **Data.** Every pre-existing row and value is preserved. Roles and permissions match a fresh seeded install, so no seeder step is needed.
- **Boot.** HTTP 200; a queue job processed; 20 / 20 scheduled commands exit 0.

**Limitations (stated exactly):**
1. **The starting baseline is assumed:** the production line's migrations and seeders (`9cba8e3`), plus **synthetic** data (91,132 rows, 204 files).
2. **The production schema is not verified.** Drift, manual changes or partially applied migrations are unknown.
3. **Production volumes are unknown.**
4. **Live-load locking was not measured.** No concurrent traffic ran during the migrations, and the release assumes a maintenance window (§5).
5. **Rollback through migrations is not a data rollback.** 10 `grant_phase_*` migrations have empty `down()`; several backfills are one-way; data written after the upgrade is lost.
6. **Restoring the pre-release backup is the authoritative recovery mechanism.** Proven exact in §3.1.
7. **The identity-backfill scaling is an INFERENCE, not a production timing.** The measured value is 11.0 s for 6,000 synthetic candidates; "about 3 minutes per 100k" assumes linear scaling and is not guaranteed.
8. **It ran on the host runtime** (PHP 8.5.4, MySQL 8.4.11), **not the production image** (D8.10-005).

## 5. P810-OP-03: worker drain

### 5.1 What the documented step did (FACT)

**Old step 2:** `docker compose exec queue php artisan queue:restart`, then wait until no job is reserved. The problems:
- `queue:restart` only sets a cache flag, so each worker exits after its current job.
- Every worker is `restart: unless-stopped`, so Docker restarts it at once, **on the same old image**. Consumption continues and "0 reserved" may never hold.
- The scheduler kept dispatching and the web tier kept creating jobs.
- `docker compose up -d` then ran `migrate` while the old app, workers and scheduler were still running against the changing schema.
- **Old step 7** claimed "the previous image runs against the new schema". That is wrong for a release with migrations.

### 5.2 Corrected procedure (`docs/runbooks/queue-operations.md` §1)

1. **Stop intake:** `php artisan down` in the app container. Web requests get 503, so no new work is created. `/up` stays 200.
2. **Stop the scheduler:** `docker compose stop scheduler`. `schedule:work` stops starting runs and waits for the runs in progress.
3. **Let the workers empty the queues:** `php artisan queue:drain-status --wait=1800`. It exits 0 when nothing is ready, nothing is reserved, and no scheduled task holds its overlap lock. Delayed jobs are reported and kept.
4. **Stop the workers:** `docker compose stop queue queue-priority queue-automation queue-background`. Each gets SIGTERM through `exec setpriv`, finishes its job within `--timeout` (≤ 300 s), and Docker waits 330 s. A manual stop is not undone by the restart policy.
5. **Verify drained and stopped:** `docker compose ps` shows only `app` and `db`, and `queue:drain-status` exits 0.
6. **Back up and verify:** the exact pre-migration state.
7. **Migrate and start:** `APP_IMAGE_TAG=<new> docker compose up -d` runs `migrate`, then `app` (no maintenance flag in the new container), then the workers, then the scheduler.
8. **Verify the release.** Containers healthy; `/up` 200; `queue:health-check` exit 0; `/health/queue` 200; worker heartbeats present; `schedule:list` shows 20 tasks; scheduler healthy.
9. **Roll back.** For a release with migrations: restore into an empty database, plus the files, then the previous tag. Never `migrate:rollback`.

If production does not run this compose stack (unknown, D8.9-026), the operator performs the same steps with the production mechanism. The runbook says so explicitly.

### 5.3 Tooling and tests

- **New read-only command `queue:drain-status`** (`app/Console/Commands/QueueDrainStatus.php`; `QueueHealthService::drainState()`):
  - per queue: ready, delayed and reserved jobs;
  - scheduled tasks still holding their `withoutOverlapping` lock (`onOneServer` locks are excluded);
  - worker heartbeats and the scheduler's last tick;
  - `--wait=N` re-checks every 5 s;
  - exit 0 only when drained;
  - writes nothing.
- **Tests:** `QueueDrainStatusTest` (8 tests), plus `DeploymentTopologyTest` "the deploy runbook drains intake, scheduler and every worker before it backs up and migrates". The latter pins the order of the runbook steps against the compose worker list and fails if `queue:restart` is used as the drain. Both are mutation-checked.

### 5.4 Verification (FACT unless marked)

| Check | Result |
|---|---|
| Worker on SIGTERM (host, MySQL, `queue:work database`) | While job 1 ran: `drain-status` showed 1 ready, 1 reserved, exit 1. After SIGTERM the worker **finished job 1** (DONE after 14 s) and exited 0 15 s after the signal ("Interrupted"). **Job 2 was not started.** Then 1 ready, 0 reserved, exit 1. A worker with `--stop-when-empty` processed job 2, and `drain-status --wait=30` then exited 0. |
| A defect found by this check | The first MySQL run failed. The alias `delayed` is a MySQL reserved word, accepted by SQLite (the suite's database). Fixed (`*_jobs` aliases). `QueueDrainStatusTest` + `QueueHealthTest` re-run **on MySQL**: 14 passed, 65 assertions. |
| Maintenance mode | During `down`: `/up` **200**, `/admin/login` **503**, `/careers` **503**. After `up`: 200. Run with the cache maintenance driver on a throwaway database. The health-route exemption is driver-independent: Laravel's `ApplicationBuilder` registers `except($health)`. |
| `schedule:work` on SIGTERM | From the framework source (`ScheduleWorkCommand`: `shouldQuit`, waits for in-flight executions). **Not run.** |
| Docker steps (`docker compose stop`, restart policy, grace period) | **NOT EXECUTED** (D8.10-005). The compose invariants are pinned by `QueueTopologyTest` (grace ≥ `retry_after` > every `--timeout`; `exec setpriv`). |

**Status:** P810-OP-03 is fixed in the procedure and tooling, and verified at Laravel level. **Worker-drain verification under Docker remains open (D8.10-005).**

## 6. D8.9-026: production facts required

**Status: OWNER / INFRASTRUCTURE INPUT REQUIRED.** Engineering has no production access. Nothing here is inferred from development data.

Collect the following **read-only**, from production, without secrets, before any rehearsal can be called production-representative:

| Fact | How (read-only) | Why |
|---|---|---|
| Migration state | `php artisan migrate:status` or `SELECT migration, batch FROM migrations ORDER BY id` | Which of the 164 have run; any drift from 75 |
| Schema | `mysqldump --no-data --skip-comments --no-tablespaces <db>` | Compare with the `9cba8e3` schema for drift or manual changes |
| Table sizes | `SELECT table_name, table_rows, data_length, index_length FROM information_schema.tables WHERE table_schema = DATABASE()` | Migration duration and locking estimate |
| Candidate / application / requisition / employee counts | `SELECT COUNT(*)` on `candidates`, `candidate_applications`, `recruitment_requisitions`, `employees` | Scaling of the identity backfill and other data migrations |
| Audit volume | `SELECT COUNT(*), MIN(created_at) FROM audit_logs` | Size and growth |
| Document volume | `SELECT COUNT(*) FROM candidate_documents`, plus offer letters; `du -sh storage/app/private/*` on the storage volume | Backup size and duration |
| Queue backlog | `SELECT queue, COUNT(*), MIN(available_at) FROM jobs GROUP BY queue`; `SELECT COUNT(*) FROM failed_jobs` | Drain duration; payload compatibility |
| Roles and permissions | `SELECT name FROM roles`; `SELECT name FROM permissions` | Hand-made changes the rehearsal assumed absent |
| Database version and configuration | `SELECT VERSION()`; `SHOW VARIABLES` for `innodb_buffer_pool_size`, `max_connections`, `log_bin`, `binlog_expire_logs_seconds`, `gtid_mode` | Runtime compatibility; binlog (P810-OP-06) |
| PHP / runtime | `php -v`; `php -m`; how PHP is installed | Image vs host |
| Docker / runtime state | `docker version`; `docker compose version`; `docker compose ps`; image tags in use; how images are built and deployed | Release A build route (D8.10-021); drain mechanism |
| Configuration (no secret values) | `QUEUE_CONNECTION`, `CACHE_STORE`, `SESSION_DRIVER`, `APP_MAINTENANCE_DRIVER`, `QUEUE_EXPECT_PROCESSES`, `APP_ENV`, `APP_DEBUG`; front end (proxy or TLS terminator) | Drain behaviour; P810-SEC-001 exposure |

## 7. D8.10-005: minimum requirements for the external runner

**Required:**
- Docker Engine with BuildKit (the Dockerfile uses `# syntax=docker/dockerfile:1`) and Docker Compose v2.
- Network access to:
  - Docker Hub (pinned digests): `php:8.5.11-cli-trixie@sha256:19642e17…`, `php:8.5.11-apache-trixie@sha256:70d80539…`, `composer:2.9.5@sha256:698d3801…`, `node:22.22.1-alpine@sha256:8094c002…`, `mysql:8.4`;
  - the Debian trixie apt mirrors;
  - Packagist (Composer dist);
  - the npm registry.
- Resources: not measured. The LibreOffice layer is the largest.

Once a runner exists, in this order:

| # | Step | Pass criterion |
|---|---|---|
| 1 | `docker build -t recruitment-edge-app:<sha> .` | exit 0 |
| 2 | Reproducibility: a second `--no-cache` build of the same commit; compare `php -v`, `php -m`, `composer show --locked` and the `public/build/manifest.json` hashes | identical (byte-identical images are not expected without `SOURCE_DATE_EPOCH`) |
| 3 | Boot: `APP_IMAGE_TAG=<sha> docker compose -p re-verify up -d` on fresh volumes | all services `healthy` |
| 4 | Migrations: the `migrate` service | exit 0, 164 ran |
| 5–6 | Workers and scheduler | four workers and the scheduler `healthy` (heartbeat checks) |
| 7 | Health | `/up` 200; `/health/queue` 200 with the token |
| 8 | Queue test | a platform alert processed by `queue-priority`, 0 failed (as in the rehearsal) |
| 9 | Drain procedure | `queue-operations.md` §1 steps 2–8 executed exactly, with `docker compose ps` and `queue:drain-status` evidence |
| 10 | Tests in the production runtime: the built image's PHP 8.5.11 runtime over the same commit's source with development dependencies, e.g. `docker run --rm -v "$PWD":/var/www/html --entrypoint php recruitment-edge-app:<sha> vendor/bin/pest --parallel` after `composer install` (dev) of that commit. The image itself is `--no-dev` and excludes `tests/`. | security suite, then the full suite: all pass |
| 11 | Browser suite against the running stack | all pass |

**P810-OP-01 is not verified until steps 1–8 succeed.**

## 8. Recovery

- **Release A:** policies only, no migration. Roll back by redeploying the previous production build.
- **Release B:** restore the pre-release backup (runbook step 7) into an empty database, plus the files, then start the previous image. Never `migrate:rollback`, and never the previous image on the new schema.

## 9. Workstream A exit gate

| Requirement | Status |
|---|---|
| Docker image actually builds | ☐ **BLOCKED** (D8.10-005) |
| Docker runtime successfully boots | ☐ **BLOCKED** (D8.10-005) |
| Phase 8.10 tests run in the production image | ☐ **BLOCKED** (D8.10-005) |
| Required browser verification completed | ☐ open: needs the running stack |
| Production authorization patch finalized | ☑ `599f0c5`, verified (§2) |
| Production release strategy explicitly decided | ☐ open: deployment of Release A, its build route (D8.10-021), Release B strategy (D8.10-003) |
| Production baseline verified, or documented as unavailable with owner acceptance | ☐ open: D8.9-026 |
| Upgrade rehearsal production-representative, or explicitly accepted as limited | ☐ open: limited rehearsal not yet accepted by the owner |
| Backup policy decisions complete | ☐ open: D8.9-007, 008, 009a–d, 010, 028 |
| Production backup exists | ☐ open |
| Restore verification completed in the required environment | ☐ open (development host only) |
| Worker drain procedure verified | ◐ Laravel level verified; **Docker execution open** (D8.10-005) |
| Deployment runbook corrected | ☑ `queue-operations.md` §1, `incident-recovery.md` §5, `backup-restore.md` |
| Queue / scheduler / application health verified | ◐ verified on the upgraded rehearsal database (host); in-image open |
| No unresolved Critical / High release-blocking security issue | ☐ **open:** production Critical until Release A is deployed; P810-SEC-001 and SEC-004 (High) are Workstream C |
| No unresolved High data-integrity release blocker | ☐ **open:** DI-01, DI-02, DI-04 (Workstream D) |
| Release rollback / recovery documented | ☑ §8, runbooks |
| All required decisions recorded | ☑ decision register (D8.10-002, 003, 005, 021; D8.9-007…010, 026, 028) |
