# Client #1 Migration Repair Report

**Date:** 2026-10-08 · **For:** release owner, database administrator / infrastructure, engineering
**Scope:** repair and prove the Client #1 old-release → current-release migration path. Production was not accessed, queried or changed. Nothing was pushed or deployed.

**Final status: REHEARSAL READY** (§19)

---

## 1. Current HEAD

| | |
|---|---|
| Branch | `production` |
| Commit | `06dc292` ("table missign") + the uncommitted repair in §8 |
| Code that failed in production | `3e2f2fb` (`main`): its `grant_phase_four_permissions.php:28` is the frame in the production stack |
| Earlier attempt on this branch | `67b91ff` / `06dc292` (`Role::query()->firstOrCreate()`); superseded by §7 |
| Client #1 old release | `9cba8e3` line (also hotfix `599f0c5`): 75 migrations, last `2026_09_14_124342_create_interviewers_table` (`docs/production-bring-up-stage-2b-production-facts.md` §C6) |
| Current release | 179 migrations, last `2026_10_05_160000_add_saas_7_tenant_time_indexes` |

## 2. Failed migration

`2026_09_25_135221_grant_phase_four_permissions` (migration 82 of 179).

```
SQLSTATE[42S22]: Column not found: 1054 Unknown column 'tenant_id' in 'where clause'
select * from `roles` where (`tenant_id` is null or `tenant_id` is null) and `name` = employee and `guard_name` = web limit 1
database/migrations/2026_09_25_135221_grant_phase_four_permissions.php:28  Spatie\Permission\Models\Role::findOrCreate()
```

Reproduced byte-for-byte on an isolated MySQL 8.4.11 copy of a `9cba8e3` database (§10, R1 step 1): migrations 76–81 applied in batch 2, then the same SQL, the same line.

## 3. Exact root cause

1. **Old release.** `9cba8e3` ran with `permission.teams = false`. Its `roles` table is `id, name, guard_name, created_at, updated_at` with `unique(name, guard_name)`. Its seeder creates five roles — `chro`, `vp_hr`, `manager`, `assistant_manager`, `recruiter` — and **no `employee` role**.
2. **Current code.** SaaS-1 set `permission.teams = true` with `team_foreign_key = tenant_id` (`config/permission.php`). Configuration is not versioned with the schema: every migration the current code runs — including historical ones — runs with spatie teams on.
3. **The Phase 4 migration.** For an existing organisation without an `employee` role it called `Spatie\Permission\Models\Role::findOrCreate('employee')`. With teams on, spatie's `findByParam()` adds `(tenant_id IS NULL OR tenant_id = getPermissionsTeamId())`; `migrate` runs outside any tenant, so the team id is null.
4. **The schema at that point.** `roles.tenant_id` is only added by `2026_10_04_025113_add_tenant_ownership_columns` (migration 166), 84 migrations later. MySQL rejects the query → 1054.

**Why it was not caught:**
- A fresh install has no roles at migration 82, so the branch never runs (fresh installs and the whole test suite migrate from empty).
- The SaaS-1 rehearsals started from 160 migrations (Phase 4 already applied). An upgrade from production's 75 was recorded as **not rehearsed** (`docs/saas-1-migration-plan.md` §5–6).
- On SQLite the defect is invisible: an unknown double-quoted identifier (`"tenant_id"`) is read as a string literal, and Eloquent silently drops the non-column `tenant_id` attribute on insert. The original code passes every SQLite data check; only MySQL fails (proven, §12).

## 4. Migration timeline (old release → current)

