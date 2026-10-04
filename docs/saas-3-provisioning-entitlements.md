# SaaS-3 — Provisioning, Plans & Entitlements

**For:** Engineering, and Security reviewers.

**Branch:** `feature/saas-3-provisioning-entitlements`, branched from `869d89a` (SaaS-2). Not pushed, merged or deployed.

**Read with:**
- `saas-3-decision-register.md`: every decision and why, and the open owner decisions.
- `saas-3-security-review.md`: findings, bypass attempts, mutation checks.
- `saas-3-migration-plan.md`: the schema change and the legacy-plan backfill.

**Out of scope (not built):** prices, payments, invoices, tax, subscriptions, webhooks, dunning (SaaS-4); SSO; the platform console (SaaS-5); integrations and the API platform.

## 1. The model

```
PLATFORM (no tenant_id)
  plans                 code (stable), name, status: active | internal | retired
    └── plan_versions   version 1, 2, …  — published once, never edited (only retired)
          └── plan_entitlements   one row per registry key: enabled, limit_value, is_unlimited

TENANT (tenant_id, TenantScope)
  tenants               status (lifecycle), status_reason, trial_started_at / trial_ends_at,
                        entitlement_version (cache generation), provisioned_at, provisioning_state / _error
  tenant_plan_assignments      the pinned plan version — one current (is_current), history kept
  tenant_entitlement_overrides one current per key, optional end, reason, actor; revoked kept
  tenant_memberships.is_owner  one owner per tenant (unique)
```

**The entitlement registry** is code: `App\Enums\Entitlement`. Nothing can be granted that is not in it.

**Key convention:** `{area}.{capability}` for a feature (`exports.data`), `{area}.{what is counted}.max` for a limit (`requisitions.active.max`). Lower case, dot-separated, stable: a key is never renamed (plan versions store it). Only the current product's commercially meaningful capabilities are registered; a new gated capability is a new case plus a value in every new plan version.

