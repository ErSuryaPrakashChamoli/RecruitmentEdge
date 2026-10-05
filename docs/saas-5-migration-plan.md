# SaaS-5 — Migration Plan: Platform Control Tables and Support Grants

**For:** the release owner and Operations (who run it), and Engineering (who support it).

**Status:**
- Rehearsed on MySQL 8.4.11 copies of the development database and of the 100k benchmark (§6).
- **Not run against production, and not to be run until approved.**
- The two SaaS-5 migrations come after the SaaS-1 to SaaS-4 migrations and need them in the same upgrade or an earlier one: `tenants`, `support_access_grants` (SaaS-2) and `platform_operators`.

## 1. What changes in the database

Two migrations: expand, then backfill + validate. There is **no contract step**: nothing is removed. Two columns are relaxed (made nullable), and nothing is tightened.

| # | Migration | Step | What it does | Reversible by `down()`? |
|---|---|---|---|---|
| 1 | `2026_10_04_193453_expand_platform_control` | expand | **`support_access_grants`** (tenant-owned, existing): adds `status` (default `active`), `requested_scopes`, `scopes`, `requested_minutes`, `requested_at`, `decided_at`, `denial_reason`, `revocation_reason`, `last_used_at`, `use_count`. Makes `starts_at` and `expires_at` nullable: a request has neither yet. Index `(tenant_id, status)`. **New platform tables:** `tenant_deletion_requests`, `compliance_exports`, `platform_events`. | yes: drops the new tables and columns. Never-started grants are dated as ended and revoked, then `starts_at` / `expires_at` are NOT NULL again. |
| 2 | `2026_10_04_193455_backfill_support_access_grant_status` | backfill + validate | Every existing grant gets its status from its own dates: revoked if `revoked_at` is set, expired if `expires_at` has passed, otherwise active. Each gets the least-privileged scope (`diagnostics`) and `decided_at = starts_at`. **Refuses to finish** if any grant is left without a known status, or an active one without scopes. Idempotent. | no-op |

**New tables.** All are platform-classified (`TenantSchema::PLATFORM_TABLES`): they are about a tenant, not owned by it, survive its purge, and are never tenant-scoped.

| Table | Key constraints and indexes | Foreign keys |
|---|---|---|
| `tenant_deletion_requests` | one open request per tenant: `unique (tenant_id, is_open)`, with `is_open` NULL once closed; `(status, purge_after)` for the sweep | `tenant_id` → tenants RESTRICT (NOT NULL); requester, approver, canceller → users SET NULL |
| `compliance_exports` | `(tenant_id, status)`; `(status, expires_at)` for expiry | `tenant_id` → tenants RESTRICT (NOT NULL); requester, last downloader → users SET NULL |
| `platform_events` | `dedupe_key` unique (idempotent events); `(severity, occurred_at)`; `(tenant_id, occurred_at)` | `tenant_id` → tenants RESTRICT (nullable: a platform-wide event concerns no tenant); acknowledger → users SET NULL |

**Intentional tenant-less or nullable-tenant tables:**
- `platform_events.tenant_id` is nullable because some events concern no tenant (a provisioning failure before the tenant row exists, platform-wide alerts).
- The two other tables require a tenant.

**No new tenant-owned table.** Nothing changes in `audit_logs`: the append-only rule is in the model, and actor kind `platform` is a value, not a schema change.

**What this guarantees for existing tenants:**
- No tenant data, membership, role, plan, entitlement or billing row changes (§6: every column of every pre-existing table checksummed).
- Existing support grants keep exactly the access they had, now with the least-privileged scope. Before SaaS-5 they opened nothing at all.
- No deletion, export or event exists until an operator creates one.

## 2. Before running it (OWNER ACTION REQUIRED)

1. **The same preconditions as SaaS-1 to SaaS-4:** an approved release, a verified backup, drained queue workers.
2. **Configuration:**
   - `PLATFORM_NOTIFY_EMAIL` (critical events), and optionally `PLATFORM_BRAND_NAME`;
   - the `platform.*` defaults (decision register §2: support duration, deletion grace, retention, second-operator rule, export retention).
3. **Platform operators:**
   - name them and grant their roles with `platform:operator grant <email> <role>`;
   - each enrols MFA at the first `/platform` sign-in.
4. **Owner decisions required before production** (decision register §2): D-S5-O1…O13.

## 3. Running it, validating it, rolling back

**Run:** inside the release procedure. The two SaaS-5 migrations run in the same `migrate`, after the SaaS-4 ones.

**Then, once per tenant:** `php artisan tenants:run files:privatize-employee-photos --all`.
- It moves employee photos to the private disk, keeping every path, and is idempotent.
- Until it runs, photos are still read from the public disk; nothing breaks.
- Suspended or closed tenants are not visited by `--all`. Their photos stay public until the purge, or until they are run individually once usable.

**Validation (read-only):**

| Check | Command or query | Expected |
|---|---|---|
| Tenancy integrity | `php artisan tenancy:verify --all` | every check 0 |
| Grants backfilled | `select status, count(*) from support_access_grants group by status` | only `active` / `expired` / `revoked` |
| Grants scoped | `select count(*) from support_access_grants where scopes is null` | 0 |
| No platform work yet | `select count(*) from tenant_deletion_requests`; `… compliance_exports` | 0; 0 |
| Plans unchanged | `select count(*) from tenant_plan_assignments where is_current = 1` | the number of tenants |
| Sweep | `php artisan platform:sweep` | succeeds; 0 purges queued |
| Panel | an operator signs in at `/platform` (MFA set-up first) and sees the tenant list | — |
| Photos | `php artisan storage:audit` after the photo move | no missing employee photo |

