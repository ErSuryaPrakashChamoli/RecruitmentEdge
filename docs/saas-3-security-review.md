# SaaS-3 — Security Review: Provisioning, Plans & Entitlements

**For:** Security, the project owner and Engineering.

**Scope:**
- the commercial control plane: provisioning, plan catalog and assignment, overrides, lifecycle and trial, entitlement evaluation, limit enforcement, feature gating on every surface (HTTP, Livewire, Filament, jobs, commands), caching, concurrency, auditing;
- on `feature/saas-3-provisioning-entitlements` (on top of `869d89a`, SaaS-2);
- verified on SQLite and MySQL 8.4.11.

**Result:**
- **No Critical or High finding remains open.**
- Two findings were found and fixed during SaaS-3 (§2); one closes a SaaS-1 item (S1-05).
- Remaining items are Low or Info, accepted or deferred, each with an owner (§5).

## 1. Bypass attempts and the tests that try them

| Attempt | Result | Test |
|---|---|---|
| Use a feature outside the plan by calling the service directly (AI gateway, automation service and engine, distribution, export) | refused (`EntitlementDenied`) before any work or provider call | `FeatureGatingTest` (mutations M1, M13) |
| Open a hidden module by URL | 403 (Copilot, automation resources and pages) | `FeatureGatingTest` (M11) |
| Call a hidden Livewire action (report export) | 403 | `CommercialSecurityTest` (M12) |
| Run queued work after the feature left the plan | AI jobs skipped (not run, not failed); job-board publish recorded as failed, nothing sent; pending automation runs cancelled with a reason | `FeatureGatingTest`, `CommercialSecurityTest` (M14) |
| Exceed a limit by creating through a path that skips the service | refused by the record backstop | `LimitEnforcementTest` |
| Exceed a limit with two concurrent creates / acceptances | one wins; the other waits on the tenant lock and is refused (MySQL) | `CommercialRaceTest` (M7, M21) |
| Have the plan grant what the role does not, or the role what the plan does not | both refused independently | `FeatureGatingTest`, `LimitEnforcementTest` (M2) |
| A missing key, or a limit with neither number nor "unlimited" | denied, never unlimited | `EntitlementEvaluationTest` (M3, M4) |
| Read another tenant's plan, usage or overrides (page, model queries, cache) | only the tenant's own; another tenant's page 404 | `CommercialSecurityTest`, `EntitlementEvaluationTest` (M5, M6) |
| A cache entry for one tenant answering for another at the same entitlement version | impossible: the key contains the tenant | `CommercialSecurityTest` (M6) |
| A decision cached before a commercial change served after it (same process, another process, shared database cache) | never: the version in the key changes in the change's own transaction | `EntitlementEvaluationTest`, `CommercialSecurityTest`, `CommercialRaceTest` (M10) |
| A downgrade or override removal committed while an operation waits | the operation decides on the new state | `CommercialRaceTest` |
| Keep using a tenant after its trial ended, before the scheduler ran | closed at the second the trial ends: sign-in, careers site, entitlements, background work | `LifecycleTest`, `EntitlementEvaluationTest` (M9) |
| The trial sweep expiring a trial that was just extended | the sweep re-checks under the lock; the extension stands (both orders) | `CommercialRaceTest` (M23) |
| Work in a suspended tenant | refused, including an operation already waiting on the lock | `CommercialSecurityTest`, `CommercialRaceTest` (M8, M28) |
| Work in a cancelled, deletion-pending or deleted tenant (entitled work, a limit, queued jobs, the scheduler) | refused; queued jobs fail at the guard | `CommercialSecurityTest` |
| A request already past its checks when the trial ends (it waits on the tenant lock while the sweep records the expiry) | refused under the lock | `CommercialRaceTest` (M28) |
| Ask for AI from an intelligence page, or manage the knowledge base, without the AI assistant | answered Unavailable / 403; nothing queued | `FeatureGatingTest` (M25–M27) |
| A tenant administrator changes their own plan, overrides, lifecycle, or provisions | refused (`PlatformOperatorGate`); no tenant route or component reaches the services | `PlanCatalogTest`, `ProvisioningTest`, `CommercialSecurityTest`, `CommercialArchitectureTest` (M15) |
| A platform support (non-administrator) operator does the same | refused | `PlanCatalogTest` |
| Edit a published plan version (model, database drift) | refused by the models; drift refused by the next sync | `PlanCatalogTest` |
| Assign a retired version, a retired plan or an internal plan | refused (internal only with an explicit platform flag) | `PlanCatalogTest` |
| Invalid lifecycle transition (re-open cancelled, skip states) | refused, nothing changed | `LifecycleTest` (M16) |
| Hijack a slug by provisioning a different request | refused; the existing tenant untouched | `ProvisioningTest` (M19) |
| Re-provision a closed tenant's slug | refused | `ProvisioningTest` |
| Duplicate provisioning, concurrent or repeated | one tenant, one plan, one owner invitation | `ProvisioningTest` (M18), `CommercialRaceTest` |
| A half-provisioned tenant used | impossible: it stays Provisioning (unusable) until the last step | `ProvisioningTest` |
| A second account created for an owner who already has an identity | no: the invitation is accepted with the existing identity | `ProvisioningTest` |

