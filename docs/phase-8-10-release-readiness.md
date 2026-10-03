# Phase 8.10 Release Readiness (Workstream A)

**For:** the project owner, Operations and Security. Use it to decide the first production release and the hotfix path.

**Status: NOT PRODUCTION READY.** Workstream A is not complete. Its open gates are in §6.
- Production: **NOT DEPLOYED / NOT CHANGED.**
- Push: **NOT DONE.**
- Nothing was merged into a production branch.

**Labels:** **FACT** = observed or read in code. **INFERENCE** = reasoned, not executed. **OWNER** = a business or operations decision.

## 1. A3: production image (P810-OP-01)

| | |
|---|---|
| Code fix | `bd32662`. PHP 8.5.11 base images are pinned by digest (`php:8.5.11-cli/apache-trixie`, `composer:2.9.5`, `node:22.22.1-alpine`). The image no longer re-builds extensions it already compiles in (`mbstring`, `pdo_sqlite`, `opcache`) or `pdo_pgsql`, which could not compile. `composer.json` declares `php ^8.5`. |
| Dependency state | `ae48029`: `laravel/framework` v13.30.1 and `league/commonmark` 2.10.3 (D8.10-020). `composer audit` reports no advisories. |
| **Verification** | **NOT VERIFIED.** No container runtime is available (D8.10-005 **BLOCKED**). The image has never been built. It is unknown whether the image runs, and whether the test suite passes inside it. |
| Status | **P810-OP-01 OPEN** until the image is built and tested on a Docker-capable runner. |

## 2. A2: production authorization gap and release path (P89-OPS-012, D8.10-002)

### 2.1 Exact gap on the production line (`9cba8e3`)

**FACT:** shown by a policy-coverage audit that booted the production-line code, and by tests.

- **Mechanism.** Without strict mode, Filament allows an ability when the model's policy lacks the method, or when there is no policy at all, unless a `Gate::before` callback denies it (`vendor/filament/filament/src/helpers.php`, `get_authorization_response`). The production line has no strict mode and no fail-closed gate.
- **Size of the gap.** 227 (resource or relation manager, ability) pairs exercised by the admin UI were audited. **62 had no policy method.** Most are delete, restore and force-delete abilities and their bulk forms: the Phase 8.6 SEC-1 delete bypass, rated Critical.
- **Proof.** `tests/Feature/Security/DeleteAuthorizationTest.php` from the hotfix fails **4 / 4** on `9cba8e3` and passes on `2fab3fd`.

### 2.2 What closes it

| Commit | Content | Result |
|---|---|---|
| `2fab3fd` (existing hotfix, local) | Explicit delete, restore and force-delete rules (25 files, +404 lines; policies and tests only) | Leaves **11** of the 62 missing (re-audit) |
| `599f0c5` (new, local branch `hotfix/p810-production-authorization`, parent `2fab3fd`) | `CandidateJoiningPolicy::create()` = `joining.confirm` (SEC-86-I-01), plus `JoiningCreateAuthorizationTest` | Makes the joining-create rule explicit |

**The 11 residual missing methods after `2fab3fd`.** Each was checked by hand. None widens access:

| Residual | Why it is bounded |
|---|---|
| `CandidateJoiningPolicy::create` | Every Filament resource page enforces `Resource::canAccess()`, which is `viewAny` (`CanAuthorizeResourceAccess`). On the production line, `viewAny` = `joining.confirm`. Tested on the hotfix tree: no permission → **403**; recruiter → 200. Closed explicitly by `599f0c5`. |
| `RecruitmentIncentiveSlabPolicy::deleteAny` (slab bulk delete) | The slabs relation manager is visible only with `viewAny` = `incentives.configureRules`, the same permission as single delete. |
| `InterviewFeedback` has no policy (`viewAny`, `create`, `update` in its relation manager) | Reachable only inside an interview the user may view: `interviews.manage` plus scope (`InterviewPolicy`). |
| 7 read-only history relation managers with no policy (`viewAny` only): stage history, duplicate matches, offer status history, incentive approvals and adjustments, requisition approvals | Read-only lists inside a record the user may already view. |

### 2.3 Answers to the brief