| Key | Type | What it gates |
|---|---|---|
| `ai.assistant` | feature | Every AI call (gateway), the Copilot, "Ask AI" links, AI requests from intelligence pages, the AI knowledge base, AI background jobs |
| `automation.rules` | feature | Creating / changing / activating rules; the engine; the automation module |
| `distribution.job_boards` | feature | Publishing to external job boards (never the tenant's own careers site) |
| `exports.data` | feature | Table exports (Filament), report CSV downloads |
| `requisitions.active.max` | limit | Requisitions that are not Closed or Cancelled |
| `members.active.max` | limit | Active staff memberships (seats) |

## 2. Evaluation (EntitlementService)

**Effective entitlement** for a key, in this order:
1. The tenant is not usable → **denied** (`tenant_inactive`). Usable means a running Trial, Active or PastDue; Provisioning, Suspended, an ended trial, Cancelled, Deletion pending and Deleted are not.
2. An override for the key is in force (started, not ended) → the override.
3. The pinned plan version defines the key → the plan's value.
4. Otherwise → **denied** (`missing`). A missing key is never unlimited.

**Values are explicit.** A feature is `enabled` true/false. A limit is a number, or `is_unlimited = true`. There are no magic values (-1, 0, null, 999…). A limit row with neither a number nor `is_unlimited` grants nothing.

**API:**

| Method | Use |
|---|---|
| `allows($key)` / `effective($key)` | Screens, navigation, read paths |
| `require($key)` | A service refusing a feature (throws `EntitlementDenied`) |
| `canAdd($key, $n)` | Screens: disable a button and explain (not a guarantee) |
| `consume($key, $operation, $n)` | **The** way to add to a limit: atomic (below) |
| `usage($key)` | Authoritative count of what the limit counts |
| `overview()` | The tenant's own Plan & usage page |

**Caching.**
- The tenant's map (plan values + overrides) is cached under `t:{tenant}:entitlements:v{entitlement_version}` for 600 s, and memoised for the request (scoped binding).
- Every commercial change increments `tenants.entitlement_version` **in the same transaction** as the change (`CommercialChange`). An entry for the old version is never read again; it simply expires.
- The version is read from the tenant row before the map is loaded, so an entry can never hold data older than its key says.
- Proven with a shared database cache store on MySQL (`CommercialRaceTest`).

**Measured** (MySQL 8.4; `CommercialPerformanceTest` asserts the query counts):

| Operation | Cost |
|---|---|
| First decision in a request (map load) | 3 queries; ~6 ms on the 100k copy |
| Repeated decision in the request | 0 queries; ~0.06 ms |
| Panel request with the map cached | 0 plan queries |
| Atomic check of both limits (`consume()` path) | ~5 ms; constant with data size |
| Switching to another tenant (access + first entitlement decision) | 6 queries / ~9 ms cold; 0 queries after |
| Provisioning a tenant (defaults, plan, owner invitation, trial) | ~0.8–1.1 s; repeating it ~3 ms |

## 3. Limits — atomic, server-side

`consume()` runs in one transaction:
1. lock the tenant row (`SELECT … FOR UPDATE`) — **the commercial lock**;
2. check the tenant is usable, re-read the plan and overrides from the database (never the cache);
3. count usage with a locking read;
4. refuse (`LimitReached`: "… (In use: 10 of 10.)") or run the operation.

Every commercial change (plan assignment, override, lifecycle transition) takes the same lock. A consumption therefore sees the old state or the new one, never a mix. Two consumptions for the last place: one wins, the other waits and is refused (MySQL-proven).

**Lock order** (deadlock-free): CHRO role → tenant → user → membership; for invitations: invitation → tenant → user → membership. Invitation acceptance takes the tenant lock exclusively (it was a shared lock in SaaS-2; a shared lock upgraded to exclusive by the seat check would deadlock two acceptances).

**Where limits are consumed:**

| Limit | Operations |
|---|---|
| `requisitions.active.max` | `RequisitionService::create` (Filament create page), `RequisitionService::restore` of a trashed active requisition (single and bulk). Restoring a closed or cancelled one consumes nothing. |
| `members.active.max` | Invitation acceptance (new member or re-activated revoked member), `StaffAccessService::restore` of a suspended member. Inviting is pre-checked (`canAdd`) so a full plan refuses early. |

**Backstops on the records** (model events) refuse a write that did not come through `consume()`: a requisition created or restored as active, a membership becoming Active, an export record. They are not atomic on their own and are skipped inside `consume()`; they exist to catch a path that forgot.

**Usage is counted from the records**, every time. There is no counter that could drift, so there is nothing to reconcile. `tenants:usage` is the read-only check (flags OVER LIMIT).

## 4. Features — where each is enforced

| Feature off | Server-side (authoritative) | Panel (convenience) |
|---|---|---|
| AI assistant | `AiGateway` refuses generate / stream / structured / embed / research before anything is sent. AI requests from intelligence pages (Role DNA suggestions, memory and insight summaries) are answered **Unavailable** at once, with the plan message — nothing is queued or left Processing. AI jobs (`SkipWithoutEntitlement` middleware) are **skipped**, not failed. | Copilot page and the knowledge base (documents, articles) refused (403) and hidden — existing documents are kept; "Ask AI" links and "Suggest with AI" hidden |
| Automation | `AutomationRuleService` refuses create / update / activate / duplicate. `AutomationEngine` triggers nothing, processes nothing, and cancels a pending run with the reason "Automation is not included in the organisation's plan." **Pause and archive stay allowed.** | Automation resources and pages refused (403) and hidden |
| Job boards | `JobDistributionService::publish` refuses external channels; `PublishJobDistributionJob` re-checks at run time and records a non-retryable failure. Unpublish and pause always run. The careers site is never gated. | External channels disabled in the publishing action |
| Exports | Creating an `Export` record throws; `ReportExportService::streamCsv` throws; `RecruitmentReports::canExport()` checks it (Livewire calls included). | Export buttons hidden |

**Permission and entitlement are separate gates.** Both must pass. `canAccess()` is `allows(entitlement) && parent::canAccess()`. The plan never grants what a role does not, and a role never grants what the plan does not (`FeatureGatingTest`, `LimitEnforcementTest`).

## 5. Plans

- **The catalog is code** (`PlanCatalog`): stable codes, and for each published version a value for **every** registry key.
- `plans:sync` (also run by `PlanCatalogSeeder`) publishes the versions that do not exist yet. It is idempotent.
- A published version that differs from its definition is **refused** ("publish v2 instead of editing it"). The same check detects drift made directly in the database.
- `PlanVersion` and `PlanEntitlement` refuse updates and deletes at the model level; only a version's status can change (retire).
- **Plans:** `legacy` (internal: everything, unlimited — existing tenants), `starter`, `growth`, `enterprise`. The commercial values are **development defaults, not an offer** (decision register D-S3-O1).
- **Assignment** (`PlanAssignmentService::assign`): under the tenant lock; ends the current assignment (`is_current` → null, `effective_until`) and starts the new one; requires a reason; refuses a retired version, a retired plan, and an internal plan unless explicitly allowed. Assigning the current version again is a no-op.
- **Tenants are pinned to a version.** Publishing v2 changes nobody until a platform decision moves them.
- **A downgrade never deletes or deactivates anything.** Existing records stay; only adding beyond the new limit is refused until usage is under it again. The Plan & usage page flags an over-limit line.

## 6. Overrides

`EntitlementOverrideService::set($tenant, $key, $value, $reason, $endsAt)`:
- one current override per key; it replaces the previous one (kept, revoked);
- the value must match the key's type (`true`/`false`, or a non-negative integer, or `PlanCatalog::UNLIMITED`);
- a reason is required; an end must be in the future;
- removed with `remove()`. Created, changed and removed are audited.

**Precedence:** plan version → override in force → effective entitlement.

## 7. Lifecycle

`TenantLifecycleService` is the only writer of a tenant's status (architecture test).

| From | To |
|---|---|
| provisioning | trial, active |
| trial | active, suspended, cancelled |
| active | past_due, suspended, cancelled |
| past_due | active, suspended |
| suspended | active, cancelled |
| cancelled | deletion_pending |
| deletion_pending | deleted |
| deleted | — |

- Every transition: under the tenant lock, with a reason, audited old → new in the tenant's own stream, `entitlement_version` bumped.
- Usable: trial (running), active, past_due. Everything else closes sign-in, the careers site, the portal, entitlements and background work.
- **Nothing deletes data.** Cancelled, deletion-pending and deleted are states; purging is a later, separately approved phase.
- **Trial:** `trial_ends_at` is evaluated on every request (`Tenant::effectiveStatus()`): an ended trial is Suspended from that second, without waiting for the scheduler. The hourly `tenants:lifecycle-sweep` records it (status `suspended`, reason `trial_expired`, audit `trial_expired`) and re-checks each tenant under its lock. `extendTrial` extends a running trial or re-opens an ended one; `activate` converts it.
- **Paused work resumes (closes S1-05).** Queued work of an unusable tenant fails at the queue guard (`TenantUnavailable`) and waits in `failed_jobs`. When a Suspended tenant (or an ended trial) becomes usable again, exactly that tenant's waiting jobs are retried after the commit, and audited (`tenant_work_resumed`). A cancelled or deleted tenant's work is never resumed.
- PastDue is a manual platform state only. Nothing sets it automatically: payments are SaaS-4.

**Background work policy** (deterministic; brief §24, §37):

| Situation | Queued / scheduled work |
|---|---|
| Tenant not usable (Provisioning, Suspended, ended trial, Cancelled, Deletion pending, Deleted) | **Fails** at the queue guard (`TenantUnavailable`) and waits in `failed_jobs`; the scheduler queues nothing for it |
| … then reactivated from Suspended or an ended trial | **Resumed**: exactly its waiting jobs are retried after the commit |
| … then Cancelled, Deletion pending or Deleted | **Never resumed** |
| AI assistant not in the plan | AI jobs **skip** (logged, not failed); AI requests answer Unavailable |
| Automation not in the plan | The engine **cancels** a pending run with the reason "Automation is not included in the organisation's plan."; triggers and sweeps create nothing |
| Job boards not in the plan | A queued publish **records a non-retryable failure** and sends nothing; unpublish and pause still **execute** |
| Exports not in the plan | No new export starts (the record is refused). An export already started before the change **completes** for the person who started it |
| Deterministic intelligence refresh (health, signals, radar) | **Executes** on every plan — it is core analytics, not AI |
| Work running when a commercial change commits | **Completes**, like an in-flight request; anything it adds to a limit waits for the tenant lock and is then refused |

**Cancellation** (brief §40): a cancelled tenant keeps all of its data. Access (sign-in, careers site, portal), exports, scheduled jobs and AI jobs all stop because the tenant is not usable. Nothing is deleted or anonymised; purge and retention belong to a separately approved data-retention phase (decision register D-S3-O7).

## 8. Provisioning

`TenantProvisioningService::provision(ProvisioningRequest, ?operator)`, or `tenants:provision`:
1. **Reserve:** the tenant row is created in Provisioning (the slug is unique) with the request's fingerprint (name, owner, plan). A repeated request finds it. A different request for a taken slug, or for a closed tenant's slug, is refused.
2. **Steps**, in one transaction under the tenant lock, each idempotent:
   - default roles and reference data (`TenantDefaults`: the same idempotent seeders as development; no demo data, no people);
   - the plan (latest published version of an **active** plan);
   - the owner's invitation (CHRO + ownership). The owner's existing identity is reused when they accept; no second account;
   - the lifecycle: Trial (with its end) or Active.
3. **Failure:** the steps roll back; the tenant stays in Provisioning (unusable) with `provisioning_error`, audited. Provisioning the same request again resumes and finishes it. Provisioning older than an hour is reported by the sweep (`platform.alert`).

Concurrent duplicates and concurrent retries serialise on the slug and the tenant lock; one tenant, one plan, one invitation (MySQL-proven).

**The owner** accepts like any invited person (SaaS-2: invitations are the only way in). The membership gets `is_owner = true` (one owner per tenant, unique index) and takes the first seat.

## 9. Platform only

- Plans, assignments, overrides, lifecycle and provisioning are changed only through `App\Services\Platform\Commercial\*`.
- `PlatformOperatorGate`: a platform administrator (`PlatformAccess`), or the platform console / internal services (no operator).
- No tenant route, page, Livewire component or job references these services (architecture test). Tenant administrators see their own plan, usage and trial (Plan & usage page, trial notice) read-only.

**Console (platform staff):**

| Command | Does |
|---|---|
| `plans:sync` | Publish the code-defined catalog (idempotent) |
| `tenants:provision {slug} --name --owner --plan [--trial=N]` | Provision a tenant |
| `tenants:plan {slug} {plan} --reason [--internal]` | Assign a plan |
| `tenants:entitlement {slug} {key} {value} --reason [--until] / --remove` | Override one entitlement |
| `tenants:lifecycle {slug} {action} --reason [--days]` | activate, suspend, past-due, cancel, extend-trial, deletion-pending, deleted |
| `tenants:lifecycle-sweep` | Record ended trials; report stale provisioning (scheduled hourly, platform task) |
| `tenants:usage [slug]` | Read-only usage vs limits |

## 10. Audit

| Event | Stream |
|---|---|
| `plan_version_published` | platform |
| `tenant_provisioning_started` / `_retried` / `_failed`, `tenant_provisioned` | tenant |
| `plan_assigned`, `plan_changed` (old → new) | tenant |
| `entitlement_override_created` / `_changed` / `_removed` | tenant |
| `trial_started`, `trial_extended`, `trial_expired`, `tenant_activated`, `tenant_suspended`, `tenant_past_due`, `tenant_cancelled`, `tenant_deletion_pending`, `tenant_deleted` | tenant |
| `tenant_work_resumed` | tenant |

Each commercial change also writes a `platform.*` log line (tenant id, source, operator id). Never a secret.

## 11. Code map

| Area | Where |
|---|---|
| Registry | `app/Enums/Entitlement.php`, `EntitlementType`, `PlanStatus`, `PlanVersionStatus` |
| Evaluation, limits | `app/Services/Entitlements/` (`EntitlementService`, `UsageMeter`, `EffectiveEntitlement`, `EntitlementDenied`, `LimitReached`, `SkipWithoutEntitlement`) |
| Control plane | `app/Services/Platform/Commercial/` |
| Models | `Plan`, `PlanVersion`, `PlanEntitlement`, `TenantPlanAssignment`, `TenantEntitlementOverride`; `Tenant` (`effectiveStatus`, `trialHasExpired`) |
| Panel | `PlanAndUsage` page, trial notice (`filament/components/trial-banner`), `RequiresEntitlement` trait |
| Tests | `tests/Feature/Commercial/`, `tests/Unit/Commercial/CommercialArchitectureTest.php`, `tests/Concurrency/CommercialRaceTest.php` |
| Fixtures | `TenantFactory` pins `legacy` by default; `onPlan()`, `withoutPlan()`, `trial()`; `CommercialWorld` (tenants A–E) |

## 12. Deferred (later phases)

- Prices, subscriptions, payment states, invoices, tax, dunning → **SaaS-4**.
- Platform console for plans, overrides, lifecycle and provisioning (today: console commands) → **SaaS-5**.
- Ownership transfer (today: one owner, set at provisioning) → SaaS-5.
- Data purge for deletion-pending / deleted tenants → a separately approved data-retention phase.
- Usage-metered entitlements (AI tokens, storage) → SaaS-4.