## 2. Findings fixed in SaaS-3

| ID | Severity | Finding | Fix | Proof |
|---|---|---|---|---|
| S3-01 | Medium | On MySQL every retry of a provisioning request was refused as "slug already taken": the JSON column returns object keys in its own order and the stored fingerprint was compared strictly. A failed provisioning could never be finished. SQLite keeps the order, so the feature suite could not see it. | Fingerprints are compared without key order. | `CommercialRaceTest` (retry and duplicate races); the full MySQL run of `ProvisioningTest` |
| S3-02 | Low | A repeated provisioning request for a cancelled or deleted tenant would return that closed tenant as if provisioned. | Refused ("belongs to a closed tenant"). | `ProvisioningTest` |
| S3-03 | Low | Without the AI assistant, AI requests from intelligence pages (Role DNA suggestions; memory and insight summaries) were accepted and their job skipped, leaving the record "Processing" until the stale sweep; the Role DNA action and the AI knowledge base were still offered. No AI call was ever made. | The requests answer Unavailable with the plan message; the action and the knowledge-base resources require the entitlement (D-S3-24). | `FeatureGatingTest`; mutations M25–M27 |
| S1-05 (SaaS-1) | Medium | A suspended tenant's queued work stayed in `failed_jobs` after reactivation and needed an operator retry. | Retried after the reactivation commits, for that tenant only; never for cancelled or deleted tenants (D-S3-15). | `LifecycleTest` (M17) |

**Design-time choices that prevent a defect:** invitation acceptance takes the tenant lock exclusively, not shared (a shared lock upgraded by the seat check deadlocks two acceptances); the sweep re-checks each tenant under its lock; `consume()` re-reads the plan from the database under the lock, never from the cache.

## 3. Layers and their tests

| Layer | What enforces | Tests |
|---|---|---|
| Registry and evaluation | `Entitlement` enum, `EntitlementService` (fail closed) | `EntitlementEvaluationTest` |
| Limits | `consume()` under the tenant lock; record backstops | `LimitEnforcementTest`, `CommercialRaceTest` |
| Features (service, job, Livewire, Filament) | gateway, services, engine, job middleware, `canAccess()` | `FeatureGatingTest`, `CommercialSecurityTest` |
| Control plane | `PlatformOperatorGate`; architecture rules | `PlanCatalogTest`, `ProvisioningTest`, `LifecycleTest`, `CommercialArchitectureTest` |
| Tenant isolation | TenantScope on assignments and overrides; tenant in cache keys | `CommercialSecurityTest`, `EntitlementEvaluationTest` |
| Cost | one map per tenant and version; constant queries | `CommercialPerformanceTest` |

**Architecture rules** (`tests/Unit/Commercial/CommercialArchitectureTest.php`), each checked against a deliberately violating probe file:
1. only the platform reaches `App\Services\Platform\Commercial`;
2. the product never reads plans, plan versions, assignments or overrides directly;
3. entitlement keys are never written as strings outside the enum;
4. only the lifecycle and provisioning services write a tenant's status, trial end or entitlement version.