| # | Question | Answer |
|---|---|---|
| 1 | Exact production gap | Missing-method allow, chiefly delete, restore and force-delete (Phase 8.6 SEC-1). The joining `create` method is also missing, but `viewAny` bounds it. |
| 2 | Commits that close it | `2fab3fd` (delete family) and `599f0c5` (explicit joining create). |
| 3 | `CandidateJoiningPolicy::create()` in the release path? | **Not in `2fab3fd`, not on `9cba8e3`. Yes in `599f0c5`** and at HEAD (`feature/sep_25_hrm`, from `3e51819`). |
| 4 | Further policy methods required? | **None to close an escalation** (§2.2). Optional, behaviour-neutral hardening: explicit slab `deleteAny`, and an `InterviewFeedbackPolicy` mirroring `InterviewPolicy`. |
| 5 | Migrations required? | **No.** Policies and tests only. |
| 6 | Tests covering the gap | `DeleteAuthorizationTest` (fails 4 / 4 without the fix), `PolicyActionCoverageTest` (static), `JoiningCreateAuthorizationTest` (fails without `create()`; mutation-checked). |
| 7 | Releasable independently? | **Yes.** No schema change, and the base is the production line (`9cba8e3`). |
| 8 | Smallest safe production patch | **`2fab3fd` + `599f0c5`** on top of `9cba8e3`: 26 files, policies and tests only. |

### 2.4 Verification of the patch (FACT)

All runs on the patched production-line tree, using its own dependency lock (Laravel 13.29.0) and locally built assets:
- **Full production-line suite:** **646 passed, 2,393 assertions.**
- **Targeted:** `JoiningCreateAuthorizationTest`, `DeleteAuthorizationTest` and `PolicyActionCoverageTest`: 6 passed. The new test fails when `create()` is removed.
- **Limitations:**
  - The production line itself still uses `laravel/framework` 13.29.0 and `league/commonmark` 2.10.0, which carry the P810-SEC-015 advisories. **The hotfix does not change dependencies.** Bringing the D8.10-020 updates to the production line is a separate decision.
  - Whether `main` / `production` at `9cba8e3` really is what runs in production is **not verified** (D8.9-026; local refs, not re-fetched).

### 2.5 Release recommendation for D8.10-002 (technical; Security and Operations decide)

**Hotfix first.** Release `9cba8e3 + 2fab3fd + 599f0c5` (branch `hotfix/p810-production-authorization`) to the production line, as its own release.

Rationale:
- It closes the Critical delete bypass now.
- It needs no migration.
- It is independent of the Phase 4–8.9 release, which is still blocked (§4, §6).

Before releasing:
1. Rebuild the production-line image. The production line's Dockerfile has the same PHP 8.3 defect as P810-OP-01, so its build needs the same fix, or the existing production build process (unknown, D8.9-026).
2. Re-run the 646 tests in that environment.
3. Take a backup (§3).

Not merged, not pushed, not deployed.

## 3. A1: backup and restore (P89-OPS-001)

### 3.1 Technical capability, recommended configuration, owner decisions

| | Item | Status |
|---|---|---|
| **A. Technical capability (prepared and tested on the development host)** | Consistent database dump (`mysqldump --single-transaction --routines --triggers --hex-blob --no-tablespaces --set-gtid-purged=OFF --default-character-set=utf8mb4`) | **Tested** |
| | Same-point file archive of `storage/app` (tar.gz) | **Tested** |
| | Encryption: GnuPG symmetric AES-256 with modification detection, using a key held outside the backup | **Tested** |
| | Integrity: SHA-256 manifest, plus the release record (last migration) | **Tested** |
| | Restore into a separate database and directory, with verification of schema, row counts, per-column checksums, file SHA-256 and database-to-file references | **Tested** (§3.2) |
| | Failure detection: tampering, wrong key, unsafe restore target | **Tested** (§3.2) |
| **B. Recommended operational configuration (engineering view; not decided)** | Run the dump from the MySQL 8.4 client that matches the server: the `db` container, or a backup host. The application image has no MySQL client (FACT, Dockerfile). | Needs D8.9-009 |
| | Back up the database and `storage-data` together at the same point. Keep the encryption key and `APP_KEY` in the secret store, never with the backups. | Needs D8.9-009 |
| | Before every release that runs migrations, take a backup and verify it with a restore, as rehearsed here. | Needs D8.10-003 |
| | Binlog: either archive and size it for point-in-time recovery, or disable it (P810-OP-06) | Needs D8.9-008 / 009 |
| **C. Owner / business decisions (not made by engineering)** | RTO (D8.9-007), RPO (D8.9-008), backup policy: frequency, retention, storage location, encryption key custody (D8.9-009), DR site (D8.9-010), restore-test cadence (D8.9-028). Backup retention is also a Legal question (R-13). | **OPEN, none provided** |

### 3.2 Backup and restore test evidence (FACT; development host; throwaway databases; synthetic data)

**Subject.** The A4 starting state: `hrms_p810_rehearsal` on production-line code `9cba8e3`, 69 tables, 91,132 synthetic rows, and 204 files under `storage/app`, 200 of them referenced by `candidate_documents`.

