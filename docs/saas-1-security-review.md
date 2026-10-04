# SaaS-1 — Security Review: Can Tenant A Reach Tenant B?

**For:** Security, the release owner, and Engineering.

**Question reviewed:** can Tenant A cause the application to read, modify, delete, execute, download or infer Tenant B's data?

**Answer, as far as tests prove it:** **no**, on every path listed below.
- Each path has a test that tries it, as a **"view all" CHRO** (`hierarchy.view-all`). That is the historical failure mode, where "view all" meant the whole database.
- The key protections were mutation-checked (§5).
- Open findings (§4) are availability, enumeration, branding and operations issues. None is a cross-tenant read or write.

**Evidence:**
- `tests/Feature/Tenancy/*`: 102 tests, plus `tests/Concurrency/TenantIntegrityRaceTest.php` on MySQL.
- Full suite: 2,238 / 2,238 on SQLite. The MySQL results are in the SaaS-1 final report.
- Two migration rehearsals with `tenancy:verify` (`saas-1-migration-plan.md` §5).

Fixtures: `TenantWorld` builds two complete tenants, ALPHA and BRAVO, with:
- users, employees, departments, requisitions, postings, candidates, applications, interviews, offers, joinings, documents and files;
- portal accounts, AI documents and chunks, knowledge articles, conversations, automation rules, settings, notifications and audit entries.

They share the same business codes (department `ENG`, identical candidate / requisition / offer codes, the same posting slug, the same candidate email and mobile), and every visible value carries the tenant's marker.

## 1. Bypass attempts (prompt §34)