## 4. Mutation checks

Each mutation was applied alone (M20b and M27: two lines together), the named tests were run, and the source was restored. M21–M23 and M28 ran against MySQL (`phpunit.concurrency.xml`).

| # | Mutation | Tests | Result |
|---|---|---|---|
| M1 | Entitlement enforcement removed (AI gateway) | `FeatureGatingTest` | caught |
| M2 | Permission enforcement removed (requisition create) | `LimitEnforcementTest` | caught |
| M3 | Plan lookup bypassed (every key granted) | `EntitlementEvaluationTest` | caught (7) |
| M4 | Missing entitlement treated as unlimited | `EntitlementEvaluationTest` | caught |
| M5 | Tenant id dropped from the plan lookup | `EntitlementEvaluationTest` | caught (6) |
| M6 | Tenant id dropped from the cache key | `EntitlementEvaluationTest`, `CommercialSecurityTest` | caught (5) |
| M7 | Quota check bypassed (atomic path) | `LimitEnforcementTest` | caught (7) |
| M8 | Lifecycle check bypassed (unusable tenant entitled) | `EntitlementEvaluationTest`, `FeatureGatingTest` | caught |
| M9 | Ended trial not closed until the sweep | `LifecycleTest`, `EntitlementEvaluationTest` | caught |
| M10 | Stale cache (entitlement version not bumped) | `EntitlementEvaluationTest`, `CommercialSecurityTest` | caught (6) |
| M11 | Filament gate removed (resource `canAccess`) | `FeatureGatingTest` | caught |
| M12 | Livewire gate removed (report export) | `CommercialSecurityTest`, `FeatureGatingTest` | caught |
| M13 | Service gate removed (automation rules) | `FeatureGatingTest` | caught |
| M14 | Job gate removed (queued AI work) | `FeatureGatingTest` | caught |
| M15 | Platform-only gate removed | `PlanCatalogTest`, `ProvisioningTest` | caught |
| M16 | Lifecycle transition table bypassed | `LifecycleTest` | caught (6) |
| M17 | Paused work not resumed on reactivation | `LifecycleTest` | caught |
| M18 | Provisioning invites the owner again | `ProvisioningTest` | caught (after adding the "resume with an existing invitation" test; it survived the first run) |
| M19 | Provisioning lets another request take a slug | `ProvisioningTest` | caught |
| M20 | `consume()` removed from invitation acceptance | `LimitEnforcementTest` | **survived — masked**: acceptance already holds the tenant lock (`lockOpen`) and the membership backstop refuses the extra seat; behaviour stays correct |
| M20b | `consume()` **and** the membership backstop removed | `LimitEnforcementTest` | caught |
| M21 | Commercial lock removed from limit consumption (MySQL) | `CommercialRaceTest` | caught (3 races) |
| M22 | Locking count replaced by a plain count (MySQL) | `CommercialRaceTest` | **survived — equivalent today** (S3-A6) |
| M23 | Trial sweep does not re-check under the lock (MySQL) | `CommercialRaceTest` | caught |

| M25 | AI requests from intelligence pages not gated by the plan | `FeatureGatingTest` | caught |
| M26 | Role DNA "Suggest with AI" shown without the plan | `FeatureGatingTest` | caught |
| M27 | AI knowledge base not gated by the plan | `FeatureGatingTest` | caught |
| M28 | Tenant state not re-checked under the lock (MySQL) | `CommercialRaceTest` | caught (2 races: suspension, trial end) |

**26 of 28 caught.** The two survivors are documented: M20 is masked by a second, independent control, and M22 is defence in depth with no observable effect in today's call paths.

## 5. Open and accepted items (no Critical, no High)

