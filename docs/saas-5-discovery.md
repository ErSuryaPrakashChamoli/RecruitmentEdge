# SaaS-5 — Discovery Report: Platform Administration, Compliance & Operational Control

**For:** Engineering, Security and the project owner.

**Baseline:** `feature/saas-5-platform-control` from `9a3d04b` (SaaS-4).
- SQLite 2,537 / 2,537 and MySQL 2,537 / 2,537.
- SaaS-1 105, SaaS-2 101, SaaS-3 86, SaaS-4 90.
- Architecture 22, Security 276, concurrency 41.

Every statement below was verified in code (file references given), not taken from earlier reports.

**Result:** no material architectural or security conflict, so no STOP condition. Policy questions (retention, grace, approvals) are handled as configurable safe defaults and recorded as owner decisions, as the brief allows.

## 1. Existing platform-control architecture

- **Platform identity plane (SaaS-2):**
  - `platform_operators` (`app/Models/PlatformOperator.php`): one row per (user, role), granted and revoked by `PlatformAccess` (`app/Services/Platform/PlatformAccess.php`, console `platform:operator`), audited in the platform stream.
  - Identity lock: `users.disabled_at` via `PlatformIdentityService` (`identity:disable`).
- **Commercial control plane (SaaS-3 / SaaS-4):** `app/Services/Platform/Commercial/*` and `app/Services/Billing/*`, gated by `PlatformOperatorGate` (platform administrator, or the console with no operator).
- **No platform UI.** Only the tenant panel exists (`app/Providers/Filament/AdminPanelProvider.php`, `/admin/{tenant}`). Platform work is console-only.
- `IdentityPlaneArchitectureTest` forbids the tenant plane from referencing `PlatformOperator`, `PlatformAccess`, `SupportAccessGrant`, `SupportAccessService` or `PlatformRole` outside `app/Services/Platform/**` and listed files.

## 2. Existing platform operator capabilities

- **Roles** (`App\Enums\PlatformRole`): administrator, support, compliance. They are role-based, with no capability model.
- **Only `PlatformRole::Administrator` is ever consulted:**
  - `PlatformOperatorGate::assert()` covers plans, overrides, lifecycle, provisioning, prices and billing operations;
  - support and compliance roles authorise nothing today.
- **Operators are not tenant members.** `User::canAccessTenant()`, `permits()`, policies and `Gate::before` ignore platform roles (`PlatformBoundaryTest`).

## 3. Existing support-access capabilities

- **Record:** `support_access_grants` is tenant-owned (`BelongsToTenant`), with columns `platform_operator_id`, `granted_by`, `reason`, `starts_at`, `expires_at`, `revoked_at`, `revoked_by`.
- **Service:** `SupportAccessService` (`app/Services/Platform/SupportAccessService.php`):
  - `grant()` by a tenant `users.access.manage` holder, for an active **support** operator, at most `identity.support.max_minutes` (480);
  - `revoke()`;
  - `activeGrant()`.
- **Nothing honours a grant.** No page, route or service reads it to reach tenant data, and no UI exists to create one (only tests call the service).
- **Missing:** request / approval flow, scopes, status, expiry recording, use auditing.

## 4. Existing tenant lifecycle controls

- **`TenantLifecycleService` (SaaS-3)** is the only writer of `tenants.status`, under the tenant row lock, audited, with an explicit transition table: provisioning → trial / active … cancelled → deletion_pending → deleted.
- **Gaps:** `deletion_pending` and `deleted` are states only; nothing purges. There is no transition back out of `deletion_pending`.
- **Time-based access:** `Tenant::effectiveStatus()` evaluates `trial_ends_at` (SaaS-3) and `access_ends_at` (SaaS-4) per request.
- **Console:** `tenants:lifecycle`, `tenants:lifecycle-sweep`.

## 5. Existing ownership controls

- **Model:** `tenant_memberships.is_owner`, unique per tenant (SaaS-3).
- **How it is set:** only by accepting a provisioning owner invitation (`TenantInvitationService::join()`, `grants_ownership`).
- **No transfer, no reassignment, no reading of `is_owner`** anywhere else (grep: `TenantProvisioningService`, `TenantInvitationService` only).
- Tenants that predate SaaS-3 have no owner.

## 6. Existing deletion/purge capabilities

