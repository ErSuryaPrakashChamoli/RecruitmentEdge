# SaaS-1 — Decision Register

**For:** the project owner, Security, Operations and Engineering.

**Status:** each decision below is either implemented on `feature/saas-1-tenant-foundation` or explicitly left open.
- Implemented decisions are technical choices made inside the SaaS-1 brief, and the SaaS audit's recommendations where the brief adopted them.
- Each can be revisited; the impact of changing it is noted.
- Open items need an owner.

## 1. Architecture decisions (implemented)

| ID | Decision | Why | Alternatives rejected | Revisit when |
|---|---|---|---|---|
| D-S1-01 | **Pooled shared schema**: `tenant_id` on tenant-owned rows; one database. | Brief rule 16 and the audit's recommendation (Option C, pooled first). Fits the single-writer services, policies and governed metrics with the least change. | Database per tenant (operations ×N, connection switching everywhere). | An enterprise tenant needs dedicated storage or residency (later phase; same schema). |
| D-S1-02 | **TenantContext is stored in Laravel Context** (`tenant_id`). | One source for HTTP, Livewire, jobs (Context is serialised into every queued payload and restored by the worker), commands and logs (the tenant on every log line). | A separate request singleton plus custom payload code per job. | — |
| D-S1-03 | **The tenant scope fails closed**: no tenant ⇒ the query throws. | Brief rules 5 and 7: never "all tenants". Found every tenant-less path during the build. | A no-op scope with no tenant (silent cross-tenant reads). | Never. |
| D-S1-04 | **Filament native tenancy** (`->tenant(Tenant::class, slug)`), with `SetTenantContextFromPanel` as persistent tenant middleware. | Brief §14. Filament provides URLs, the switcher and `canAccessTenant`. Isolation does not depend on it (layers 2–5). | A custom panel tenancy (forbidden). | — |
| D-S1-05 | **The Roles resource is excluded from Filament's tenant scope** (`isScopedToTenant = false`); explicit `forCurrentTenant()` plus `RolePolicy` instead. | Filament would add a global scope to `Role`, which would also limit the roles spatie loads into its single permission cache, wrong for every other tenant. Same for Users (global identities, filtered by membership). | Filament scoping on Role. | spatie supports per-team caches. |
| D-S1-06 | **Spatie teams = tenants** (`team_foreign_key = tenant_id`, `TenantTeamResolver` reads TenantContext). Every role belongs to one tenant; there are no global roles. | Per-tenant roles without a parallel RBAC; no team state to keep in sync. | Global role templates editable by any tenant. | SaaS-2 role templates (copied per tenant). |
| D-S1-07 | **Composite foreign keys for CASCADE / RESTRICT references (108); in-app checks for SET NULL references (157).** | MySQL cannot null one column of a composite key while `tenant_id` is NOT NULL. `BelongsToTenant` checks the 157 before every save, and `tenancy:verify` re-checks the stored data. | Triggers (heavy, engine-specific); changing ON DELETE behaviour (a business change). | MySQL allows column-level SET NULL. |
| D-S1-08 | **35 unique keys become per tenant**: business codes and slugs, configuration keys, and every key looked up through a scoped query. Random public ids, id-pair keys, provider event ids and `users.email` stay global. | A key looked up per tenant must be unique per tenant, or a collision in one tenant becomes a failure (and an existence probe) in another. | Converting every unique index. | — |
| D-S1-09 | **Tenant #1** = the existing organisation, created by the backfill from `TENANT_ONE_*` configuration only when the database already holds an organisation. Codes, ids and sequences are untouched. | Brief §26; never an implicit "default tenant" at run time. | A runtime default tenant (forbidden). | — |
| D-S1-10 | **The old permission migration is pinned to teams-off.** | It read `config('permission.teams')`: with teams on, a fresh install would diverge from an upgraded database. | Two schema paths. | — |
| D-S1-11 | **Global identity, tenant memberships; `users.employee_id` kept** (one employing tenant in SaaS-1). | Brief §13: prepare, do not implement, SaaS-2. Keeps all employee-based code working. | Moving the employee link now (a large identity change). | SaaS-2. |
| D-S1-12 | **MFA required if any tenant requires it** (identity-wide, read across tenants). | Before a tenant is chosen, team-scoped roles are empty: a tenant-scoped check would waive MFA on tenant-less pages. | A per-tenant MFA requirement. | SaaS-2 per-tenant MFA policy (stricter-of). |
| D-S1-13 | **Access state belongs to the identity; only the employing tenant may change it.** | One tenant must not suspend a person's access to another. | Any member tenant may suspend. | SaaS-2 (per-membership suspension). |
| D-S1-14 | **"View all" = the whole current tenant** (`null` kept; its meaning bounded by the scope, closure-table filters, `canView` and `Gate::before`). | Brief §16 without a 100-call-site signature change; the bound is enforced in several layers and tested as each tenant's CHRO. | A new return type everywhere. | — |
| D-S1-15 | **`Gate::before` denies any ability on another tenant's record or role.** | Defence in depth for policies written as "view-all ⇒ allow". | Relying on scoped loading alone. | — |
| D-S1-16 | **Public surfaces carry the tenant in the path** (`/careers/{slug}`, `/portal/{slug}`); never Host. | Brief §7 (no Host trust, SEC-001 unchanged); subdomains and custom domains are later phases. | Subdomain resolution now. | SaaS-5 / SaaS-6 domains (from a verified domain table). |
| D-S1-17 | **Candidate portal accounts are tenant-owned** (email unique per tenant). | Audit F-1 recommendation; a candidate applies to each employer separately. | A global candidate identity. | — |
| D-S1-18 | **Audit log: tenant stream + platform stream in one table** (`tenant_id` null = platform). Pre-tenant identity events go to every tenant the person belongs to. | Audit F-5: separate streams, one immutable writer and redaction; tenants keep their staff's sign-in trail. | A second audit table (duplicated machinery). | SaaS-5 platform audit UI. |
| D-S1-19 | **Integration statuses are tenant-owned**; there is no platform provider-health table. | Audit F-6 split: the existing table only holds a tenant's own connection tests (with the tester, a tenant employee). Real platform signals (circuit breaker) stay platform. | Keeping one global row (a tenant's test would change every tenant's view). | SaaS-6. |
| D-S1-20 | **AI evaluations stay platform** (`ai_evaluations`, `ai_evaluation_runs`). | Audit F-3 / 4: questions and expected tools, no tenant data. | — | Tenant-authored evaluations. |
| D-S1-21 | **Webhook events: tenant resolved from the provider's message id; an opt-out applies in every tenant holding the recipient.** | Audit F-2: the shared sender has no tenant; honouring "STOP" for the shared sender is the privacy-protective reading, applied inside each tenant. | Opting out in one tenant only (which one?); ignoring it. | SaaS-6 per-tenant sender accounts. |
| D-S1-22 | **Scheduler: the dispatcher queues one job per tenant; the three long tasks run per tenant in a background process.** | Brief §18. Long tasks outlast a queued job's 330 s ceiling (`intelligence:refresh` 414 s cold at 520 requisitions). | Everything queued (would fail at scale); loops inside the scheduler (forbidden). | Chunked long tasks (SaaS-7). |
| D-S1-23 | **Queue health split: tenant scope (panel) vs platform scope (`/health/queue` token-only, `queue:drain-status`, platform alerts to the log).** | The page and alerts showed every tenant's failed jobs to every tenant. | Hiding the page. | SaaS-5 platform panel. |
| D-S1-24 | **File prefix `tenants/{id}/…` for new files; legacy paths stay and are reached through their record.** | Brief §20, without a risky file move in SaaS-1. | Moving files now. | SaaS-5 controlled move. |
| D-S1-25 | **AI usage with no tenant is logged, not stored.** | `ai_usage_logs` is tenant-owned (metering); diagnostics are platform usage. | A null-tenant usage row. | SaaS-3 metering. |
| D-S1-26 | **Development branch `feature/saas-1-tenant-foundation`** off `3fb40d6`. | Keeps the frozen release-candidate branch (`226bc7d` application) deployable as is. | Committing SaaS-1 onto the RC branch. | — |

## 2. Open (owner action)

| ID | Question | Owner | Recommendation |
|---|---|---|---|
| D-S1-O1 | Release order: Phase 8.11 first and SaaS-1 later (recommended), or one combined upgrade? | Release owner | RMS first; a combined upgrade needs a rehearsal from a production copy. |
| D-S1-O2 | Tenant #1 slug and names (`TENANT_ONE_*`) | Product + Ops | — |
| D-S1-O3 | Old links after the upgrade: accept the 404s, or a reviewed transition redirect for Tenant #1? | Product + Security | — |
| D-S1-O4 | Pause semantics for suspended tenants' queued work (S1-05) | Product + Ops | SaaS-3 lifecycle. |
| D-S1-O5 | Who are platform operators (alerts, health, support)? | Ops | SaaS-2 / 5 platform plane. |
| D-S1-O6 | Provider accounts per tenant (S1-02) | Product + Finance | SaaS-6. |
