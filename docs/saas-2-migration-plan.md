# SaaS-2 — Migration Plan: Access and Employee Links Move to Memberships

**For:** the release owner and Operations (who run it), and Engineering (who support it).

**Status:**
- Rehearsed on MySQL 8.4.11 copies of the development database and of the 100k benchmark (§5).
- **Not run against production, and not to be run until approved.**
- The SaaS-2 migrations come after the four SaaS-1 migrations. They need SaaS-1 (Tenant #1 and its memberships) in the same upgrade or an earlier one.

## 1. What changes in the database

Three migrations, in order: expand → backfill + validate → contract.

| # | Migration | Step | What it does | Reversible by `down()`? |
|---|---|---|---|---|
| 1 | `2026_10_04_070949_expand_identity_membership_access` | expand | Adds to `tenant_memberships`: `status_changed_at/by`, `status_reason`, `status_source`, `revoked_roles`, `is_default`, `joined_at`, `last_selected_at`. Adds `tenants.mfa_required`, `users.disabled_at/reason`. New tables `tenant_invitations` (tenant-owned), `platform_operators`, `support_access_grants` (tenant-owned). Additive only. | yes (drops them) |
| 2 | `2026_10_04_070951_move_staff_access_to_tenant_memberships` | backfill + validate | **Refuses to start** (counts only, never data) unless the mapping is deterministic (§2). Copies the employee link and the access state onto the memberships, in batches of 5,000 by id, and marks the default membership. **Refuses to finish** unless every link and state was moved. Creates or deletes nothing. | no-op; the user columns are still intact until step 3 |
| 3 | `2026_10_04_070954_contract_user_tenant_columns` | contract | Refuses unless every login's employee link is on a membership. Adds a unique key on `tenant_memberships.employee_id` (one login per employee record). **Drops** `users.employee_id`, `access_status`, `access_changed_at`, `access_changed_by`, `access_reason`, `access_source`, `revoked_roles`. | development only: re-creates the columns and copies the default membership back (rehearsed, §5). Data rollback is a restore (§4). |

## 2. The mapping

**Proven by step 2 before and after.**

| Before (identity) | After (membership) | Rule |
|---|---|---|
| `users.employee_id` = E (employee of tenant T) | the membership of T: `employee_id` = E, `is_default` = true | SaaS-1 already mirrored E onto that membership. Step 2 fills it where it is missing. |
| `users.access_status` (+ changed at / by / reason / source, revoked roles) | the same values on **every** membership of the user that was not already revoked | SaaS-1 applied the identity's state in every tenant, so effective access is unchanged everywhere. |
| membership already `revoked` (SaaS-1) | stays revoked | — |

**Step 2 refuses to run if any of these is not zero:**
- logins whose employee's tenant has no membership;
- memberships linked to an employee other than the login's;
- employees linked to more than one membership;
- unknown membership statuses;
- unknown access states.

**What this guarantees:**
- every identity stays one row with the same id and email;
- no user is created, so no duplicate is possible;
- no membership is created or deleted;
- no role assignment changes;
- nobody gains or loses access in any tenant (the state is copied as is).

## 3. Before running it (OWNER ACTION REQUIRED)

1. **The same preconditions as SaaS-1** (`saas-1-migration-plan.md` §2): an approved release, Tenant #1 settings, a verified backup, the URL-change communication.
2. **Communicate the onboarding change:**
   - Administrators now **invite** people (Users → Invite member).
   - New staff choose their own password from the invitation email.
   - Candidate conversion sends an invitation instead of a "set your password" link.
3. **Decide the invitation lifetime** (`IDENTITY_INVITATION_TTL_HOURS`, default 72).
4. **Name the platform operators** who may run `platform:operator` and `identity:disable` (decision register D-S2-O1).
5. **Queues:** drain the workers first, as for SaaS-1. A pending "set your password" mail queued before the upgrade still works: it is a normal password-reset link.

## 4. Running it, validating it, rolling back

**Run** it inside the SaaS-1 release procedure (`saas-1-migration-plan.md` §3). The three SaaS-2 migrations run in the same `migrate` after the SaaS-1 ones.

**Validation (read-only):**

| Check | Command or query | Expected |
|---|---|---|
| Tenancy integrity | `php artisan tenancy:verify --all` | every check 0 |
| Every login has its membership | `select count(*) from users u where not exists (select 1 from tenant_memberships m where m.user_id = u.id)` | 0 |
| Employee links | `select count(*) from tenant_memberships where employee_id is not null` | the old count of `users.employee_id` |
| Access states | `select status, count(*) from tenant_memberships group by status` | the old `users.access_status` distribution |
| Columns gone | `show columns from users like 'access_status'` | empty |
| Sign-in | a staff smoke test: sign-in lands on the person's tenant; Users → Invite member sends a mail | — |

**Rollback:**
- The rule is unchanged (P810-A4-01): restore the pre-release backup and redeploy the previous image.
- `down()` is for development: it restores the columns from the default membership. That is correct only while every identity has a single membership.

## 5. Rehearsals (MySQL 8.4.11, throwaway copies)

**Method:**
1. Each source database was copied table by table into a throwaway `hrms_saas2_r*` database on the same server: structure from `SHOW CREATE TABLE`, rows with `INSERT … SELECT`. The source is only read.
2. The pending release-candidate, SaaS-1 and SaaS-2 migrations were run.
3. Every user was compared, field by field, with its membership.
4. Every original column of every table (except the moved user columns) was compared before and after with a CRC32 sum.
5. Each copy was dropped afterwards.

| | R1: development `hrms` | R1b: R1 with mixed access states | R2: 100k benchmark `hrms_p87_perf` |
|---|---|---|---|
| Starting state | 160 of 171 migrations | same | 160 of 171 migrations |
| Users before → after | 11 → 11 | 11 → 11 | 511 → 511 |
| Access states before | 11 active | 8 active, 2 suspended, 1 revoked | 511 active |
| Memberships | 11, one per user, all Tenant #1 | 11 | 511 |
| Employee links moved | 6 / 6 (6 defaults) | 6 / 6 | 111 / 111 (111 defaults) |
| Access state copied (status, when, who, reason, source, revoked roles) | 11 / 11 | 11 / 11 (suspended and revoked exact) | 511 / 511 |
| Unmatched or wrong rows | 0 | 0 | 0 |
| Duplicate emails | 0 | 0 | 0 |
| Role assignments | 11 → 11, identical | 11 → 11, identical | 111 → 111, identical |
| Tables otherwise unchanged | 119 / 119 (users' remaining columns identical) | 119 / 119 | 119 / 119 (678k rows) |
| `tenancy:verify --all` | 389 checks, 0 violations | 389 checks, 0 violations | 413 checks, 0 violations |
| SaaS-2 step times (expand / backfill / contract) | 2 s / 0.04 s / 1 s | 2 s / 0.02 s / 1 s | 1 s / 0.05 s / 0.8 s |
| Whole `migrate` (release candidate + SaaS-1 + SaaS-2) | 65 s | — | 107 s |
| `down()` × 3, then `up()` × 3 | restored columns (6 links, 11 states), re-applied, re-verified clean | — | — |

**Found by rehearsal and fixed:** `down()` of step 3 failed on MySQL, because the employee foreign key relied on the unique index being dropped. It now adds a plain index first, before touching `users`. SQLite never showed this.

**Not rehearsed** (same gaps as SaaS-1, D8.9-007…010):
- a production copy;
- an upgrade from production's 75-migration state.

Both need a production copy.