- **None.** No tenant purge; models refuse deletes of billing records and plan versions.
- **Policies:** `ForbidsDeletion` blocks hard deletes of many records.
- **Files:** `TenantStorage` (`tenants/{id}/…`) is designed so "a tenant's files can be exported or purged as a whole". Legacy pre-SaaS-1 paths (Tenant #1) are reached through their rows (`StorageAudit::REFERENCES`).

## 7. Existing audit mechanisms

- **One table, two streams:** `audit_logs` (`app/Models/AuditLog.php`).
  - Tenant stream: `tenant_id` set, `TenantScope`.
  - Platform stream: `tenant_id` null.
- **Attribution:** `record()` sets `user_id`, polymorphic actor, `actor_kind` (user / candidate / automation / ai / scheduler / console / queue / system), `on_behalf_of_user_id`, `reason`, `request_id` (correlation), `ip_address`, old and new values (redacted per model).
- **No append-only guard.** Rows are only ever created (grep shows no update or delete in `app/`), but the model allows `update()` and `delete()`.
- **Tenant viewing:** `AuditLogResource` is view-only (list and view, `canCreate` false, no edit or delete pages) behind `audit.view`.
- **No platform audit viewer.**

## 8. Existing export/compliance mechanisms

- **Exports:**
  - Filament exports (`app/Models/Export.php`, tenant-owned, files under `tenants/{id}/filament_exports/{id}/`, gated by `exports.data` in SaaS-3);
  - report CSVs (`ReportExportService`).
- **Not a compliance mechanism:** no tenant data export, no export register, no artifact expiry for any export.
- **Read-only audit commands:** `governance:audit`, `identity:audit`, `lifecycle:audit`, `storage:audit`, `tenancy:verify`.

## 9. Existing file deletion mechanisms

- **Private files:** `PrivateFileController` serves files through `OWNERS` (record + ability), with signed 5-minute URLs.
- **Reporting only:** `storage:audit` reports usage and unreferenced files and deletes nothing.
- **No file deletion on purge** (no purge exists).
- **Employee photos are on the public disk** (`Employee::photoUrl()`, `EmployeeForm`, `Profile`; S1-06): a leaked URL is readable without signing in.

## 10. Existing notification mechanisms

- **In-app:** `DatabaseNotification` (`notifications`, **tenant-owned**), so platform notifications cannot live there.
- **Mail:** mailables and notifications on queues; payloads encrypted or ids-only (`QueuePayloadPrivacyTest`).
- **Platform alerts are log lines only** (`Log::warning('platform.alert', …)`). There is no platform event feed.

## 11. Existing platform jobs/scheduler

- **Registry:** `TenantTasks` — QUEUED / BACKGROUND / OPERATOR tasks per tenant through `tenants:dispatch` / `tenants:run`.
- **PLATFORM tasks:** queue housekeeping, `tenants:lifecycle-sweep`, `billing:sweep` / `billing:reconcile` / `billing:prune-events`.
- **Queue guard:** `TenantQueueGuard` refuses jobs of unusable tenants (into `failed_jobs`). Platform jobs carry no tenant (`ProcessBillingEvent`).
- **Operational visibility:** `QueueHealthService` (platform `/health/queue` with token; tenant-scoped `QueueHealth` page).

## 12. Existing authorization boundaries

| Plane | Mechanism |
|---|---|
| Tenant | spatie roles per tenant (teams = tenants); policies; `Gate::before` (`crossesTenant`); `StaffAccessService` (membership, usable tenant) |
| Identity | `AuthorityGuard` (credentials of shared identities) |
| Commercial | `PlatformOperatorGate` |
| Billing | `billing.view` / `billing.manage` |

`User::canAccessPanel()` does not distinguish panels; only one panel exists.

## 13. Existing tenant isolation guarantees

- **Enforcement:** `TenantScope` (fail-closed), `BelongsToTenant`, composite tenant foreign keys, `TenantSchema` classification (architecture test).
- **Reviewed crossings:** `withoutTenancy()` is allowed only in reviewed files (allow-list in `TenancyArchitectureTest`). Raw `DB::table()` on tenant tables must name `tenant_id`.
- **Keys and payloads:** tenant cache keys (`TenantCache`), tenant queue payloads (`TenantQueueGuard`), tenant file prefix (`TenantStorage`).
- **Verification:** `tenancy:verify`. Routes must be under a tenant or allow-listed.

## 14. SaaS-1 → SaaS-4 carry-forward findings relevant to SaaS-5

| Finding | Source | Verified state | SaaS-5 disposition |
|---|---|---|---|
| S1-06 employee photos on the public disk | SaaS-1 | `Employee::photoUrl()` uses the public disk | **Fix:** private disk through `PrivateFileController`; idempotent move command |
| S1-07 legacy (pre-SaaS-1) file paths of Tenant #1 | SaaS-1 | reached through their rows | **Purge and export must include them** (done through `StorageAudit::REFERENCES`); the physical move is re-deferred (needs a production-copy rehearsal) |
| S1-08 branding: platform name used for tenant surfaces | SaaS-1 | offer letters' `company_name`, careers index and feed, candidate message templates, portal layout, candidate mails, incentive statement PDFs use `config('app.name')` | **Fix** the tenant-facing surfaces only (platform vs tenant brand) |
| S2-A3 invitation token in the web-server access log | SaaS-2 | `GET /invitations/{token}`; Apache logs `%U` (the path, without query string) | **Fix:** the token moves to the query string (never logged); the legacy path stays for links already sent |
| S2-A5 no platform panel or platform audit viewer | SaaS-2 | true | **Build** |
| S2-A6 support grants honoured by nothing | SaaS-2 | true | **Build** the scoped support workspace |
| SaaS-3: platform console; ownership transfer | SaaS-3 | console only; no transfer | **Build** |
| S1-10 permission cache churn; password-reset timing | SaaS-1/2 | — | stays SaaS-7 |
| SSO family; API platform; provider, prices, tax (SaaS-4) | — | — | out of scope |

## 15. Gaps

1. No platform panel, no capability model, no platform audit viewer.
2. Support grants: no request/approval, no scopes, no status, no use, no expiry recording, no tenant UI.
3. No ownership transfer.
4. No deletion workflow or purge; no exit from `deletion_pending`.
5. Audit not guarded as append-only; no platform actor kind.
6. No compliance export or artifact expiry.
7. No platform notification / event feed.
8. The defects in §14 (photos, branding, invitation-token logging).

## 16. Proposed SaaS-5 architecture

**Three planes, unchanged:**
- **Identity:** users, memberships, roles.
- **Tenant application:** the tenant panel.
- **Platform control:** a **separate Filament panel at `/platform`**, its code under `app/Filament/Platform/**`, so it is never discovered by the tenant panel. Its services live under `app/Services/Platform/**`.

**Panel access:**
- Holding an active platform role (and an enabled identity) admits a person to the platform panel only. MFA is required there whenever MFA enforcement is on.
- `User::canAccessPanel()` branches by panel id; the platform decision is made by a platform-plane gate.

**Explicit capabilities, mapped from the existing roles** (no new operator data):

| Capability | administrator | support | compliance |
|---|---|---|---|
| `platform.tenants.view` | ✓ | ✓ | ✓ |
| `platform.tenants.manage` (lifecycle, ownership) | ✓ | | |
| `platform.support.manage` (request and use support grants) | | ✓ | |
| `platform.compliance.manage` (compliance exports) | | | ✓ |
| `platform.audit.view` | ✓ | | ✓ |
| `platform.operations.view` | ✓ | ✓ | |
| `platform.deletion.manage` (request, approve, cancel, purge) | ✓ | | ✓ |

Every platform service checks its capability server-side; pages only mirror it.

**Authorities preserved:**
- SaaS-3 decides lifecycle and entitlements, SaaS-4 billing.
- SaaS-5 calls their services and never writes their state directly.
- The one SaaS-3 extension is a `deletion_pending → cancelled` transition, so a deletion can be withdrawn during its grace period, through `TenantLifecycleService`.

## 17. Data model changes

| Change | Class | Purpose |
|---|---|---|
| `support_access_grants` + `status`, `requested_scopes`, `scopes`, `requested_minutes`, `requested_at`, `decided_at`, `denial_reason`, `revocation_reason`, `last_used_at`, `use_count`; `starts_at` / `expires_at` nullable (a request has neither yet); backfill existing rows (status from dates; scopes = diagnostics) | tenant (existing) | request → approve → use → expire / revoke |
| `tenant_deletion_requests` | platform record about a tenant (`tenant_id` NOT NULL) | the deletion workflow and purge progress; one open request per tenant |
| `compliance_exports` | platform record about a tenant (`tenant_id` NOT NULL) | export register: requester, reason, scope, status, artifact, checksum, expiry, downloads |
| `platform_events` | platform (subject tenant optional — a platform event may concern no tenant) | the operational event feed and notifications; idempotent by `dedupe_key` |
| `audit_logs` | unchanged schema | append-only guard in the model; `platform` actor kind |
| employee photos | no schema change | files move from the public disk to the private disk |

The platform tables reference a tenant only to say which tenant a platform record is *about*. They survive the tenant's purge, which is why they are not tenant-owned.

## 18. Authorization model

- **Platform:** `PlatformAuthorization::can(User, PlatformCapability)`, from the operator's active roles. The identity must not be disabled.
- **Console commands** that change tenant state require a named operator (`--operator=email`), so they are attributable. The existing SaaS-3/4 console paths keep their console (no-operator) rule.
- **Two-person rule for deletion:** approval by a different operator than the request (configurable; owner decision).
- **Tenant side:**
  - a tenant's `users.access.manage` holders approve, deny, grant and revoke support access for their own tenant;
  - tenant administrators reach nothing in the platform panel (no platform role means no panel);
  - a CHRO or owner is never a platform operator by virtue of that role.
- **Support:** an active, unexpired, unrevoked grant for this operator and tenant, with the scope used, in a usable or suspended tenant. Checked on every request and Livewire update, under a row lock; every use is audited.

## 19. Operational workflows

| Workflow | Steps |
|---|---|
| Support | operator requests (tenant, scopes, minutes, reason) → tenant admins notified → approve (scopes ⊆ requested, minutes ≤ max) or deny → operator uses the **support workspace** (read-only views per scope: diagnostics, people, audit) → expiry, revocation or denial fails closed → sweep records the expiry |
| Ownership | platform administrator transfers to an active member who holds CHRO → atomic under the tenant lock; the old owner keeps their membership and roles (only the owner flag moves) |
| Deletion | tenant cancelled (SaaS-3) → request (reason) → approval by a second operator → `deletion_pending` + grace period → cancellable until purge starts → purge (scheduler or operator, after grace) → `deleted` |
| Purge | claim with a lease (one worker) → drop the tenant's queued and failed jobs → delete files (prefix and legacy references) → delete tenant rows table by table in foreign-key order, chunked, progress recorded → mark `deleted`. Idempotent and resumable; failure recorded and retried. Retained by default: billing records, audit logs, support grants, plan history (owner decision) |
| Compliance export | compliance operator requests (reason) → platform job writes the tenant's rows (JSON lines per table, sensitive columns excluded) and its files manifest into a private zip with a checksum → download by compliance operators (audited) → expires and is deleted after N days |
| Visibility | platform dashboard: lifecycle counts, provisioning failures, open deletion requests, active and requested support grants, failed background work per tenant, unprocessed billing events, recent platform events |

## 20. Security risks

| Risk | Control |
|---|---|
| Platform panel reachable by tenant administrators | separate panel, platform-role gate, server-side capability checks in every service |
| Support turning into impersonation or permanent access | no sign-in-as; scoped read-only views; per-request grant validation under lock; expiry fails closed |
| Accidental or malicious deletion | cancelled tenant only; two-person approval; grace period; purge only after grace; idempotent purge with a lease |
| Cross-tenant purge | purge keyed by the request's tenant id on every statement; no tenant can reach the service |
| Audit tampering | model append-only guard; no edit or delete UI; architecture test |
| Export leakage | private disk, no public URL, capability plus audit on download, expiry, sensitive columns excluded |
| Ownership races | tenant lock, then membership locks |
| Cross-tenant reads in platform listings | one reviewed read model per need, allow-listed |

## 21. Migration strategy

- **Expand:** new platform tables; new nullable grant columns; nullable `starts_at` / `expires_at`.
- **Backfill:** grant status and scopes from existing data.
- **Validate:** every grant has a status.
- **No contract step.**
- **Files:** the photo move is an idempotent command run at release, not a migration.
- **Rehearsals:** R1, R1 rollback/re-apply, R1b (mixed states, including grants and a full deletion → purge of a populated tenant on MySQL), R2 (100k, including a purge of the 100k tenant on a throwaway copy, proving foreign-key order on real data).

## 22. Test strategy

- **Feature tests per workflow:** support, ownership, deletion/purge, compliance export, audit immutability, platform panel access, operations visibility, branding, invitation-token logging, employee photos.
- **Security:** direct HTTP, Livewire and service calls by every unauthorised actor.
- **Architecture:** platform UI is only under `app/Filament/Platform`; tenant code never references platform services; audit is never updated or deleted.
- **MySQL races (brief §967):**
  1. transfer vs transfer;
  2. grant approval vs revocation;
  3. support use vs expiry;
  4. deletion request vs cancellation;
  5. deletion cancellation vs purge;
  6. duplicate purge workers;
  7. purge retry;
  8. platform suspension vs tenant support approval;
  9. audit ordering of approve vs cancel.
- **Lock mutants:** each race must fail without its lock.
- **Mutation testing:** authorisation, tenant boundaries, expiry, ownership, deletion state, purge, audit, lifecycle.
- **Performance:** on the 100k copy.

## 23. Explicit out-of-scope items

- SSO, SAML, SCIM, passkeys, external identity providers.
- API platform, API keys, service accounts, webhook platform, marketplace, integration vault.
- Payment provider and SDK, production pricing, GST or tax engine, accounting, ledger, invoice compliance redesign, billing emails or dunning.
- Analytics or warehouse, read replicas, scaling, dedicated databases, data residency.
- BYO AI, advanced search, website or CMS, unrelated UX redesign.
- Sign-in-as / impersonation of tenant users.
- Physical move of legacy Tenant #1 file paths (S1-07, needs a production-copy rehearsal).
- Automatic deletion of retained records (retention periods are owner decisions).
