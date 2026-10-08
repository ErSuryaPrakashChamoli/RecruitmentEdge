# SaaS-1 — Migration Plan: the Existing Organisation Becomes Tenant #1

**For:** the release owner and Operations (who run it), and Engineering (who support it).

**Status:**
- Rehearsed on development copies only (§5).
- **Not run against production, and not to be run until approved.**
- Production is still on `9cba8e3`. The release candidate `226bc7d` (Phase 8.11) is not deployed.
- The SaaS-1 migrations come after the release candidate's 89 migrations. The RMS release (Phase 8.11) must happen first, or both must be planned as one upgrade (§6).

## 1. What changes in the database

Four migrations, in order. They are expand → backfill → validate → contract.

| # | Migration | Step | What it does | Reversible by `down()`? |
|---|---|---|---|---|
| 1 | `2026_10_04_025111_create_tenants_table` | expand | `tenants`, `tenant_memberships` | yes (drops them) |
| 2 | `2026_10_04_025113_add_tenant_ownership_columns` | expand | nullable `tenant_id` + index + foreign key to `tenants` on 103 tenant tables, `audit_logs`, `communication_webhook_events` and `roles`; nullable `tenant_id` on `model_has_roles` / `model_has_permissions`. MySQL foreign-key checks are off while the empty columns are added, so each ALTER is in place. | yes (before migration 4 only) |
| 3 | `2026_10_04_025114_backfill_tenant_one` | backfill | creates Tenant #1 from `config/tenancy.php` (`TENANT_ONE_*`); assigns every existing row, role, role assignment, audit entry and webhook event to it; makes every existing staff login an active member, linked to its current employee. **Only if the database holds an organisation**; a fresh install gets no tenant here. | no: data rollback is restore (§4) |
| 4 | `2026_10_04_025116_enforce_tenant_ownership` | validate + contract | **refuses to run** (with table / column counts, never data) while any tenant row has no tenant or any reference or role assignment crosses tenants. Then: `tenant_id NOT NULL`; 35 business keys unique per tenant; `(tenant_id, id)` keys on 32 parents; 108 composite foreign keys; role assignment keys include the tenant. | no: restore (§4) |

**Backfill properties** (`App\Services\Tenancy\TenantBackfill`):
- **Batched:** each statement updates at most 5,000 rows, by id range (or by key range or batches for the four tables without an integer id). Never a whole table in one statement or lock.
- **Resumable:** only rows whose `tenant_id` is still null are touched, and Tenant #1 is found by its slug. An interrupted migration 3 is simply run again.
- **Codes untouched:** no business code, id, timestamp or sequence is rewritten. Code sequences carry on: in rehearsal R1, `ofr:2026` kept its last number.
- **Progress:** the console prints one line per table, for example `tenant #1 backfill: candidates 100063 rows`.

The pre-SaaS-1 permission migration (`2026_08_26_100131`) is pinned to the shape it created when it first ran (teams off). Fresh installs and upgrades therefore take the same path.

## 2. Before running it (OWNER ACTION REQUIRED)

1. **Phase 8.11 done:** the release candidate is in production and stable, or the owner approves one combined upgrade (§6).
2. **Choose Tenant #1's settings** in the environment:
   - `TENANT_ONE_SLUG`: appears in every staff, careers and portal URL. Default `main`. Choose it once; changing it later changes every link.
   - `TENANT_ONE_NAME`, `TENANT_ONE_LEGAL_NAME`.
   - `TENANT_ONE_TIMEZONE` (default: the metrics business timezone, `Asia/Kolkata`), `TENANT_ONE_CURRENCY` (`INR`), `TENANT_ONE_COUNTRY` (`IN`), `TENANT_ONE_LOCALE` (`en`).
3. **Communicate the URL changes** (`saas-1-tenant-foundation.md` §10):
   - Staff bookmarks become `/admin/{slug}/…`.
   - Careers links become `/careers/{slug}/…`; republish them on job boards and the website.
   - Candidate portal links become `/portal/{slug}/…`.
4. **Old links:**
   - Links already emailed (portal invitations, scheduling links, password-set links) and in-app notification links stored before the upgrade point to the old paths and will 404.
   - No compatibility redirect ships: guessing a tenant from an old path would be "default tenant" resolution. **OWNER DECISION:** accept, or ask Engineering for a reviewed one-tenant redirect for the transition period.
5. **Calendar redirect URIs:** unchanged (the callback path did not change). Connect links include the tenant.
6. **Verified production backup** (database and storage), exactly as for Phase 8.11 (`docs/runbooks/backup-restore.md`).
7. **Monitoring:** `/health/queue` now answers only to the `QUEUE_HEALTH_TOKEN` bearer token. Platform alerts with no tenant go to the log as `platform.alert`; tenant administrators get only their own tenant's queue problems.