| # | Migration(s) | Schema available | Role behaviour of the current code | Tenant context | Team-aware role queries? |
|---|---|---|---|---|---|
| 1–75 | old release (`9cba8e3`) | `roles(id, name, guard_name, timestamps)`, `unique(name, guard)`; pivots without `tenant_id` | ran under the old code: teams **off** | none | no |
| 76–81 | Phase 4 tables + pipeline columns | + recruitment stage / pipeline tables | not used | none | — |
| **82** | **`grant_phase_four_permissions`** | **unchanged roles: no `tenant_id`, no `key`** | **teams on: spatie `Role::findOrCreate/findByName/create` add `tenant_id` → FAILED** | none | **yes — mismatch** |
| 123–137 | Phase 5–8.3 grants | same | `Role::query()->where('name')` adds no team predicate | none | no (safe) |
| 139 | `add_key_to_roles_table` | + `roles.key` (unique), `is_protected`; seeded roles (incl. `employee`) keyed by name | query builder | none | no |
| 140, 149 | Phase 8.4 / 8.5 grants | same | `App\Models\Role::byKey()` checks `Schema::hasColumn('roles','tenant_id')` first → no filter. **8.5 requires `offers.manage` and `performance.view` to exist** | none | no (safe) |
| 165 | `create_tenants_table` | + `tenants`, `tenant_memberships` | — | none | — |
| 166 | `add_tenant_ownership_columns` | + nullable `tenant_id` on `roles`, `model_has_roles`, `model_has_permissions` and every tenant table | — | none | schema now matches teams |
| 167 | `backfill_tenant_one` | Tenant #1 (`TENANT_ONE_*`, default slug `main`); every row, role, assignment → Tenant #1; one membership per login | query builder (`TenantBackfill`) | none | — |
| 168 | `enforce_tenant_ownership` | `tenant_id` NOT NULL; roles unique per tenant (`tenant,key` / `tenant,name,guard`); composite FK assignment → role | — | none | — |
| 169–171 | SaaS-2 identity | access state and employee link move to `tenant_memberships`; `users.employee_id` and access columns dropped | query builder | none | — |
| 172–179 | SaaS-3 … SaaS-7 | plans, billing, platform, API, indexes | query builder | none | — |

There is no migration that "enables team mode": team mode is configuration, on for the current code at every step. The schema catches up at 166–168. Anything between 1 and 165 that uses spatie's team-aware finders breaks an upgrade; only migration 82 did.

**At migration 82:** schema = the old `roles` table (no `tenant_id`, no `key`). `App\Models\Role` / spatie with teams on = every static finder filters by a column that does not exist.

## 5. Schema / model mismatch

| Call in the old Phase 4 migration | Depends on | Safe at 82? |
|---|---|---|
| `Role::findOrCreate('employee')` | spatie teams predicate on `roles.tenant_id` | **No** (the failure) |
| `Role::query()->where('name')->first()` | plain Eloquent | yes |
| `Permission::findOrCreate()` | `TenantPermissionRegistrar` (tenant-aware permission map; selects `roles.tenant_id` when a tenant context exists) | only because `migrate` has no tenant |
| `$role->givePermissionTo()` | spatie role pivot (not team-scoped) | yes |
| `RolePermissionSeeder::PHASE_4_ROLE_PERMISSIONS` | a live application constant | yes, but not frozen |

## 6. Recommended repair approach

**Chosen: A / D — migration-specific query-builder operations against the schema at that point**, following the project's own precedent (`2026_10_04_144459_grant_billing_permissions`).

| Option | Verdict |
|---|---|
| A / D. Query builder (`DB::table`) in the historical migration | **Chosen.** Depends only on `roles`, `permissions`, `role_has_permissions` as they exist at 82; no spatie model, no team resolver, no permission map; identical on MySQL and SQLite; insert-if-missing → idempotent over production's partial state; existing ids never touched. |
| B. Migration-specific Eloquent Role/Permission models | Same result with more code and still Eloquent mass-assignment behaviour. Rejected. |
| C. Move the grant after SaaS-1 | Changes the order of an already-released history; the `employee` role would miss the name-based keying at 139 and need tenant-aware creation per tenant; databases that already passed 82 would diverge. Rejected. |
| E. Keep `67b91ff` (`Role::query()->firstOrCreate()`) | Works today, but still runs through spatie's model and the tenant-aware permission registrar — the class of dependency that caused the failure. Superseded. |

## 7. Code changes made

`2026_09_25_135221_grant_phase_four_permissions` rewritten, same behaviour, no spatie models:
- A **frozen copy** of the Phase 4 role → permission map (verified identical to `RolePermissionSeeder::PHASE_4_ROLE_PERMISSIONS`).
- Permissions: found by `name` + guard `web`, inserted only if missing (same creation order, so the same ids as before on a fresh install).
- Roles: found by `name` + guard `web` (lowest id). The `employee` role is inserted **only** when roles exist and no `employee` role does — exactly the original rule.
- Grants: only the missing `role_has_permissions` rows are inserted; nothing is ever removed or synced. CHRO receives the union, as before.
- The spatie cache is still invalidated (`forgetCachedPermissions()`, cache only).

Re-running it after an interruption — production's situation — only adds what is still missing.

No other migration, model, configuration, permission set, role name or authorisation rule changed.

## 8. Files changed

