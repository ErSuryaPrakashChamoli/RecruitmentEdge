# SaaS-1 — Tenant Foundation & Isolation

**For:** engineers working on Recruitment Edge, and the security reviewers of the SaaS conversion.

**Status:** implemented on `feature/saas-1-tenant-foundation` (from `3fb40d6`; application code equal to release candidate `226bc7d`). Not pushed, not deployed. The release-candidate branch `feature/sep_25_hrm` is untouched.

**Purpose:** make the application safe for several independent companies (tenants) in one pooled database. Billing, plans, provisioning, SSO, API, branding and platform administration are later phases (§12).

Companion documents: `saas-1-security-review.md` (bypass attempts and findings), `saas-1-migration-plan.md` (Tenant #1 upgrade), `saas-1-decision-register.md` (decisions).

## 1. Model

**Pooled shared schema.** Every tenant-owned row carries `tenant_id`, which is `NOT NULL` once the migrations finish. One database and one codebase serve all tenants. A dedicated database for a large or regulated tenant stays possible later (same schema; decision D-S1-01).

| Entity | Table | Notes |
|---|---|---|
| `Tenant` | `tenants` | `public_id` (ULID), `slug` (in URLs), `name`, `legal_name`, `status`, `status_changed_at`, `timezone`, `locale`, `currency`, `country`, `branding` (JSON). A platform record, never tenant-scoped. |
| `TenantStatus` | — | provisioning, trial, active, past_due, suspended, cancelled, deletion_pending, deleted. Only **trial / active / past_due** are usable: staff sign-in, careers, portal, and background work. Every other status fails closed. |
| `TenantMembership` | `tenant_memberships` | links a staff identity (`users`) to a tenant: `employee_id`, `status` (active / revoked). It is the only way a signed-in person reaches a tenant. |

## 2. How the boundary is enforced

Five independent layers. Each one alone refuses cross-tenant access.

1. **TenantContext** (`app/Services/Tenancy/TenantContext.php`): the only source of "which tenant is this work for?".
   - Stored in Laravel `Context` under `tenant_id`, so it travels in every queued payload and appears on every log line.
   - There is no "all tenants" value.
   - API: `setTenant()`, `tenant()`, `id()`, `hasTenant()`, `requireId()`, `requireTenant()`, `clear()`, `run($tenant, fn)` (restores the previous context even on failure), `runWithoutTenant(fn)`.
2. **TenantScope** (`BelongsToTenant` trait): every query on a tenant-owned model is limited to the current tenant. **With no tenant the query throws `MissingTenantContext`**: it is never widened.
3. **Writes** (`BelongsToTenant`):
   - A new row takes the current tenant, also at construction, so `saveQuietly()` and `createQuietly()` carry it.
   - A row built for another tenant is refused with `CrossTenantViolation`.
   - `tenant_id` never changes.
   - References that the database cannot check are verified before save (`TenantSchema::REFERENCES`).
4. **Authorization:**
   - Policies and services as before; `Gate::before` (`TenancyServiceProvider::crossesTenant`) **denies every ability on another tenant's record or role**, whatever a policy says.
   - Hierarchy "view all" means all of the current tenant (§6).
5. **Database:**
   - `tenant_id NOT NULL` on 103 tables.
   - 108 composite foreign keys `(tenant_id, x_id) → parent (tenant_id, id)`.
   - 35 business keys unique per tenant (§3).

Filament's tenant selection feeds layer 1. It is **never the only boundary**: models, policies and the database enforce the tenant on their own. With `TenantScope` disabled and Filament's tenancy still active, 19 isolation tests fail (mutation M1 in `saas-1-security-review.md` §5).

**The only bypass** is `->withoutTenancy()` (a `TenantScope` macro), allowed in five reviewed files (§11). Raw `DB::table()` queries on tenant tables must name `tenant_id` themselves (architecture test).

## 3. Table classification (`app/Services/Tenancy/TenantSchema.php`)

| Class | Count | Tables |
|---|---|---|
| B: tenant-owned | 103 | every business table, including `notifications`, `exports`, `imports`, `failed_import_rows`, `saved_table_views`, `integration_statuses`, `candidate_portal_accounts`, `employee_hierarchy` and all pivots |
| Tenant-attributed, nullable | 2 | `audit_logs` (tenant stream, or platform stream when null); `communication_webhook_events` (tenant resolved from the matched message; null when it matched nothing or applied to several tenants) |
| A: platform | 3 | `tenants`, `ai_evaluations`, `ai_evaluation_runs` (the platform's own AI quality suite) |
| C: identity | 7 | `users`, `password_reset_tokens`, `tenant_memberships`, `roles`, `model_has_roles`, `model_has_permissions` (tenant as the spatie team key), `role_has_permissions` |
| D: reference | 1 | `permissions` (the permission catalogue) |
| E: system | 7 | `migrations`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `sessions` |

`TenancyFoundationTest` fails if a table exists that is not classified, or if a tenant-owned table has a nullable or missing `tenant_id`. `TenancyArchitectureTest` fails if a model of a tenant-owned table lacks `BelongsToTenant`. That covers 95 domain models plus `TenantPivot`, `Export`, `Import`, `FailedImportRow` and `DatabaseNotification`.

**Unique per tenant (35):**
- **Business codes and slugs:** candidate, application, requisition, offer, employee and referral codes; employee email; department, designation, location, source, rejection-reason and stage codes; campaign code; pipeline template, talent pool and AI article slugs; job posting public slug.
- **Configuration keys:** recruitment settings keys; code sequence keys; automation rule keys; communication template identity; integration provider.
- **Lookup keys** (scoped lookups must have a unique index in the same scope): idempotency, dedupe, capture and open keys; portal account email; saved table views.
- **Roles:** key, and name + guard.

**Kept global:** random public ids, keys made only of global record ids (for example `(candidate_id, requisition_id)`), provider webhook event ids, and `users.email` / `users.employee_id` (staff identity is global; §5).

**References:**
- 108 `ON DELETE CASCADE / RESTRICT` references between tenant tables are composite foreign keys.
- 157 `ON DELETE SET NULL` references cannot be composite: MySQL would null `tenant_id`. They are checked by `BelongsToTenant` before every save and by `tenancy:verify` (decision D-S1-07).
- References to `users` (34) stay single-column, because identity is global.

## 4. Where the tenant comes from

| Surface | Resolver | Source of truth |
|---|---|---|
| Every HTTP request | `ResetTenantContext` (first global middleware) | starts empty; a context set before the request (a test's) is restored afterwards |
| Admin panel `/admin/{slug}/…` (pages, Livewire updates) | Filament `IdentifyTenant` + `SetTenantContextFromPanel` (persistent tenant middleware) | `User::canAccessTenant()`: active membership, usable tenant, and at least one role there |
| Profile page `/admin/profile` (tenant-less in Filament) | `Profile::boot()` | the person's employing tenant (`users.employee_id`), only while they may act there |
| Careers `/careers/{slug}/…`, portal `/portal/{slug}/…`, calendar connect | `ResolveTenantFromRoute` | the slug, looked up in `tenants` (never `Host`); unknown or unusable tenant → 404; the parameter is removed before controllers run |
| Filament export / failed-row downloads | `ResolveTenantForFilamentDownload` | the export's own tenant, after checking the person may act there |
| Private files `files/private` | `PrivateFileController` | the tenant signed into the URL; plus access, owning record and its view policy |
| Provider webhooks | `DeliveryStatusService` | the message the provider's own id names (after the signature check); opt-outs: each tenant holding the recipient |
| Calendar OAuth callback | `CalendarOAuthController` | the tenant stored in the session with the OAuth state, re-checked |
| Queued jobs, listeners, notifications, mail, exports | `TenantQueueGuard` (JobProcessing) | the payload (Context plus top-level `tenant_id`); must agree; tenant must allow background work |
| Scheduled tasks | `tenants:dispatch`, `tenants:run` | `TenantDirectory::forBackgroundWork()` (§8) |
| Seeders, tests | `TenantContext::run()` / `TestCase::actInTenant()` | explicit |

When `TenantContext` changes it also sets the `{tenant}` default for route URLs and Filament's selected tenant. A link generated in a job, mail or command therefore points into the right tenant, and a tenant link cannot be generated without a tenant. A queue worker re-syncs both at the start of every job.

## 5. Identity foundation (SaaS-2 builds on it)

- **`users` stays global** (one identity, unique email).
  - `tenant_memberships` decides which tenants a person reaches.
  - `users.employee_id` is kept: a person has one employing tenant in SaaS-1, and `membership.employee_id` mirrors it.
  - Moving the employee link onto the membership is SaaS-2 (migration plan §7).
- **Roles are per tenant:**
  - Spatie teams use `tenant_id` as the team key; `TenantTeamResolver` returns the current tenant.
  - Role checks (`hasRole`, `can`, `User::role()`) see only the current tenant's roles. With no tenant, no role applies.
  - `Role` deliberately has **no** global scope: spatie's single permission cache must see every role. Direct role queries use `Role::forCurrentTenant()`, and `RolePolicy` checks the role's tenant.
- **Before a tenant is chosen** (sign-in, `Authenticate`, `EnforceStaffAccess`, MFA set-up):
  - `canAccessPanel` asks whether the person can reach any tenant.
  - Employment facts (due separations, last-CHRO protection) are evaluated in the employing tenant (`User::withinEmployingTenant()`).
  - **MFA is required if any tenant the person holds a role in requires it**, read across tenants on purpose.
- **Listing staff:** identities have no `tenant_id`, so the tenant scope does not cover them. Every list or sweep of staff inside a tenant uses `User::membersOfCurrentTenant()`: the Users resource, Access Review, the audit log's user filter, fallback owners, access reconciliation and the identity audit.
- **Who may manage whom:**
  - Only members of the current tenant are in anyone's scope (`AuthorityGuard::inScope`).
  - Only the tenant that employs a login may change its access state, because access state belongs to the global identity.
- **Provisioning:** a login provisioned or rehired by a tenant becomes an active member of it.

## 6. "View all" is the current tenant

- `HierarchyService::visibleEmployeeIdsFor()` still returns `null` for `hierarchy.view-all`. Its meaning is now **every employee of the current tenant**:
  - all Eloquent queries are tenant-scoped;
  - every closure-table read names the tenant (`HierarchyService::closure()`, `EmployeeObserver`);
  - composite foreign keys make a cross-tenant reporting edge impossible;
  - `canView()` refuses another tenant's employee.
- The same holds for `MetricScope` (its cache fingerprint is `all-of-tenant-{id}`), the AI tools' `ScopesToHierarchy`, `AiConversationVisibility`, outcome analytics and the dashboards. All are covered by tests run as each tenant's CHRO.

## 7. Jobs, listeners and queues

**Every queued payload carries its tenant twice:**
- inside Laravel Context, which the worker restores before the job runs;
- as a top-level `tenant_id` (`Queue::createPayloadUsing`).

**`TenantQueueGuard` refuses the job** (it fails into `failed_jobs`, with its tenant) when:
- the two disagree;
- the tenant no longer exists;
- the tenant's status forbids background work.

A payload with no tenant is platform work: it runs with no tenant and cannot touch tenant data.

| Job | Tenant handling |
|---|---|
| SendCommunicationJob, RunAutomationExecutionJob, SyncInterviewCalendarJob, PublishJobDistributionJob, ConvertOfferLetterJob, ImportInterviewersJob, ProcessOwnershipHandoffJob, GenerateRoleDnaSuggestionsJob, SummarizeHiringMemoryJob, SummarizeOutcomeInsightJob, IndexAiDocumentJob, ReindexKnowledgeArticleJob (12) | Ids only, as before; they re-read their records through scoped queries, so another tenant's id finds nothing. Unique-job keys are global record ids, so they cannot collide across tenants. |
| RunTenantScheduledTask (new) | One scheduled task for one tenant. One attempt; unique per task and tenant; refuses a task that is not allow-listed or a context that is not its own. |
| Queued listeners, queued notifications and mail, Filament export / import batches | Same mechanism; no code in them names the tenant. |

**Row locks:** `RowLock` never locks across tenants.
- `fresh()` re-reads the caller's own row, with its tenant.
- `key()` locks through the `(tenant_id, id)` key. Another tenant naming the same id finds nothing and never waits on the owner's lock, which InnoDB would otherwise take on the primary-key row before checking `tenant_id` (`TenantIntegrityRaceTest`).

**Pitfall:** never return a `PendingDispatch` from `TenantContext::run()`. It queues only when destroyed, after the previous tenant is back. Use `Bus::dispatch()`, or a block callback. The architecture test enforces this.

## 8. Scheduled tasks (all 20)

| Task | Class | How it runs |
|---|---|---|
| `queue:prune-failed`, `cache:prune-expired`, `auth:clear-resets`, `queue:prune-batches` | platform | no tenant; technical housekeeping only |
| `queue:health-check` | platform + light tenant pass | platform signals (workers, scheduler, providers, configuration) are logged as `platform.alert`; each active tenant's administrators are alerted about their own failed jobs, backlog and stuck work |
| `incentives:release-matured` (intelligence), `notifications:dispatch-alerts` (notifications), `offers:expire-lapsed` (default), `interview-slots:expire` (default), `jobs:sync-distributions` (integrations), `communications:send-reminders` (communications), `recruitment:automation:dispatch` / `process` / `cleanup` (automation), `ai:expire-pending-actions` (intelligence), `identity:enforce-separations` (security), `reliability:sweep` (communications) | tenant job dispatcher | `tenants:dispatch <task>`: the scheduler only enumerates active tenants and queues one `RunTenantScheduledTask` per tenant |
| `performance:snapshot`, `intelligence:refresh`, `outcomes:evaluate` | tenant-iterating, background process | `tenants:run <task> --all` with `runInBackground()`: a separate process runs each tenant in turn, each isolated (a failure is reported and the others continue) and logged with its tenant and duration. These tasks outlast a queued job's ceiling: `intelligence:refresh` took 414 s cold at 520 open requisitions, against a 330 s `retry_after`. |

- Only tasks in `TenantTasks` can be run this way: an allow-list, so `tenants:dispatch db:wipe` is refused.
- Operators run a task for one tenant with `php artisan tenants:run <task> --tenant=<slug> [--with=option[=value] …]`.
- The same applies to the manual commands on tenant data (`TenantTasks::OPERATOR`): `identity:audit`, `identity:reconcile-access`, `lifecycle:audit`, `governance:audit`, `outcomes:backfill`, `ai:reindex-knowledge`, `ai:redact-history`, `recruitment:assign-default-pipelines`.
- Platform commands run without a tenant: `tenancy:verify`, `storage:audit`, `queue:drain-status`, `ops:heartbeat`, `ai:test-provider`, `ai:evaluate`. `ai:evaluate --live --user=…` runs inside that user's employing tenant.
- Overlap guards, `onOneServer` and the heartbeat are unchanged. The heartbeat reports a tenant task by its own name.

## 9. Cache, files, AI, search, analytics, notifications, audit

- **Cache** (`TenantCache::key()` → `t:{tenant}:{key}`):
  - Tenant-keyed: metric results; recruitment settings; active automation triggers; the Risk Radar scan lock; alert dedupe; the careers apply lock; the portal sign-in lockout.
  - Global on purpose (architecture allow-list): worker and scheduler heartbeats; the provider circuit breaker and Zoom token (shared platform credentials); held webhook statuses (provider message ids); step-up codes and message caps (global record ids); staff credential keys (global identity); spatie's permission cache, which is complete across tenants by design.
- **Files** (`TenantStorage::path()` → `tenants/{tenant}/{area}/…`):
  - What goes there: every new upload and generated file — resumes, candidate and joining documents (portal and careers too), employee photos, offer letters and templates (including each tenant's standard template), AI documents, interviewer imports, and export files (`Export::getFileDirectory()`).
  - Earlier files keep their path: they all belong to Tenant #1 and are reached only through their owning record.
  - `PrivateFileController` requires all of:
    - the signed user;
    - the signed tenant, which that user may act in;
    - a record of that tenant owning the path (`PrivateFileController::OWNERS`);
    - the `view` policy on that record (or on its offer or template).
  - A valid signature alone is not enough.
- **AI:**
  - Every AI table is tenant-owned. `ai_document_chunks` carries `tenant_id` itself, and `VectorSearch` filters chunks on it directly, never only through their document.
  - The approval fingerprint includes the tenant.
  - Usage is metered per tenant. A call with no tenant (diagnostics) is logged as `ai.platform_usage` and never charged to a tenant.
  - The system prompt names the tenant organisation.
  - All 49 tools are tested against another tenant's records.
- **Search:** Filament global search, tables, pickers, the command palette, duplicate detection, Talent Rediscovery and AI search all read tenant-scoped models. Form uniqueness uses `scopedUnique()`, except `users.email`, which is global identity.
- **Analytics:** governed metrics are tenant plus hierarchy; raw metric subqueries name the tenant. Outcome learning runs per tenant (`tenants:run outcomes:evaluate`); there is no cross-tenant benchmarking.
- **Notifications:**
  - In-app notifications are tenant-owned (`App\Models\DatabaseNotification` via `User::notifications()`), so a person sees each tenant's notifications only there.
  - Alert recipients, fallback owners and automation run inside the tenant.
  - Platform alerts with no tenant are logged, never sent to a tenant.
- **Audit:**
  - `audit_logs.tenant_id`: the tenant stream is scoped (`audit.view` means this tenant).
  - Entries about a tenant-owned record take that record's tenant.
  - Identity events before tenant selection (sign-in, MFA) are recorded in every tenant the person belongs to.
  - The rest is the platform stream (null), which no tenant query reaches.
- **Queue health:**
  - The panel page shows the tenant's own queued and failed jobs (by payload tenant), and retries only them.
  - `/health/queue` and `queue:drain-status` are the platform view; the endpoint now accepts only the monitoring token.

## 10. URLs that changed

| Before | After |
|---|---|
| `/admin/...` | `/admin/{tenant-slug}/...` (sign-in, password reset, email change, MFA set-up and profile stay tenant-less) |
| `/careers`, `/careers/{posting}`, `/careers/feed.xml` | `/careers/{tenant-slug}`, `/careers/{tenant-slug}/{posting}`, `/careers/{tenant-slug}/feed.xml` |
| `/portal/...` | `/portal/{tenant-slug}/...` |
| `/integrations/calendar/{provider}/connect` | `/integrations/calendar/{tenant-slug}/{provider}/connect` (the callback URL registered with providers is unchanged) |

## 11. Architecture tests (`tests/Feature/Tenancy/TenancyArchitectureTest.php`)

They fail when new code steps around the boundary:
- a tenant model without `BelongsToTenant`;
- Filament export / import records that are not the tenant-owned models;
- a tenancy bypass outside the reviewed files;
- a raw tenant-table query without `tenant_id`;
- a cache entry or lock without `TenantCache` outside the allow-list;
- an upload outside `TenantStorage`;
- a `PendingDispatch` escaping `TenantContext::run()`;
- a route that neither lives under a tenant nor resolves its tenant in a documented way;
- a private-file owner pointing at a column that does not exist.

| Allow-list | Entries (reason in the test) |
|---|---|
| Tenancy bypass | `TenantScope` (defines it), `RowLock` (re-imposes the tenant), `DeliveryStatusService` (provider message → tenant), `ResolveTenantForFilamentDownload` (export → tenant, then access check), `QueueHealthService` (platform counts) |
| Raw queries without the tenant | `TenantBackfill`, `TenancyVerifier`, `StorageAudit` (platform-level, read-only except the backfill); `User` (employing tenant); `EmployeeObserver` (rows built with the tenant) |
| Global cache keys | heartbeats, circuit breaker, Zoom token, held statuses, step-up, message cap, credentials, `MetricService` (key built by `TenantCache`) |
| Tenant-less routes | staff auth pages, profile, Filament downloads, private files, webhooks, calendar callback, health, Livewire assets and updates, the dev-only `_boost` route |

## 12. Known limitations and next phases

| Area | State in SaaS-1 | Phase |
|---|---|---|
| One identity in several tenants | supported by memberships and per-tenant roles; the employee link stays on `users` (one employing tenant) | SaaS-2 |
| Global `users.email` | creating a login whose email exists in another tenant fails with "already used", which reveals that the address exists | SaaS-2 (invite an existing identity) |
| Tenant switcher, invitations UI, SSO | Filament's switcher lists the person's tenants; no SSO | SaaS-2 |
| Branding, sender identity | the panel, mail and PDFs still use `config('app.name')`; `tenants.branding` is unused | SaaS-5 |
| Timezone, currency, country | stored on the tenant; the application still uses `metrics.business_timezone`, INR and +91 | localisation (SaaS-3 / 5) |
| Employee photos | new photos are under `tenants/{id}/` but still on the public disk (URL known means readable) | SaaS-5, object storage |
| Platform plane | no platform panel; platform alerts go to the log; integration provider credentials are platform-wide | SaaS-2 / 5 / 6 |
| Provider circuit breaker | one per shared provider account: one tenant's failures pause sending for all | SaaS-6 (per-tenant accounts) |
| Fair scheduling | per-tenant jobs share workers; no per-tenant caps | SaaS-7 |
| List indexes | plans unchanged with one tenant; `(tenant_id, created_at)`-style indexes to be measured with a real multi-tenant distribution | SaaS-7 |
| Plans, billing, provisioning, API, custom domains, database per tenant | not started (out of scope) | SaaS-3…7 |
