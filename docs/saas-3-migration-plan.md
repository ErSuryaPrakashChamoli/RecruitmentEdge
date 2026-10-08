# SaaS-3 — Migration Plan: Plan Catalog and Tenant Commercial State

**For:** the release owner and Operations (who run it), and Engineering (who support it).

**Status:**
- Rehearsed on MySQL 8.4.11 copies of the development database and of the 100k benchmark (§5).
- **Not run against production, and not to be run until approved.**
- The two SaaS-3 migrations come after the SaaS-1 and SaaS-2 migrations and need them (tenants, memberships, invitations) in the same upgrade or an earlier one.

## 1. What changes in the database

Two migrations: expand, then backfill + validate. There is **no contract step**: nothing is removed, renamed or tightened, so there is nothing to contract.

| # | Migration | Step | What it does | Reversible by `down()`? |
|---|---|---|---|---|
| 1 | `2026_10_04_100354_create_plan_catalog_and_tenant_commercial_state` | expand | New platform tables `plans`, `plan_versions`, `plan_entitlements`. New tenant tables `tenant_plan_assignments`, `tenant_entitlement_overrides`. Adds to `tenants`: `status_reason`, `trial_started_at`, `trial_ends_at`, `entitlement_version` (default 1), `provisioned_at`, `provisioning_state`, `provisioning_error`, index `(status, trial_ends_at)`. Adds `tenant_memberships.is_owner` (nullable, unique per tenant) and `tenant_invitations.grants_ownership` (default false). Additive only; no existing value changes. | yes (drops them) |
| 2 | `2026_10_04_100355_assign_legacy_plan_to_existing_tenants` | backfill + validate | Creates the internal `legacy` plan v1 if missing (every feature on, both limits explicitly unlimited — a frozen copy in the migration). Gives every tenant without a current assignment exactly one (`source = migration`), and sets `provisioned_at` where empty. **Refuses to finish** unless every tenant has exactly one current assignment. Idempotent. Changes no tenant's status. | no-op (step 1's `down()` removes everything) |

**Constraints and indexes:**
- `plans.code` unique; `plan_versions (plan_id, version)` unique; `plan_entitlements (plan_version_id, key)` unique.
- `tenant_plan_assignments (tenant_id, is_current)` unique: one current assignment (history rows have `is_current = NULL`, which a unique index allows any number of). History index `(tenant_id, effective_from)`.
- `tenant_entitlement_overrides (tenant_id, key, is_current)` unique: one current override per key.
- `tenant_memberships (tenant_id, is_owner)` unique: one owner per tenant.
- Foreign keys (restrict on delete): assignments → tenants, plan versions; overrides → tenants; versions → plans; entitlements → versions. Actor columns (`assigned_by`, `created_by`, `revoked_by`) → users, set null on delete.

**What this guarantees for existing tenants:** every capability they have today stays, with no limit; their status, members, roles and data are untouched; their plan history starts with one "migration" row.

## 2. Before running it (OWNER ACTION REQUIRED)

1. **The same preconditions as SaaS-1 and SaaS-2** (`saas-1-migration-plan.md` §2, `saas-2-migration-plan.md` §3): an approved release, a verified backup, drained queue workers.
2. **Nothing commercial changes at upgrade.** Do not assign a commercial plan to an existing tenant in the release itself (decision register D-S3-O5).
3. **After the migrations, publish the catalog:** `php artisan plans:sync` (idempotent; also run by the seeder). Until the commercial catalog is decided (D-S3-O1), do not provision customers on `starter` / `growth` / `enterprise`.
4. **Schedule:** `tenants:lifecycle-sweep` runs hourly through the existing scheduler (platform task). Correctness does not depend on it.
5. **Name the operators** who may run `plans:*` and `tenants:*` (D-S3-O6).

## 3. Running it, validating it, rolling back

**Run** it inside the SaaS-1/SaaS-2 release procedure: the two SaaS-3 migrations run in the same `migrate`, after the SaaS-2 ones.

**Validation (read-only):**