| File | Change |
|---|---|
| `database/migrations/2026_09_25_135221_grant_phase_four_permissions.php` | the repair (§7) |
| `tests/Feature/RolePermissionSeederTest.php` | 3 new tests: interrupted upgrade completes without duplicates; an existing `employee` role keeps its id; a fresh install creates no role (plus the existing pre-tenancy test from `67b91ff`) |
| `tests/Feature/Tenancy/LegacyReleaseUpgradeTest.php` | new: a 75-migration database with old-release data upgrades through every migration in fresh `artisan migrate` processes; runs on the suite's driver (MySQL on a MySQL run) |
| `docs/runbooks/client-1-migration-diagnostic.sql` | new: read-only diagnostic for a production copy (§18) |
| `docs/client-1-migration-repair-report.md` | this report |
| `.ai/rules/migrations.md` | new project rule: pre-SaaS migrations use the query builder; prove upgrades on MySQL |

## 9. Fresh-install test result

`migrate:fresh --seed` (179 migrations + `DatabaseSeeder`):

| | MySQL 8.4.11 | SQLite |
|---|---|---|
| Migrations | 179 / 179 | 179 / 179 |
| Tenant #1 | `main`, active | `main`, active |
| System roles | 6: `chro` (key, protected, 78 / 78 permissions), `vp_hr` 71, `manager` 50, `assistant_manager` 33, `recruiter` 26, `employee` (key) 1 | identical |
| Permissions | 78 | 78 |
| Owner login | `EMP-000001` login: Tenant #1 membership, active, role `chro` | identical |
| `tenancy:verify --all` | 410 checks, 0 violations | 410 checks, 0 violations |
| vs. the original (pre-fix) migration | **identical**: migrations, roles, permission ids, grants, assignments, memberships, tenancy | — |

`tenant_memberships.is_owner` is empty on both paths, by design (`docs/saas-3-migration-plan.md` §60: the owner of an existing tenant is an owner decision).

## 10. Old-release upgrade rehearsal result

**Old release reconstructed from its own code**: a git worktree of `9cba8e3` with its own `composer.lock`, configuration (teams off) and seeders built the 75-migration schema in an isolated local MySQL 8.4.11 database (`c1_rehearsal_baseline`), then `DatabaseSeeder` and a synthetic Client #1 dataset ran through that release's own models and factories (§13). The result was dumped and each rehearsal restored the dump into its own database. Production was never used.