## 3. Running it

Follows the established release order (`docs/runbooks/queue-operations.md` §1):

1. Maintenance on.
2. Stop the scheduler.
3. Drain the workers: `queue:drain-status --wait`. **Required:** jobs queued before the upgrade carry no tenant and would be refused.
4. Stop the workers.
5. Backup and verify it.
6. Run the one-shot `migrate` (all four migrations).
7. Validation (§3.1).
8. Start the workers, then the scheduler.
9. Maintenance off.
10. Smoke tests: sign-in lands on `/admin/{slug}`; careers and portal under the slug; a queued mail; a scheduled task in the log with `tenant_id`; the private file preview.

For a very large database, migration 2 can be run on its own (`php artisan migrate --step`), then the backfill (migration 3), with its progress watched, and then migration 4.

### 3.1 Validation (read-only)

| Check | Command or query | Expected |
|---|---|---|
| Integrity | `php artisan tenancy:verify --all` | every check 0: null tenants, every reference (composite and in-app), polymorphic owners, role assignments, memberships |
| Tenant #1 | `tenants` | one row, the chosen slug, `active` |
| Memberships | `tenant_memberships` | one per login, linked to that login's employee |
| Roles | `roles`, `model_has_roles` | all with Tenant #1 |
| Data unchanged | row counts and a checksum of every original column, before and after (rehearsal script) | identical, except `migrations` |
| Sequences | `code_sequences` | the same `last_number` per key, now for Tenant #1 |

## 4. Rollback

- **Rule (unchanged, P810-A4-01):** restore the pre-release backup (database and storage) into an empty database, then redeploy the previous image tag.
- `down()` is not a data rollback. Migrations 3 and 4 cannot be reversed safely once a second tenant exists.
- Before migration 4 has run, migrations 2 and 1 can be rolled back structurally. This is for development only.

## 5. Rehearsals (development copies)

Both on MySQL 8.4.11. The source database was copied with `mysqldump` into a throwaway database. The pending release-candidate migrations and the four SaaS-1 migrations were then run, followed by `tenancy:verify`. Row counts and a CRC32 checksum over every original column were compared before and after.

| | R1: copy of the development database `hrms` | R2: copy of the 100k benchmark `hrms_p87_perf` |
|---|---|---|
| Rows (120 tables) | 1,387 | 678,220 (100,063 candidates, 100,062 applications, 271,179 stage histories, 17,830 audit rows) |
| Starting state | 160 of 164 migrations | 160 of 164 migrations |
| Whole `migrate` run | 35 s | 107 s |
| Expand (migration 2) | 13 s | 10 s |
| Backfill (migration 3) | 0.8 s | 50 s |
| Validate + contract (migration 4) | 18 s | 43 s |
| `tenancy:verify` | 386 checks, **0 violations** | 408 checks, **0 violations** |
| Data unchanged | 120 / 120 tables identical (only `migrations` changed) | 120 / 120 tables identical |
| Tenant #1 | `main`, active, `Asia/Kolkata`, INR; 11 / 11 memberships; 7 / 7 roles and 11 / 11 role assignments on Tenant #1 | as R1 |

**Query plans** at 100k, before and after, for the main tenant-scoped list, scope and lookup queries (candidates, applications, requisitions, employees, interviews, offers, audit logs, AI chunks, notifications, hierarchy, stage histories): **unchanged**, with the same index choices and row estimates. With a single tenant, `tenant_id` adds no selectivity. Composite list indexes for many small tenants should be measured in SaaS-7.

**Not rehearsed:**
- An upgrade starting from production's 75-migration state: no production copy exists.
- A full production-size copy.
- Both need a production copy (D8.9-007…010, 028).

## 6. If Phase 8.11 and SaaS-1 ship together (OWNER DECISION)

One upgrade would run 89 + 4 migrations. The drain, backup and restore rules are the same. The rehearsal above started from the release candidate's 160-migration state, not from production's 75. A combined upgrade needs a rehearsal from a production copy first.

## 7. Next migration steps (later phases, not run)

- **SaaS-2:**
  - Move the employee link onto `tenant_memberships` (one identity, several employing tenants).
  - Then make `users.employee_id` nullable and stop using it as the source.
  - Invitations of existing identities; tenant-specific MFA policy.
- **SaaS-5:** move the legacy (pre-tenancy) file paths of Tenant #1 under `tenants/{id}/` with a reviewed copy-then-repoint migration (no deletion until verified); move employee photos to private storage.
- **SaaS-7:** tenant-leading list indexes after measuring multi-tenant plans; partitioning only if measured.