| Attempt | Result | Evidence |
|---|---|---|
| Eloquent query, `find()`, `findOrFail()` of B's id inside A | nothing found; update and delete affect 0 rows | `TenancyFoundationTest` "another tenant's row is invisible" |
| Query with no tenant at all | refused (`MissingTenantContext`), never widened | `TenancyFoundationTest` |
| `withoutGlobalScopes()` / `withoutGlobalScope(TenantScope)` / `withoutTenancy()` | allowed only in five reviewed files | `TenancyArchitectureTest` "crossing tenants happens only in reviewed places" |
| Raw `DB::table()` | every tenant-table query names `tenant_id` (platform tools allow-listed) | `TenancyArchitectureTest` |
| Raw SQL insert linking A to B's parent | refused by the database (composite foreign key) | `TenancyFoundationTest` |
| Raw insert with no tenant | refused (`NOT NULL`) | `TenancyFoundationTest` |
| Write a row for B while in A; move a row to B | refused (`CrossTenantViolation`) | `TenancyFoundationTest` |
| Link a record in A to B's department (`ON DELETE SET NULL` reference) | refused in-app; detected by `tenancy:verify` if written behind the model's back | `TenancyFoundationTest` |
| Panel URL of another tenant `/admin/bravo/…` | 404 | `CrossTenantPanelTest` |
| B's record id under A's panel (view, edit; candidates, applications, requisitions, offers, employees, users, roles, AI conversations, automation rules) | 404 on every page | `CrossTenantPanelTest` |
| Every resource's query as a view-all user | only A's rows, for all 54 resources | `CrossTenantPanelTest` |
| Global search for B's values | nothing | `CrossTenantPanelTest` |
| Table, row and bulk actions with B's key (Livewire) | the key resolves to nothing | `CrossTenantPanelTest` |
| Assign B's role to an A user; edit B's role; suspend B's login | ignored / 404 / refused | `CrossTenantPanelTest` |
| Staff lists (Users, Access Review, staff filters) as a view-all reviewer | only A's members | `CrossTenantPanelTest` |
| Policy check on B's record by A's CHRO | denied (`Gate::before`) | `CrossTenantServicesTest` |
| Hierarchy "view all" | all of A only; B's employee never "in view" | `CrossTenantServicesTest` |
| Relation managers and pivots | relations query scoped models; pivots are `TenantPivot` rows of their tenant | foundation; `TenancyArchitectureTest` |
| Model binding (routes) | resolved inside the request's tenant | panel 404 tests; portal binding |
| Exports | the table query is scoped; the download of B's export is 404 | `CrossTenantPublicSurfacesTest`; `SEC8803ExportGovernanceTest` |
| Signed private-file URLs: B's path signed in A, URL signed in B, tenant parameter tampered | 404 / 404 / 403 (signature) | `CrossTenantPublicSurfacesTest` |
| Queued job for B's record dispatched in A; payload tenant altered; suspended tenant | runs only in its own tenant; refused; refused | `CrossTenantAsyncTest` (real database worker) |
| Scheduled commands | one job per active tenant, each declaring its tenant in its payload; never a cross-tenant sweep; arbitrary commands refused | `CrossTenantAsyncTest` |
| AI tools (all 49) aimed at B's ids | no B data returned, nothing of B changed | `CrossTenantAiTest` |
| RAG retrieval | only A's chunks (filtered on the chunk's own `tenant_id`) | `CrossTenantAiTest` |
| AI approvals | B's pending call cannot be approved from A; the fingerprint includes the tenant | `CrossTenantAiTest` |
| Cache keys (metrics, settings, triggers, locks) | per tenant; A's view-all result never served to B | `CrossTenantServicesTest`; `TenancyArchitectureTest` |
| Notifications and alerts | per tenant; a person in two tenants sees each tenant's own | `CrossTenantPanelTest`, `CrossTenantServicesTest` |
| Automation | active triggers, owners and fallback owners per tenant | `CrossTenantServicesTest` |
| Polymorphic relations (audit subjects, evidence owners, timeline subjects, chunk sources, …) | the subject lives in the same tenant; checked by `tenancy:verify` | `TenancyVerifier` (16 morph columns) |
| Audit | A sees only A's stream, never B's or the platform's | `CrossTenantPanelTest` |
| Careers: same slug in two tenants; apply on B's site | each site shows its own; the application is created in B only | `CrossTenantPublicSurfacesTest` |
| Candidate portal: same email in both tenants | two accounts; A's password does not open B's; the guard resolves only within the tenant | `CrossTenantPublicSurfacesTest` |
| Provider webhooks | applied in the message's own tenant; an opt-out in each tenant holding the number, each inside its tenant | `CrossTenantPublicSurfacesTest` |
| Calendar OAuth callback carrying another tenant | 403 | `CrossTenantPublicSurfacesTest` |
| Host header / forged origin | the tenant never comes from Host; SEC-001 (`forceRootUrl`, trusted hosts) unchanged | `P810SEC001PasswordLinkOriginTest` |
| Concurrency | per-tenant sequences under real two-transaction races; a lock never reaches, waits on or reveals another tenant's row | `TenantIntegrityRaceTest` (MySQL, 3 races) |

## 2. Findings fixed during SaaS-1

Found by the cross-tenant suite, the rehearsals or review. All are fixed and covered by tests.

