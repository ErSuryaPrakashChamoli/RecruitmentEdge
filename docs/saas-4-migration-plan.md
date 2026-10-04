# SaaS-4 — Migration Plan: Billing Tables and Permissions

**For:** the release owner and Operations (who run it), and Engineering (who support it).

**Status:**
- Rehearsed on MySQL 8.4.11 copies of the development database and of the 100k benchmark (§5).
- **Not run against production, and not to be run until approved.**
- The two SaaS-4 migrations come after the SaaS-1, SaaS-2 and SaaS-3 migrations and need them (tenants, roles per tenant, plan versions) in the same upgrade or an earlier one.

## 1. What changes in the database

Two migrations: expand, then backfill + validate. There is **no contract step**: nothing is removed or tightened.

| # | Migration | Step | What it does | Reversible by `down()`? |
|---|---|---|---|---|
| 1 | `2026_10_04_144458_create_billing_tables` | expand | New platform tables `billing_prices`, `billing_invoice_sequences`. New tenant tables `billing_customers`, `billing_subscriptions`, `billing_invoices`, `billing_payments` (tenant_id NOT NULL, composite tenant keys, restrict on delete). New `billing_events` (provider notifications; tenant_id nullable until matched). Adds `tenants.access_ends_at` (nullable). Additive only. | yes (drops them) |
| 2 | `2026_10_04_144459_grant_billing_permissions` | backfill + validate | Creates the permissions `billing.view` and `billing.manage`, and gives both to every tenant's CHRO role (found by its immutable key `chro`). No other role gets them. Additive; never syncs or removes. **Refuses to finish** unless every CHRO role holds both. | no-op (permissions stay; harmless without the tables) |

**Constraints and indexes:**

| Table | Constraint |
|---|---|
| `billing_prices` | one current price per (plan version, currency, interval): `unique (plan_version_id, currency, interval, is_current)`, history `is_current` NULL |
| `billing_invoice_sequences` | `series` unique |
| `billing_customers` | one per tenant: `unique (tenant_id)`; `unique (provider, provider_customer_ref)` |
| `billing_subscriptions` | one live per tenant: `unique (tenant_id, is_live)`; `unique (provider, provider_subscription_ref)`; index `(tenant_id, status)` |
| `billing_invoices` | `number` unique (global series); one per period: `unique (billing_subscription_id, period_start)`; index `(tenant_id, status)` |
| `billing_payments` | `reference` unique; `unique (provider, provider_payment_ref)`; index `(tenant_id, billing_invoice_id)` |
| `billing_events` | one per provider event: `unique (provider, provider_event_id)`; index `(status, received_at)` |

Composite foreign keys `(tenant_id, x) → (tenant_id, id)`: subscription → customer, invoice → subscription, payment → invoice. Platform references (plan versions, prices) restrict deletion; actor columns set null.

**What this guarantees for existing tenants:**
- no billing record is created: there is no subscription until the platform subscribes a tenant;
- every tenant keeps its plan (legacy), status, members, roles and data;
- `access_ends_at` is empty, so nothing changes in what any tenant can do.

## 2. Before running it (OWNER ACTION REQUIRED)

1. **The same preconditions as SaaS-1 to SaaS-3:** an approved release, a verified backup, drained queue workers.
2. **Do not configure the fake provider in production.** It is refused there anyway: webhooks answer 404 and nothing is collected. Without a production provider (D-S4-O1), only manual and contract billing can be used.
3. **Environment** (only if a provider is chosen):
   - the provider's credentials and webhook secret as infrastructure secrets;
   - `BILLING_PROVIDER`;
   - optionally `BILLING_GRACE_DAYS`, `BILLING_COLLECTION_RETRY_HOURS`, `BILLING_INVOICE_PREFIX`, `BILLING_INVOICE_YEAR_STARTS_MONTH`.
4. **Owner decisions before the first customer is billed:** provider, prices, tax scope, invoice numbering, dunning (decision register §2).
5. **Name the finance operators** who may run `billing:*`.

## 3. Running it, validating it, rolling back

**Run** it inside the SaaS-1 to SaaS-3 release procedure: the two SaaS-4 migrations run in the same `migrate`, after the SaaS-3 ones.

**Validation (read-only):**