| Step | Result |
|---|---|
| Backup | Database dump 2.03 s → `database.sql.gz.gpg` 2,758,559 bytes. Files 0.23 s → `storage.tar.gz.gpg` 137,161 bytes. Manifest records the last migration `2026_09_14_124342_create_interviewers_table`. |
| Integrity check | `sha256sum -c` OK; decrypt plus `gunzip -t` OK |
| Restore | Into `hrms_p810_restore_pre` in 6.83 s; files in 0.21 s |
| Verification | **69 / 69 tables, 91,132 / 91,132 rows, 0 differences** in columns, indexes, foreign keys, CHECK constraints, row counts and per-column value checksums. **204 / 204 files identical** (SHA-256). **0** database-to-file references missing. |
| Tampered byte | SHA-256 **FAILED**. GnuPG reports "encrypted message has been manipulated" (exit 2). |
| Wrong key | GnuPG "Bad session key" (exit 2) |
| Unsafe target | The restore script refuses any target that is not a `hrms_p810_*restore*` database (exit 2) |

**What this does and does not show:**
- It **shows** that the documented procedure, as corrected here, produces a complete, verifiable and encrypted backup, and an exact restore.
- It **does not** show a production backup. No production backup exists, no backup is scheduled, no storage target or key custody exists, and nothing ran inside the compose stack (no container runtime).
- **P89-OPS-001 remains OPEN.** It needs the owner decisions in §3.1 C and a restore test in the production environment.

### 3.3 Runbook corrections made (`docs/runbooks/backup-restore.md`)