| ID | Severity (multi-tenant) | Finding | Fix |
|---|---|---|---|
| S1-F01 | High | `tenants:dispatch` queued each job only after `TenantContext::run()` had restored the previous tenant (`PendingDispatch` queues on destruct). Every scheduled tenant task would have carried the wrong tenant, or none. | `Bus::dispatch` inside the tenant; a payload test plus an architecture guard. Mutation-checked. |
| S1-F02 | High | `HierarchyService::canView()` let a view-all user "see" any tenant's employee (null = unrestricted). | Refuses another tenant's employee; plus `Gate::before` denying every ability on another tenant's record or role. |
| S1-F03 | High | `AuthorityGuard::inScope()` (null = every login) would let one tenant's CHRO suspend or revoke another tenant's staff through the identity services. | Only members of the current tenant are in scope; only the employing tenant changes access state. |
| S1-F04 | High | Pre-tenant MFA check: team-scoped roles are empty before a tenant is chosen, which would waive MFA on tenant-less pages. | The MFA requirement is evaluated across every tenant the person holds a role in (the current tenant's loaded roles when the person belongs only to it); tested and mutation-checked (M9). |
| S1-F12 | High | Access Review (`AccessReview::reviewQuery`) listed every login for a view-all reviewer — every tenant's staff (names, emails, roles, sessions); staff identities have no `tenant_id`, so the tenant scope does not apply to them. | `User::membersOfCurrentTenant()` on the review, the audit log's user filter, fallback owners, access reconciliation and the identity audit; tested and mutation-checked (M7). |
| S1-F05 | Medium | Provisioned and rehired logins had no membership (could not reach their tenant). | Membership created with the login. |
| S1-F06 | Medium | The export audit hook was registered on Filament's base `Export` class; the tenant-owned subclass did not fire it (`export_requested` audit lost). | Registered on `App\Models\Export`. |
| S1-F07 | Medium | Queue health page, `/health/queue` and the health check mixed every tenant's failed jobs and alerted every tenant's administrators about all of them. | Tenant scope (payload tenant) vs platform scope; endpoint token-only; platform alerts to the log. |
| S1-F08 | Low | `TimeToFill` and `QueriesOffers` aggregated every tenant's rows before joining to scoped records (correct results, cross-tenant scan). | Subqueries name the tenant. |
| S1-F09 | Low | Duplicate-match order depended on database row order. | Deterministic order. |
| S1-F10 | Low | `RowLock::key()` locked by primary key: a request from another tenant naming a locked id waited on (and could time) the owner's lock, because InnoDB locks the primary-key row before checking `tenant_id` (found by `TenantIntegrityRaceTest` on MySQL). | The lock goes through the `(tenant_id, id)` key; the race test fails before the fix and passes after it. |
| S1-F11 | Medium | Private-file owner lookup queried `employee_referrals.resume_path`, which does not exist (a latent bug already in the release candidate, hidden on SQLite). On MySQL, AI-document and offer-letter previews would have failed with an error. | Removed (a referral's resume is on its candidate); an architecture test checks every owner column exists. |

## 3. Layers and their tests

| Layer | Mechanism | Tests |
|---|---|---|
| Context | `TenantContext` (Laravel Context), `ResetTenantContext`, resolvers (`saas-1-tenant-foundation.md` §4) | foundation, public surfaces, async |
| Model | `TenantScope` (fail closed), `BelongsToTenant` (stamp, immutability, in-app references) | foundation, architecture |
| Authorization | policies; `Gate::before` cross-tenant denial; `canAccessTenant`; `AuthorityGuard` | panel, services |
| Database | `NOT NULL`, 108 composite foreign keys, 35 per-tenant uniques, spatie team keys | foundation; rehearsals R1 / R2 |
| Async | payload tenant, `TenantQueueGuard`, per-tenant scheduler | async |
| Cache / files / AI | `TenantCache`, `TenantStorage`, `PrivateFileController::OWNERS`, chunk `tenant_id` | services, public surfaces, AI, architecture |

## 4. Open findings

None is Critical or High. None lets one tenant read or change another's data.

| ID | Severity | Area | Evidence | Impact | Next phase |
|---|---|---|---|---|---|
| S1-01 | Medium | Identity enumeration | `users.email` is global (identity). Creating a login, or converting a candidate, whose email belongs to another tenant's staff fails with "already used" (`IdentityProvisioningService`, `UserForm` uniqueness). | Reveals that an email address has a login somewhere on the platform. | SaaS-2: invite an existing identity instead of creating one; neutral message |
| S1-02 | Medium | Availability (shared providers) | `ProviderCircuitBreaker` and provider credentials are platform-wide (`config/services.php`). | One tenant's failing sends can pause a provider for all tenants. | SaaS-6: per-tenant provider accounts and breakers |
| S1-03 | Medium | Fair use | No per-tenant concurrency caps on workers; tenant jobs share queues. | A tenant with heavy automation or AI can delay others. | SaaS-7 |
| S1-04 | Medium | Upgrade: old links | URLs gained the tenant slug (`saas-1-tenant-foundation.md` §10). | Previously emailed portal and scheduling links and stored notification URLs 404 after the upgrade. | Release planning (owner decision, migration plan §2.4) |
| S1-05 | Medium | Suspended tenant's queued work | `TenantQueueGuard` fails the jobs of a tenant that may not have work done. | After reactivation those jobs are in `failed_jobs` and need a retry (an operator step). | SaaS-3: tenant lifecycle (pause / resume) |
| S1-06 | Low | Files | Employee photos stay on the public disk (random names; new ones under `tenants/{id}/`). | A leaked photo URL is readable without signing in. | SaaS-5: private storage |
| S1-07 | Low | Files | Pre-SaaS-1 files keep their legacy paths (all Tenant #1). | No isolation impact (reached only through their record); a per-tenant export or purge must include them. | SaaS-5 |
| S1-08 | Low | Branding | Panel, mail, PDFs and careers use `config('app.name')`; `tenants.branding` is unused. | Tenants see the platform's name, not their own. | SaaS-5 |
| S1-09 | Low | Localisation | Tenant timezone, currency and country are stored, but the application still uses `Asia/Kolkata`, INR and +91. | Wrong dates and currency for a non-India tenant. | Localisation (SaaS-3 / 5) |
| S1-10 | Low | Permission cache | spatie keeps one permission → roles map for all tenants (by design: it must see every role). | Any tenant's role change refreshes it for everyone (cache churn at scale). | SaaS-7 |
| S1-11 | Info | Platform plane | No platform panel; platform alerts are log-only (`platform.alert`); the panel's Queue health page is per tenant. | Operators rely on logs and `/health/queue`. | SaaS-2 / 5 |

Pre-existing within-tenant findings from the SaaS audit (E-RMS-1 RecruitmentCosts visibility, E-RMS-2 the Pipeline move modal's read, E-RMS-3 `VerifyEmailChange` not queued) are unchanged. They are now bounded by the tenant. They stay in the post-RMS backlog.

## 5. Mutation checks

Each protection was removed by hand, the tests were run, and the code was restored. Each removal made its tests fail.

| # | Protection removed | Tests that failed |
|---|---|---|
| M1 | `TenantScope` filter (Filament tenancy left active) | 19 across foundation, panel and services |
| M2 | `Gate::before` cross-tenant denial | "no ability is granted on another tenant's record" |
| M3 | `TenantQueueGuard::check` | "payload names another tenant" and "tenant that may no longer have work done" |
| M4 | private file owning-record and policy check | "a private file is served only to someone who may act in the signed tenant" |
| M5 | tenant in the metric cache key and fingerprint | "governed metrics … never cached together" |
| M6 | `Bus::dispatch` → returned `PendingDispatch` in `tenants:dispatch` | "the scheduler queues one run per active tenant, each declaring its own tenant" |
| M7 | the members-only filter on the Access Review | "the access review and staff pickers list only the tenant's own staff" |
| M9 | the identity-wide MFA requirement (tenant-scoped roles only) | "MFA follows the identity: required everywhere when any tenant the person belongs to requires it" |
| M8 | the `(tenant_id, id)` index for `RowLock::key()` (the pre-fix code) | `TenantIntegrityRaceTest` "a row lock held in one tenant is never taken, waited on or read by another tenant" (MySQL) |

## 6. What this review does not cover

- Penetration testing by a third party (SaaS-7).
- Production configuration (TLS, proxies, Host allow-list values).
- Provider-side isolation (shared accounts, S1-02).
- Denial of service beyond the noted availability items.