| Rehearsal | Start | Code | Result |
|---|---|---|---|
| **R1 step 1** — reproduce | 75 migrations | original (`3e2f2fb`) | **fails exactly like production** at 82 after 76–81 (batch 2); leaves the partial state: 81 recorded, 9 Phase 4 permissions created (33 → 42), `vp_hr` / `manager` / `assistant_manager` / `recruiter` granted, `chro` not, no `employee` |
| **R1 step 2** — continue (production's path) | R1's failed state | repaired, via `ops:migrate` | **98 / 98 migrations, exit 0**; `tenancy:verify` 404 checks, 0 violations; all preservation checks pass |
| **R2** — clean upgrade | 75 migrations | repaired, via `ops:migrate` | **104 / 104, exit 0**; 404 / 0; all checks pass |
| **R3** — admin-created `employee` role | 75 + an `employee` role (id 9) with a grant and an assignment | repaired | 104 / 104; 404 / 0; role 9 kept (no new role), assignment kept, `referrals.submit` added |
| R1 / R2 re-run on the final file (md5 `9702d457…`) | as R1 step 2 / R2 | repaired | 98 / 98 and 104 / 104; 404 / 0; all checks pass |

**R1 (failed → continued) and R2 (clean) end in an identical state:** roles, permissions, grants, assignments, direct permissions, employees, hierarchy, logins, memberships, tenancy and migration list.

Final roles after the upgrade (R1, R2):

| id | name | key | protected | tenant | permissions |
|---|---|---|---|---|---|
| 1 | chro | chro | yes | 1 | 78 / 78 |
| 2 | vp_hr | vp_hr | | 1 | 71 |
| 3 | manager | manager | | 1 | 50 |
| 4 | assistant_manager | assistant_manager | | 1 | 33 |
| 5 | recruiter | recruiter | | 1 | 25 (the administrator's revocation is kept) |
| 6–8 | hiring_partner, regional_lead, interview_panel (custom) | — | | 1 | 5, 8, 3 (unchanged) |
| 9 | employee (created by migration 82) | employee | | 1 | 1 (`referrals.submit`) |

## 11. MySQL result

- Rehearsals R1–R3 on MySQL 8.4.11: all pass (§10).
- New tests on MySQL (`DB_CONNECTION=mysql`): 9 / 9 pass.
- **Mutation check on MySQL:** with the original migration restored, `LegacyReleaseUpgradeTest` fails with the production error (`1054 Unknown column 'tenant_id'` at `grant_phase_four_permissions`), and the pre-tenancy query test fails. With the repair, both pass.
- Fresh install: §9.

## 12. SQLite result

- New tests on SQLite: 9 / 9 pass. Fresh install: §9.
- Mutation checks on SQLite: "employee role never created", "grant replaces (syncs) a role's permissions" and "employee created despite an existing one" are each caught. The original defect is caught on SQLite only by the pre-tenancy query test (no query may name `tenant_id`), because SQLite cannot fail on the unknown column (§3).

## 13. Data preservation result

Synthetic Client #1 dataset, created by the old release itself: 8 roles (5 seeded + 3 custom, one administrator revocation on `recruiter`), 33 permissions, 123 role grants, 16 role assignments (one login with two roles, one inactive employee's login, one login without an employee), 1 direct permission, 15 logins, 22 employees in a 4-level reporting tree (74 closure rows), 4 departments, 9 designations, 2 locations, 30 candidates, 4 requisitions, 40 applications, 10 interviews, 5 offers, 15 stage histories, 100 audit rows.

Before / after (R1 and R2), every one of the 68 original tables compared on row count and an MD5 over every original column:

| Check | Result |
|---|---|
| All migrations complete, no SQL errors | pass |
| Roles: same ids, names, guards; no duplicates; `employee` exactly once | pass (one role added: `employee`) |
| Permissions: same ids, none lost, no duplicates | pass (45 added) |
| Role grants: none lost (additive only) | pass (151 added) |
| Role assignments and direct permissions identical; no orphans | pass |
| `tenant_id` populated at the backfill stage; Tenant #1 exists; every role, assignment, employee and hierarchy row on Tenant #1 | pass |
| One active Tenant #1 membership per login; every login → employee link preserved | pass |
| Employees (ids, codes, reporting lines, department, designation, location, status) identical | pass |
| Hierarchy closure identical | pass |
| Business keys (candidate, application, requisition, offer, employee codes) identical | pass |
| Original tables otherwise unchanged | 63 / 68 identical. The 5 others are explained: `cache` (new entries), `permissions`, `role_has_permissions`, `roles` (additions only, checked above), `users` (only `employee_id` dropped by SaaS-2 by design; every other column byte-identical; the links are on memberships) |
| Unexpected deletions | none |

## 14. SaaS regression result

PENDING_SUITE

## 15. Security result

- Authorisation outcome unchanged: the repaired migration grants exactly the permission set the original intended (frozen copy verified identical), additively, to the same roles; the `employee` role receives only `referrals.submit`. A fresh install is identical to the original down to permission ids (§9).
- No new permission, role or access path; no change to policies, the gate, `StaffAccessService`, spatie configuration or tenancy.
- The migration builds no SQL from input (constants only).

PENDING_SECURITY

## 16. Concurrency result

PENDING_CONCURRENCY

## 17. Remaining risks

1. **No production copy has been rehearsed.** All evidence uses a reconstructed `9cba8e3` database with synthetic data. Production data shapes not modelled here (unusual NULLs, renamed seeded roles, legacy rows) can still fail a later migration. This is the gate in §18 step 6.
2. **Phase 8.5 precondition.** `2026_09_27_075958` throws `PermissionDoesNotExist` if any role exists but `offers.manage` or `performance.view` does not. Both are in `9cba8e3`'s fixed seed list and that release has no screen that deletes a permission; diagnostic C1.4 must show 2.
3. **Renamed seeded roles.** Phase 4–8.3 find roles by name; migration 139 keys only roles still carrying their seeded name. A seeded role an administrator renamed in the old release gets no Phase 4–8.3 top-ups and no key. A renamed `chro` would leave Tenant #1 without a protected CHRO role. Diagnostic C1.3 lists every role.
4. **Production's code at failure time.** Recorded as `3e2f2fb` from the stack frame; the deployed artifact itself has not been fingerprinted (`docs/production-bring-up-stage-2a-release-evidence.md` §3.1).
5. **Phase 5–8.5 still use spatie models before SaaS-1.** Safe today (no team-aware finder; no tenant during `migrate`; proven by the rehearsals). Recorded as a project rule so no future change reintroduces team-aware calls there.
6. **Size and duration.** The rehearsal database is small (611 rows; 98 migrations in 53 s). Production's run time and lock impact are unmeasured.
7. **Tenant #1 identity** comes from `TENANT_ONE_*` (defaults: slug `main`, name from `APP_COMPANY_NAME`/`APP_NAME`, `Asia/Kolkata`, `INR`, `IN`). These must be set in production's environment before continuing.

## 18. Production recovery instructions (DBA / infrastructure)

Production stays exactly where it is until step 8. **Do not** run `migrate` / `ops:migrate` on production, `migrate:rollback`, `migrate:fresh`, edit the `migrations` table, add `tenant_id`, or create the `employee` role by hand.

1. **Hold.** Keep production at the failed point (81 migrations). Keep the application in maintenance, or on the previous release's code, so nothing runs the current code against the old schema (the `tenant_memberships` 1146 errors come from that).
2. **Back up and verify.** Take a full `mysqldump --single-transaction --routines --triggers` of `fynnrctdb` plus the storage volume, and verify it by restoring into an empty database (`docs/runbooks/backup-restore.md`, `backup-restore-verification.md`). No recovery action happens without a verified backup.
3. **Record the migration table.** `SELECT id, migration, batch FROM migrations ORDER BY id;` — expected: 81 rows, last `2026_09_25_134640_add_pipeline_columns_to_recruitment_tables`, batch 2 for 76–81, `2026_09_25_135221_…` absent.
4. **Record the schema.** `mysqldump --no-data` of `fynnrctdb`, kept with the backup.
5. **Clone and diagnose.** Restore the backup into an isolated rehearsal database (never on the production server's live schema), then run the read-only diagnostic and keep its output:
   `mysql -u <read-only user> -p -h <rehearsal host> <copy> --table < docs/runbooks/client-1-migration-diagnostic.sql > client-1-diagnostic.txt`
   Expected: C1.1 81 / not recorded; C1.2 no `tenant_id`, no SaaS tables; C1.3 no `employee` role and `chro` present; **C1.4 prerequisites = 2**; C1.6 orphans 0; C1.7 no shared employee, anomalies 0. Any difference: stop and send the output to engineering.
6. **Rehearse on the clone** with the repaired release (production's real `.env` values, `TENANT_ONE_*` set, pointing at the clone): record counts (diagnostic C1.3–C1.8), run `php artisan ops:migrate`, then `php artisan migrate:status` (0 pending), `php artisan tenancy:verify --all` (0 violations), re-run the diagnostic's count sections, and compare role ids, assignment counts, permission counts, employees and hierarchy with step 5. Record the wall-clock time.
7. **Validate.** Release owner and engineering accept the step 6 evidence; smoke-test the clone (sign-in lands on `/admin/<tenant-one-slug>`, CHRO sees all menus, a recruiter sees their pipeline).
8. **Only then schedule production continuation** in a maintenance window: re-verify the backup from step 2, deploy the repaired release, run `php artisan ops:migrate` once (it continues from migration 82; the repaired migration completes the partial Phase 4 state without duplicates), then `tenancy:verify --all` and the smoke tests. If anything fails: **restore the step 2 backup** into an empty database and redeploy the previous release (`docs/runbooks/failed-migration.md`); never roll back migrations.

## 19. Client #1 migration readiness

**REHEARSAL READY.**

The repository-level cause is fixed and the OLD RELEASE → CURRENT RELEASE upgrade has completed successfully on isolated MySQL copies, including continuation from production's exact failed state. It is **not** "Ready for Production Migration": no rehearsal has run on a copy of Client #1's real data (§17.1). Steps 2–7 of §18 close that gap.

## 20. Organization-configurability follow-up items (next task; not started)

The repair itself changes none of these; it preserves today's behaviour. Dependencies to carry forward:

| Item | Finding | Dependency for the next task |
|---|---|---|
| Custom roles | Kept with ids and grants, but **unkeyed** (`key` NULL) after migration 139. `RoleAssignmentService` and `Role::byKey()` address roles by key only. | Custom roles need a key strategy (assign at creation / backfill) before configurable organisations rely on them. |
| Role keys | Seeded roles are keyed only if they still carry their seeded name at 139. | A renamed seeded role on Client #1 must be mapped to its key by an explicit, audited decision (`identity:audit` lists unkeyed roles). |
| CHRO | Protected only if a role named `chro` exists at 139. | Confirm from diagnostic C1.3; a missing/renamed CHRO blocks last-CHRO protection and billing grants (`2026_10_04_144459` grants only `key = chro`). |
| Employee role | Created by migration 82 for upgraded organisations, keyed `employee` at 139, holds `referrals.submit`; existing logins are **not** assigned it. | Whether existing staff should receive the base role is a product decision (conversion/rehire grants it to new people only). |
| Tenant #1 owner | No membership is marked owner by migrations or the seeder (by design). | Designate the owner after the upgrade (platform `TenantOwnershipService`; the owner must hold `chro`). |
| Role assignments | Preserved 1:1 onto Tenant #1 memberships. | None from the repair. |
