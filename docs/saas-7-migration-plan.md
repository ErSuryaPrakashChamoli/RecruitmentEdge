# SaaS-7 — Migration Plan: Tenant-Led Indexes

**For:** Operations, the database administrator and the release owner.

**Scope:** one additive migration, `2026_10_05_160000_add_saas_7_tenant_time_indexes`.
- Four secondary indexes; no table, column or row changes; no data migration.
- Everything else SaaS-7 changes is code, configuration and compose (`docs/saas-7-scale-reliability.md`).

## 1. What changes in the database

| Table | Index | Why (EXPLAIN evidence: `docs/saas-7-capacity-plan.md` §3) |
|---|---|---|
| `candidates` | `candidates_tenant_updated_idx (tenant_id, updated_at)` | API `updated_since`: 459 → 1.4 ms (large tenant) |
| `candidate_applications` | `ca_tenant_updated_idx (tenant_id, updated_at)` | API `updated_since`: 325 → 1.2 ms |
| `candidate_stage_histories` | `csh_tenant_created_idx (tenant_id, created_at)` | stage-activity period metrics: 13.9 → 0.9 ms (small tenant), 38 → 14 ms (large) |
| `audit_logs` | `audit_logs_tenant_created_idx (tenant_id, created_at)` | a small tenant's audit list: 144 → 0.5 ms |

MySQL 8.4 adds a secondary index **in place, without blocking reads or writes** (online DDL). Each build holds a brief metadata lock at its start and end. Measured build times on the 200k-application study copy: 0.2–2.0 s per index.

The triggers from `audit:protect` (if installed) do not affect `ALTER TABLE … ADD INDEX`.

## 2. Before running it

1. A verified backup (`docs/runbooks/backup-restore.md`) — required by every release with migrations.
2. Nothing else: no configuration or owner decision depends on these indexes.
3. The SaaS-7 **code** has its own prerequisites, independent of this migration (`docs/runbooks/production-environment.md`):
   - `DB_CACHE_CONNECTION=mysql_cache`, `DB_CACHE_LOCK_CONNECTION=mysql`;
   - the maintenance flag in the shared cache;
   - `PLATFORM_NOTIFY_EMAIL`;
   - `ops:preflight` passing.

## 3. Running it, validating it, rolling back

**Run:** the compose `migrate` service (`php artisan ops:migrate`), which runs `migrate --force` under a per-database named lock. A second run started at the same time waits, then finds nothing to do.

**Validate:**
- `php artisan migrate:status | tail -1` shows the migration as run;
- `php artisan ops:verify-integrity` → "Integrity OK";
- `php artisan tenancy:verify --all` → 0 violations.

**Roll back:** `down()` drops the four indexes (rehearsed: about 1 s, nothing else changes). Dropping them only restores the earlier query plans; no data depends on them. The release rollback remains the backup restore of the runbook, as for every release.

## 4. Rehearsals (MySQL 8.4.11, throwaway copies)

**Method** (as in SaaS-6):
1. Each source database was copied table by table into a throwaway `hrms_saas7_r*` on the same server. The source is only read.
2. The copy was migrated up to SaaS-6 only (every migration but `2026_10_05_160000`).
3. Snapshot: CRC32 of every column of every table; every tenant's effective entitlements and status.
4. The SaaS-7 migration was run and compared with the snapshot.
5. `tenancy:verify --all`; `tenants:run integrations:sweep --all`.
6. Rollback (`down()`), compared; re-apply, compared.
7. A **SaaS-7 smoke** in one tenant (DNS and outbound HTTP faked), described below.

The SaaS-7 smoke covers:
- `/health/live`, `/health/ready`;
- `ops:preflight` and `ops:verify-integrity`;
- `audit:protect` on MySQL: install, append, refuse an update and a delete, remove;
- the permission map of each tenant (only its own roles);
- the scheduler's probes and the uniqueness of tenant tasks;
- a budgeted pass and the one-pass health check;
- the delivery budget (deferral, no attempt counted);
- the API's `updated_since`;
- **paused work end to end, on the database queue with a real worker:** a queued message is refused while its tenant is suspended and kept Queued; it is re-queued once on reactivation and processed;
- re-encryption (dry run).