| Check | Command or query | Expected |
|---|---|---|
| Tenancy integrity | `php artisan tenancy:verify --all` | every check 0 |
| Plans unchanged | `select count(*) from tenant_plan_assignments where is_current = 1` | the number of tenants |
| CHRO holds billing | `select count(*) from roles r where r.key = 'chro' and 2 > (select count(*) from role_has_permissions rp join permissions p on p.id = rp.permission_id where rp.role_id = r.id and p.name in ('billing.view','billing.manage'))` | 0 |
| No billing yet | `select count(*) from billing_subscriptions` | 0 |
| Clock | `php artisan billing:sweep` | succeeds, prints nothing |
| Reconciliation | `php artisan billing:reconcile` | every tenant: 0 match, 0 mismatch, 0 unresolved |
| Smoke test | the Billing page as CHRO: "There is no subscription for this organisation" | — |

**Rollback:**
- The rule is unchanged (P810-A4-01): restore the pre-release backup and redeploy the previous image.
- `down()` (development) drops the SaaS-4 tables and `access_ends_at`; the billing data they held is lost with them. Rehearsed (§5).

## 4. Data migration notes

- **Backfill:** no billing record is invented for existing tenants; only two permissions and their grants to CHRO roles (two rows per tenant in `role_has_permissions`).
- **Idempotent:** re-running the grant creates nothing twice.
- **Billing customers** are created on first use (subscription or billing details), from the tenant's own name and country.

## 5. Rehearsals (MySQL 8.4.11, throwaway copies)

**Method:**
1. Each source database was copied table by table into a throwaway `hrms_saas4_r*` database on the same server: structure from `SHOW CREATE TABLE`, rows with `INSERT … SELECT`. The source is only read.
2. The copy was migrated **up to SaaS-3 only** (all migrations but the two SaaS-4 ones), so that the SaaS-4 step can be measured on its own.
3. Then the copy was snapshotted:
   - every column of every table, with a CRC32 sum;
   - every tenant's effective entitlements and effective status.
4. The SaaS-4 migrations were run.
5. Snapshot 3 was repeated and compared, and the billing tables and CHRO grants were checked.
6. `tenancy:verify --all`, `billing:sweep` and `billing:reconcile` were run.
7. Each copy was dropped afterwards.

| | R1: development `hrms` | R1b: R1 with mixed tenant states | R2: 100k benchmark `hrms_p87_perf` |
|---|---|---|---|
| Starting state | 160 → 173 (SaaS-3) → 175 migrations | same, plus 3 tenants provisioned on the copy: a running trial; one past due with an entitlement override; one suspended | 160 → 173 → 175 |
| Tenants / memberships / role assignments | 1 / 11 / 11, unchanged | 4 / 11 / 11, unchanged | 1 / 511 / 111, unchanged |
| Current plan assignments | 1, unchanged | 4, unchanged | 1, unchanged |
| Effective entitlements and status, every tenant | identical before / after | identical (4 tenants: trial, past due with override, suspended, active) | identical |
| Tables otherwise unchanged (all columns) | 128 / 130 — only `permissions` (+2) and `role_has_permissions` (+2) | 129 / 130 — only `role_has_permissions` (+2)¹ | 128 / 130 — `permissions` (+2), `role_has_permissions` (+2) |
| Billing rows created | 0 in every billing table; `access_ends_at` set on 0 tenants | 0; 0 | 0; 0 |
| CHRO roles holding both billing permissions | 1 / 1; no other role | 4 / 4; no other role | 1 / 1; no other role |
| `tenancy:verify --all` | 400 checks, 0 violations | 403 checks, 0 violations | 422 checks, 0 violations |
| SaaS-4 step times (expand / grant) | 1 s / 0.01 s | 1 s / 0.01 s | 1 s / 0.01 s |
| `billing:sweep`, `billing:reconcile` | succeed; 0 match / 0 mismatch / 0 unresolved | succeed; every tenant 0 / 0 / 0 | succeed; 0 / 0 / 0 |
| `down()` × 2, then `up()` × 2 | tables and `access_ends_at` removed (173 migrations); re-applied; re-verified clean (400 checks, 0 violations) | — | — |

¹ The tenants provisioned on the rehearsal copy were created by the current code, whose seeder already includes the billing permissions. The migration therefore only added Tenant #1's two grants.

**Not rehearsed** (same gaps as SaaS-1 to SaaS-3):
- a production copy;
- an upgrade from production's 75-migration state.

Both need a production copy. **The production-copy rehearsal remains a release gate.**