| ID | Severity | Item | Disposition | Owner |
|---|---|---|---|---|
| S3-A1 | Low | The record backstops are not atomic. Every application path that adds to a limit uses `consume()`; the backstop catches a path that forgot. `IdentityProvisioningService::joinCurrentTenant` has a membership-create branch that its only caller (rehire) cannot reach; if ever reached, the backstop applies. | Accepted. A new path must use `consume()` (`.ai/rules/entitlements.md`). | Engineering |
| S3-A2 | Low | Work already running when a suspension commits finishes, like any in-flight request. Anything it adds to a limit waits for the tenant lock and is then refused. | Accepted (same semantics as SaaS-1/2 sessions). | — |
| S3-A3 | Info | Cache entries of old entitlement versions stay until their 600 s TTL. They are never read (the key changed) and belong to the same tenant. | Accepted. | — |
| S3-A4 | Info | The provisioning fingerprint is name, owner and plan. A repeat that differs only in legal name, timezone, locale, currency or country returns the existing tenant unchanged. | Accepted; documented. | — |
| S3-A5 | Medium | Cancelled, deletion-pending and deleted are states only: no data is purged or exported. | Deferred: owner decision D-S3-O7 before any tenant is cancelled. | Legal + Security |
| S3-A6 | Info | The locking count (`sharedLock`) is defence in depth: in today's call paths the tenant lock is taken before any other read in the transaction, so a plain count sees the same rows (M22). | Kept for future callers. | — |
| — | — | Production-copy rehearsal of the migrations | Release gate (migration plan §5). | Release owner |

Carried forward from SaaS-1 / SaaS-2, not reopened (decision register §3): S1-10 permission cache churn → SaaS-7; invitation token in the access log → SaaS-5; password reset timing → SaaS-7; platform panel → SaaS-5; SSO / SAML / SCIM / passkeys / service accounts → later identity phase.

## 6. Audit coverage

Every commercial change writes one audit row in the tenant's stream with old and new values, the source (`platform`, `provisioning`, `scheduler`, `migration`), the operator id and the reason, plus a `platform.*` log line. Catalog publishing is audited in the platform stream. No secret is written (owner email appears only in the SaaS-2 invitation row, as before; tokens are hashed).

## 7. Verification

| Suite | SQLite | MySQL 8.4.11 |
|---|---|---|
| Full suite | **2,447 / 2,447**, 33,401 assertions (baseline 2,361 + 86 SaaS-3) | **2,447 / 2,447**, 33,401 assertions (fresh migrations, SaaS-3 included) |
| SaaS-1 tenancy (`tests/Feature/Tenancy`) | 105 / 105 | 105 / 105 |
| SaaS-2 (`tests/Feature/IdentityAccess` + identity-plane architecture) | 101 / 101 | 101 / 101 |
| SaaS-3 (`tests/Feature/Commercial` + `tests/Unit/Commercial`) | 86 / 86 | 86 / 86 |
| Architecture (tenancy 9 + identity plane 4 + commercial 4) | 17 / 17 | 17 / 17 |
| Security (`tests/Feature/Security` 150 + SaaS-2 access 94 + `CommercialSecurityTest` 14) | 258 / 258 | 258 / 258 |
| Concurrency (`phpunit.concurrency.xml`, fresh database) | — | **31 / 31** (21 existing + 10 SaaS-3) |
| Mutation checks | 26 of 28 caught; 2 explained (§4) | |
| Migration rehearsals (MySQL copies) | | R1, R2 clean; rollback and re-apply clean (`saas-3-migration-plan.md` §5) |
| Seeds | `PlanCatalogSeeder` and `DatabaseSeeder` run twice: nothing created twice | |
| Browser | not applicable: the repository has no browser suite | |

## 8. Existing assertions changed on purpose

No existing assertion changed. Existing tests keep their behaviour because every tenant they create is pinned to the internal `legacy` plan (everything, unlimited), exactly as existing tenants are migrated.

Two existing **fixtures** changed: `IdentityRaceTest` and `TenantIntegrityRaceTest` create a second tenant directly (not through the factory); it is now pinned to `legacy` like the harness's home tenant (`Race::pinPlan`). Without a plan, the seat backstop correctly refused its new members (fail closed).

## 9. What this review does not cover

- Production data and a production copy (migration plan §5).
- Billing and payment security (SaaS-4: not built).
- A real browser: the repository has no browser suite. Livewire and HTTP paths are covered by feature tests.