| | R1: development `hrms` | R1b: mixed states | R2: 100k benchmark `hrms_p87_perf` |
|---|---|---|---|
| Starting schema | 178 (the source was already at SaaS-6) | 178, plus 4 tenants provisioned on the copy: trial; past due with a `members.active.max` override; suspended; cancelled | 160 → 178 (SaaS-1…6 on the copy, 140 s), plus one provisioned tenant (`reh-api`) |
| SaaS-7 migration | 0.31 s → 179 | 0.25 s → 179 | 2 s → 179 |
| Tenants / memberships / role assignments / current plans | 1 / 11 / 11 / 1, unchanged | 5 / 11 / 11 / 5, unchanged | 2 / 511 / 111 / 2, unchanged |
| Pre-existing tables unchanged (all columns, CRC32) | 146 / 146 | 146 / 146 | 146 / 146 |
| New indexes present | 4 / 4 | 4 / 4 | 4 / 4 |
| Effective entitlements and status, every tenant | identical | identical (5 tenants) | identical (2 tenants) |
| `tenancy:verify --all` | 410 checks, 0 violations | 413, 0 | 433, 0 |
| `integrations:sweep` | exit 0 (1 tenant) | exit 0 (3 usable tenants; suspended and cancelled skipped) | exit 0 (2 tenants) |
| Rollback (`down()`) | 1 s; 178; 0 tables changed; 0 index columns left | 0 s; same | 1 s; same |
| Re-apply | 1 s; 179; 0 changed; 4 indexes | 1 s; same | 3 s; same |
| `ops:verify-integrity` (smoke) | OK: 476 foreign keys, 0 orphans, 0 tenancy violations, 0 pending | same | same (6.5 s) |
| Health | live 200; ready 200 `{"status":"ok"}` | same | same |
| Preflight (development configuration) | exit 0; warnings only | same | same |
| Audit triggers on MySQL | installed; append OK; update and delete refused; removed | same | same |
| Permission maps | own roles only (0 of another tenant), 10 KB | same, 4 tenants | same, 2 tenants |
| Probes and uniqueness | 0 tenants with work; two ticks queue 1 job for 1 tenant | 0 with work; 3 jobs for 3 usable tenants | automation 1, reliability 2, integrations 1 of 2; 2 jobs for 2 tenants |
| Budgeted pass | 1 tenant finished | 3 finished | 2 finished |
| Delivery budget (2 per minute, 5 deliveries) | 2 sent, 3 deferred (still pending, 0 attempts, due later) | same (past-due tenant) | same |
| Paused work (database queue, real worker) | refused while suspended, kept Queued (1 failed job); re-queued once on reactivation; then processed¹ | same | same |
| Re-encryption (dry run) | every value readable | same | same |
| `tenancy:verify` after the smoke | 414, 0 | 416, 0 | 435, 0 |

¹ It then failed only because the copies have no message provider configured ("Provider none is no longer configured"), not on a tenant refusal (1 attempt).

The smoke steps were first run inside the rehearsal script, where two smoke defects stopped them. The endpoint had no event, and on R2 the worker took one of the copy's 700 pre-existing queued messages. Both were fixed (its own queue for the smoke job), and the smoke was re-run on all three copies; the table shows the re-run.

**Code rehearsed.** The rehearsals ran on the code of `490aaa9` without `68f5b4b` (the permission map also rotating on rollback) and its tests. The later commits `b749ae1` and `c395827` change only tests and factories. None of those touches the schema, a query or anything the rehearsals measured.

**Source databases.** `hrms_p87_perf` was only read and remains at 160 migrations. The development database `hrms` was already at 178 migrations (SaaS-1…6) when SaaS-7's rehearsals started: it was migrated at 06:31 UTC on 2026-10-05, at a moment when this work ran no command. The rehearsals only read it. It is not a production database.

**Not rehearsed** (the same gaps as SaaS-1 to SaaS-6):
- a production copy, from production's real migration state;
- a real container runtime (`ops:migrate` in the compose service; tested with the MySQL race instead).

The throwaway copies were dropped after the run.

## 5. Production-copy rehearsal procedure (release gate D-S7-O10)

Run by the release owner, never against production itself:
1. **Snapshot:** take an encrypted backup per the runbook and confirm it restores into an empty instance. Record `migrate:status`, row counts and table sizes.
2. **Copy:** restore it into an isolated database with the production MySQL version and configuration (`innodb_buffer_pool_size`, binary logging).
3. **Baseline:**
   - CRC32 of every column of every table;
   - every tenant's effective entitlements and status;
   - `tenancy:verify --all`;
   - `ops:verify-integrity`.
4. **Run:** `php artisan ops:migrate`, timing each migration. From a second session, issue reads and writes on the four tables and watch `SHOW PROCESSLIST` for metadata-lock waits.
5. **Validate:**
   - checksums of every pre-existing table unchanged;
   - the four indexes present;
   - `tenancy:verify` 0 violations;
   - `ops:verify-integrity` OK;
   - entitlements identical;
   - `ops:preflight` (production configuration) without a blocker;
   - `/health/ready` 200;
   - the smoke steps of §4 on a scratch tenant.
6. **Decide:** roll back (restore) only if a validation fails. Record the timings to size the maintenance window.