| Check | Command or query | Expected |
|---|---|---|
| Tenancy integrity | `php artisan tenancy:verify --all` | every check 0 |
| Every tenant has one current plan | `select count(*) from tenants t where (select count(*) from tenant_plan_assignments a where a.tenant_id = t.id and a.is_current = 1) <> 1` | 0 |
| Every tenant is on legacy v1 | `php artisan tenants:usage` | every tenant listed, every limit "unlimited", no OVER LIMIT |
| No status changed | `select status, count(*) from tenants group by status` | as before the release |
| Catalog | `php artisan plans:sync` | "Published: …" the first time, then "nothing new" |
| Smoke test | a staff sign-in; Plan & usage page shows "Legacy (all features, no limits)" | — |

**Rollback:**
- The rule is unchanged (P810-A4-01): restore the pre-release backup and redeploy the previous image.
- `down()` (development) drops the SaaS-3 tables and columns; the data they held (assignments, overrides, trial dates) is lost with them. Rehearsed (§5).

## 4. Data migration notes

- **Backfill size:** one assignment row per tenant (today: Tenant #1 only). No table scan of tenant data.
- **Idempotency:** re-running step 2 creates nothing (it only fills tenants without a current assignment).
- **No counters to backfill:** usage is counted from the records on demand (D-S3-08).
- **Ownership:** no membership is marked owner by the migration (`is_owner` stays empty). The owner of an existing tenant is an owner decision; nothing in SaaS-3 depends on an existing tenant having one.

## 5. Rehearsals (MySQL 8.4.11, throwaway copies)

**Method** (as for SaaS-2):
1. Each source database was copied table by table into a throwaway `hrms_saas3_r*` database on the same server: structure from `SHOW CREATE TABLE`, rows with `INSERT … SELECT`. The source is only read.
2. The pending release-candidate, SaaS-1, SaaS-2 and SaaS-3 migrations were run (160 → 173).
3. Every original column of every table was compared before and after with a CRC32 sum (SaaS-2's moved user columns excepted).
4. Every tenant was checked for exactly one current assignment, to legacy v1, from the migration; the legacy version's six entitlements were read back.
5. Each copy was dropped afterwards.

| | R1: development `hrms` | R2: 100k benchmark `hrms_p87_perf` |
|---|---|---|
| Starting state | 160 of 173 migrations | 160 of 173 migrations |
| Tenants | 1 (`main`, active) — status unchanged | 1 (`main`, active) — status unchanged |
| Current plan assignments | 1 / 1 tenant, legacy v1, source `migration` | 1 / 1 tenant, legacy v1, source `migration` |
| Legacy v1 | internal, published; 4 features enabled; both limits `is_unlimited` | same |
| Overrides / owners set / ownership invitations | 0 / 0 / 0 | 0 / 0 / 0 |
| Tables otherwise unchanged | 119 / 119 (users' remaining columns identical; 11 → 11 users) | 119 / 119 (511 → 511 users) |
| `tenancy:verify --all` | 393 checks, 0 violations | 415 checks, 0 violations |
| SaaS-3 step times (expand / backfill) | 6 s / 0.03 s | 6 s / 0.03 s |
| Whole `migrate` (release candidate + SaaS-1 + SaaS-2 + SaaS-3) | 55 s | 185 s |
| `tenants:usage` | 6 active requisitions, 11 seats, both unlimited | 521 active requisitions, 511 seats, both unlimited |
| `plans:sync` afterwards | published starter, growth, enterprise; **legacy v1 unchanged** (the migration's frozen copy equals the catalog); a second run published nothing | — |
| `down()` × 2, then `up()` × 2 | SaaS-3 tables and columns removed (171 migrations); re-applied; re-verified clean (393 checks, 0 violations) | — |

The machine was also running the SQLite suite during the rehearsals, so the times are pessimistic. The SaaS-3 expand time is dominated by the `ALTER TABLE` on `tenants`, `tenant_memberships` and `tenant_invitations` (small tables).

**Not rehearsed** (same gaps as SaaS-1 and SaaS-2):
- a production copy;
- an upgrade from production's 75-migration state.

Both need a production copy. **The production-copy rehearsal remains a release gate.**