**Rollback:**
- The rule is unchanged (P810-A4-01): restore the pre-release backup and redeploy the previous image.
- `down()` (development) drops the platform tables, with any deletion, export and event records, and the new grant columns. Rehearsed (§6).
- **A purge cannot be rolled back.** No purge can run before a deletion has been requested, approved by a second operator, and waited out its grace period (30 days by default).

## 4. Data migration notes

- **Backfill:** only `support_access_grants` rows, from their own columns. No other table is written.
- **Idempotent:** running the backfill again changes nothing.
- **Expected row counts after the migration:**
  - every pre-existing table unchanged;
  - the three new tables empty;
  - `support_access_grants` the same number of rows, with the new columns filled.

## 5. Purge-related considerations

- **Order.** The purge deletes children before parents. The order is derived from the live foreign keys (`TenantPurgePlan`), so it holds for MySQL's RESTRICT constraints and the SaaS-1 composite keys. Proven on MySQL with real data (§6 R1b, R2).
- **New foreign keys.** A future migration that adds a foreign key from a retained, platform or global table to a tenant table must use `nullOnDelete()`, or the purge plan refuses to run (`.ai/rules/migrations.md`). A new tenant table is purged automatically once it is classified in `TenantSchema`.
- **Retained tables.** `platform.deletion.retain_tables` keeps evidence tables: audit, billing, support grants, plan history. Their own foreign keys to purged tables are SET NULL or absent (the plan checks this).
- **Long purges.** Each table is deleted in chunks of `platform.deletion.chunk` rows. The lease is renewed after each table. A worker that stops is resumed by `platform:sweep` once its lease expires (15 minutes).
- **Files** are deleted from the configured disks. Purging on a copy of the database is safe only with the disks pointed elsewhere (the rehearsals did that).

## 6. Rehearsals (MySQL 8.4.11, throwaway copies)

**Method:**
1. Each source database was copied table by table into a throwaway `hrms_saas5_r*` database on the same server: structure from `SHOW CREATE TABLE`, rows with `INSERT … SELECT`. The source is only read.
2. The copy was migrated **up to SaaS-4 only** (all migrations but the two SaaS-5 ones).
3. The copy was snapshotted:
   - every column of every table, with a CRC32 sum;
   - every tenant's effective entitlements and effective status.
4. The SaaS-5 migrations were run.
5. The snapshot was compared with the result, and the grant backfill was checked.
6. `tenancy:verify --all` and `platform:sweep` were run.
7. The migrations were rolled back (`down()` × 2) and the result compared with the snapshot again, then re-applied and compared again.
8. R1b and R2 then deleted a populated tenant through the real workflow: cancel → request → approval by a second operator → purge, with the file disks pointed at an empty temporary directory. Every row of every other tenant, every global table, and the deleted tenant's retained rows were checksummed before and after.
9. Each copy was dropped afterwards.

All three were run again on the final code (after the review fixes, which added `tenant_deletion_requests.failures`), with the same results. The figures below are from that final run.

| | R1: development `hrms` | R1b: R1 with mixed states, then a purge | R2: 100k benchmark `hrms_p87_perf`, then a purge |
|---|---|---|---|
| Starting state | 160 → 175 (SaaS-4) → 177 migrations | same, plus 4 tenants provisioned on the copy (trial; past due with an entitlement override; suspended; cancelled) and 15 legacy SaaS-2 grants (per tenant: active, expired, revoked) | 160 → 175 → 177 |
| Tenants / memberships / role assignments / current plans | 1 / 11 / 11 / 1, unchanged | 5 / 11 / 11 / 5, unchanged | 1 / 511 / 111 / 1, unchanged |
| Effective entitlements and status, every tenant | identical before and after | identical (5 tenants) | identical |
| Pre-existing tables unchanged (all columns) | 137 / 137 | 137 / 137 | 137 / 137 |
| Grant backfill | no grants | 15 grants: 5 active, 5 expired, 5 revoked; 0 status mismatches; 0 without scopes | no grants |
| `tenancy:verify --all` | 400 checks, 0 violations | 403 checks, 0 violations | 422 checks, 0 violations |
| SaaS-5 step times (expand / backfill) | ≈ 0.7 s / 0.003 s | 0.54 s / 0.009 s | 0.70 s / 0.003 s (SaaS-1…4 catch-up on the copy: 107 s) |
| `platform:sweep` | succeeds; nothing to do | succeeds; nothing to do | succeeds; nothing to do |
| `down()` × 2, then `up()` × 2 | back to 175 migrations, 0 tables changed, platform tables gone; re-applied, 0 changed, 0 violations | same; the 15 grants re-backfilled identically | back to 175, 0 changed; re-applied, 0 changed, 0 violations |
| Purge | — | Tenant #1 (`main`): 635 rows in 36 tables, 11.9 s; 0 rows left; 133 other tables unchanged; retained: 189 audit, 3 support grants, 1 plan assignment; 14 identities kept; 403 checks, 0 violations after | the 100k tenant (`main`), with 999 more tenants added to the copy: **651,139 rows in 42 tables in 67 s** (largest: 271,179 stage histories, 100,063 candidates, 100,062 applications); 0 rows left; 133 other tables (every other tenant, every global table) unchanged; retained: 17,839 audit, 1 grant, 1 plan assignment; 514 identities kept; 410 checks, 0 violations after |

**Not rehearsed** (the same gaps as SaaS-1 to SaaS-4):
- a production copy, including its storage, for the photo move and the S1-07 legacy paths;
- an upgrade from production's actual migration state.

Both need a production copy. **The production-copy rehearsal remains a release gate (D-S5-O12).**