- **`mysqldump` options.** Added `--no-tablespaces`: since MySQL 8.0.21, dumping tablespaces needs the PROCESS privilege, which the compose application user lacks (documented MySQL behaviour; this host's user has PROCESS, so the failure was not reproduced). Also added `--set-gtid-purged=OFF` and `--default-character-set=utf8mb4`.
- **Encryption** is now specified, using the tested GnuPG command.
- **Restore verification** is now concrete: the checks rehearsed in §3.2.
- **Restore into an empty database.** The dump drops and recreates only the tables it contains (69 `DROP TABLE IF EXISTS`, no `DROP DATABASE` / `CREATE DATABASE`; FACT). Restoring a pre-upgrade backup over the upgraded schema would leave the 52 newer tables behind, and the next `migrate` would fail. The runbook now recreates the database first.
- **Failure modes** observed in the test are listed.

### 3.4 Dependencies, failure modes and evidence required for release

- **Dependencies:**
  - a MySQL 8.4 client next to the database;
  - storage outside the host;
  - a secret store for the backup key and `APP_KEY`;
  - the owner decisions above.
- **Failure modes:**
  - a dump missing tablespace privilege;
  - a database without its files, or files without the database;
  - lost or rotated keys;
  - a corrupted or tampered archive;
  - restoring onto an older release (migrations are forward-only);
  - the binlog filling the disk (P810-OP-06).
- **Evidence required before a production release:**
  - a production backup taken under the decided policy;
  - a restore into a non-production environment with the §3.2 checks passing;
  - a documented location and key custody;
  - the owner's sign-off on D8.9-007…010 and 028.

## 4. A4: upgrade rehearsal (P810-OP-02)

**REHEARSAL — PRODUCTION BASELINE NOT VERIFIED.**

Assumptions (labelled):
- **Starting state.** The starting state is the production line's own migrations and seeders (`9cba8e3`: 75 migrations), plus **synthetic** data generated with that code's factories. Production volumes, data shapes, manual changes and any schema drift are **unknown** (D8.9-026).
- **Runtime.** Host PHP 8.5.4 and MySQL 8.4.11, not the production image (D8.10-005).

### 4.1 Path and data

```
9cba8e3 schema (75 migrations) + seed + synthetic data
   = 69 tables, 91,132 rows, 204 files
      ↓
Phase 4–8.9 migrations: 89, applied by the Phase 8.10 code (ae48029)
      ↓
Phase 8.10 migrations: none exist yet
      ↓
final schema: 164 migrations, 121 tables
```

### 4.2 Results (FACT)

| Check | Result |
|---|---|
| Migration success | **89 / 89 applied, exit 0, 0 pending, 164 ran.** No failure. |
| Ordering | Filename order. All 89 are timestamped after the production line's last migration. |
| Total time / memory | **32.75 s** wall; migrations 30.3 s; peak RSS 115 MB |
| Slowest | `add_normalized_identity_columns_to_candidates_table` **11.0 s** for 6,000 candidates. INFERENCE: about 3 minutes per 100k candidates if linear. Then `add_pipeline_columns` 1.0 s, `add_access_state_to_users` 0.9 s; the rest under 0.9 s. |
| Final schema vs a fresh Phase 8.10 install | **Identical.** 121 / 121 tables. 0 differences in column types, nullability, defaults, extras, indexes, unique keys, foreign keys (with update and delete rules), CHECK constraints, and column order. |
| Data preservation | All **69** pre-existing tables kept every row, and every pre-existing column kept identical values (per-column checksums). The only additions: `permissions` 33 → 76, `role_has_permissions` 108 → 257, `roles` 5 → 6, `migrations` 75 → 164. Pre-existing rows of those tables are unchanged. |
| Seed / config assumptions | The upgraded roles (6, with keys), permissions (76) and grants (257) are **identical to a fresh install seeded with the Phase 8.10 `RolePermissionSeeder`**. The release needs **no seeder step**, assuming production roles were never hand-modified (unverified). Compose's `migrate` service runs `migrate --force` only. |
| Application boot | `/up`, `/admin/login`, `/careers`, `/portal/login`: **200** (`php artisan serve` on the upgraded database) |
| Queue boot | A real queued `StaffDatabaseNotification` (platform alert) was processed by `queue:work` on `security,notifications,default`: **DONE, 0 failed**, notification row written |
| Scheduler boot | `schedule:list` loads 20 tasks. **Every scheduled command, run once on the upgraded data: 20 / 20 exit 0.** Slowest: `performance:snapshot` 49.6 s, `notifications:dispatch-alerts` 27.5 s. `schedule:run` exit 0; `ops:heartbeat scheduler` exit 0. |

### 4.3 Rollback, irreversibility and locking

- **Migration rollback (FACT).** `migrate:rollback --step=89` ran all 89 `down()` methods in 24.8 s. The **schema returned exactly** to the production-line baseline: 69 tables, 0 schema differences.
- **It is not a data rollback (FACT):**
  - **10 `grant_phase_*` migrations have empty `down()`.** Rollback left 43 extra permissions, 149 extra grants and the `employee` role.
  - Rollback drops every new column and table, so data written after the upgrade is lost.
  - Migrations that backfill or reshape data on the way up: normalised identity columns, communication preferences, incentive beneficiary, role keys, AI tool-call identity, and the interview-feedback and separation lifecycle changes. Some of these re-add a unique index on the way down. That worked on fresh synthetic data, but INFERENCE: it would fail once post-upgrade data creates duplicates, for example several feedback versions.
- **Recovery strategy.** **Restore the pre-upgrade backup.** This is verified by §3.2: the restore of the pre-upgrade backup reproduced the starting state exactly. `migrate:rollback` is not an approved recovery path for this release.
- **Locking (NOT MEASURED).** No concurrent load ran during the migrations. The release plan assumes a maintenance window with every writer stopped. The deploy runbook's "drain" step does not stop the writers (P810-OP-03, discovery). That must be fixed before the window is relied on; it is not in Workstream A as authorised.

### 4.4 What is still required for P810-OP-02

1. The production baseline: the actual `migrations` table, schema and volumes (D8.9-026).
2. A rehearsal on a **restored copy of production data**, timing the identity backfill at real volume (benchmark B4).
3. The same rehearsal inside the production image (D8.10-005).
4. The release strategy decision (D8.10-003) and a downtime window (D8.9-023).

## 5. Dependency security (D8.10-020): APPROVED and applied

`ae48029`:
- `laravel/framework` v13.29.0 → **v13.30.1**;
- `league/commonmark` 2.10.0 → **2.10.3**.

No other package changed (181 packages). `composer.json` is unchanged. Results:
- `composer validate`: valid. `composer audit`: **no advisories.**
- AI, Copilot, conversation and privacy tests: 280 passed, 4,580 assertions. Security tests: 126 passed, 652 assertions.
- **Full parallel suite: 2,064 passed, 21,555 assertions**, 0 files written to storage. Local PHP 8.5.4, not the image.
- **Browser suite: not re-run.** The two packages change the Markdown renderer (Copilot and transcript display) and framework internals. Server-rendered Markdown output is covered by the feature tests above, so a browser run was not needed for this change. It stays part of final verification.

## 6. Workstream A gate status

| Gate | Status |
|---|---|
| Production image builds and passes tests | **BLOCKED**: D8.10-005, no Docker-capable runner |
| Upgrade rehearsal succeeds | **Succeeds on the assumed baseline.** **PRODUCTION BASELINE NOT VERIFIED** (D8.9-026); real-volume rehearsal outstanding |
| Backup and restore tested | **Procedure tested on the development host only.** Production backup **not established**; owner decisions D8.9-007…010 and 028 OPEN |
| Release path documented and reproducible | Hotfix path: **exact patch identified and tested** (`599f0c5`); decision D8.10-002 pending. Full-release path: blocked by the gates above and D8.10-003. |
| Dependency advisories | **Cleared** (D8.10-020) |
