# SaaS-5 — Platform Administration, Compliance & Operational Control

**For:** engineers and platform operators working on this codebase.

**Branch:** `feature/saas-5-platform-control`, from `9a3d04b` (SaaS-4).

**Related:**
- discovery: `docs/saas-5-discovery.md`
- security review: `docs/saas-5-security-review.md`
- migration plan: `docs/saas-5-migration-plan.md`
- decisions: `docs/saas-5-decision-register.md`

SaaS-5 adds a platform control plane on top of SaaS-1 (tenancy), SaaS-2 (identity), SaaS-3 (lifecycle and entitlements) and SaaS-4 (billing). It replaces none of them: lifecycle is still decided by `TenantLifecycleService`, billing by the billing domain, and identity by the identity services. SaaS-5 calls those services; it never writes their state directly.

## 1. The three planes

| Plane | Where | Who |
|---|---|---|
| Identity | `users`, `tenant_memberships`, roles (spatie teams = tenants) | everyone |
| Tenant application | the tenant panel, `/admin/{tenant}` | members of that tenant |
| Platform control | the platform panel, `/platform` (`app/Filament/Platform/**`, `app/Services/Platform/**`) | platform operators |

**The boundaries are enforced by construction.**
- `User::canAccessPanel()` admits a person to the platform panel only with an active platform role (`PlatformAuthorization::isOperator`). A tenant role never admits anyone there.
- A platform role never admits anyone to a tenant. Operators have no membership; `canAccessTenant` is membership-based.
- The tenant plane never references platform services (`IdentityPlaneArchitectureTest`). The only exception is the tenant's own side of a support grant: `app/Filament/Pages/SupportAccess.php`.
- Nothing outside the platform panel references `App\Filament\Platform` (`PlatformArchitectureTest`).
- Platform code reads across tenants only in reviewed places, which `TenancyArchitectureTest` allow-lists: `PlatformDirectory`, `SupportWorkspace` and `SupportAccessService`.

## 2. Platform authorisation

Capabilities (`App\Enums\PlatformCapability`) are derived from the three SaaS-2 platform roles. No new operator data is stored.

| Capability | administrator | support | compliance |
|---|---|---|---|
| `platform.tenants.view` | ✓ | ✓ | ✓ |
| `platform.tenants.manage`: lifecycle, ownership | ✓ | | |
| `platform.support.manage`: request and use support access | | ✓ | |
| `platform.compliance.manage`: compliance exports | | | ✓ |
| `platform.audit.view` | ✓ | | ✓ |
| `platform.operations.view`: events | ✓ | ✓ | |
| `platform.deletion.manage`: request, approve, cancel, purge | ✓ | | ✓ |

**How the checks are made:**
- `PlatformAuthorization::can()` and `::authorize()` answer from the operator's active roles. A disabled identity holds no capability.
- Every platform service authorises first, on the server. Pages and actions only mirror it: `canAccess()` and `visible()`.

**Panel guards:**
- **MFA.** Every operator must enrol an authenticator app (`EnsurePlatformMfa`) whenever `identity.mfa.enforce` is on. This does not depend on role, unlike the tenant panel.
- **Session.** `EnforceStaffAccess` is persistent. A revoked operator, a disabled identity or a pre-revocation session is signed out on its next request or Livewire update.
- **Roles.** Roles are still granted and revoked with `platform:operator` (SaaS-2), audited in the platform stream.

## 3. The platform panel

| Page | Capability | What |
|---|---|---|
| Overview (dashboard) | any operator | counts: tenants in use, open deletions and failed purges, support access in force and awaiting tenants, unacknowledged critical events, failed jobs, ready exports |
| Tenants | tenants.view | every tenant: status, plan, active members, trial end, open deletion, support grants. Fixed number of queries (subselects in `PlatformDirectory::tenants()`). |
| Tenant detail | tenants.view; actions by capability | status, owner, plan, members and invitations, trial and access end, failed jobs, usage against limits. Actions: suspend, activate, cancel, extend trial; transfer ownership; request support access; compliance export; request deletion (the slug must be typed); link to its audit. |
| Deletions | deletion.manage | requests with status, requester, approver, purge date, progress, attempts, error. Approve, cancel, purge now (after the grace period only). |
| Support access | support.manage (own grants) or tenants.manage (all) | grants and requests; open the workspace; end a grant |
| Support workspace | support.manage + an active grant | read-only views of one tenant (§4) |
| Compliance exports | compliance.manage | the export register; download |
| Platform audit | audit.view | the platform stream, and every tenant's entries of platform actions (`PlatformDirectory::PLATFORM_ACTIONS`); filters; before and after values |
| Events | operations.view | platform events; acknowledge |

The panel shows operational metadata only. A tenant's business records appear only in the support workspace, through that tenant's grant.

## 4. Support access

**There is no impersonation.** No one signs in as a tenant user. Support sees read-only views in the platform panel, one tenant and one scope at a time.

**Workflow** (`SupportAccessService`):
1. **Request.** A support operator (`platform.support.manage`, an active Support role) asks a tenant for access, giving the scopes, a duration of at most `platform.support.max_minutes` (default 480) and a reason. The tenant must be Trial, Active, PastDue or Suspended. A repeated request for the same operator and tenant returns the open one. The tenant's `users.access.manage` holders are notified in-app. The request is audited in the tenant's stream with actor kind `platform`.
2. **Decision.** On the tenant's own page (**Administration → Support Access**):
   - approve: the scopes must be a subset of those asked, and the duration at most the duration asked;
   - or deny, with a reason.

   Both happen under the tenant row lock, then the grant row. The tenant must be usable to approve. A tenant may still grant directly (the SaaS-2 path), now with scopes; Diagnostics is the default.
3. **Use.** The workspace page calls `open()` on load and on every scope switch. `open()` locks the grant, checks it, counts the use (`use_count`, `last_used_at`) and audits `support_access_used` with the scope. Every Livewire update calls `usableGrant()`, which applies the same checks without a lock. A grant is usable only when:
   - it is this operator's;
   - the operator still holds the Support role;
   - the tenant is open to support;
   - the status is Active and the time is within `starts_at`–`expires_at`;
   - the scope is covered.

   Anything else is a 403, so a page left open stops on its next request.
4. **End.**
   - **Revocation:** by the tenant (its own grants only), by the grant's operator, or by a platform administrator.
   - **Expiry:** decided by time on every check. `platform:sweep` records ended grants as Expired, and requests unanswered for `platform.support.request_ttl_hours` (default 72) as lapsed. Correctness does not depend on the sweep.

**Scopes** (`SupportScope`):
- **Diagnostics:** status, plan and usage, billing status, and failed background work (job names and counts only, never payloads).
- **People:** members, with access state, owner flag, roles, last sign-in and whether MFA is enrolled (never the secret).
- **Audit:** the tenant's audit stream: what, who, when and why, without the changed values.

## 5. Ownership

`TenantOwnershipService::transfer()` moves the existing `tenant_memberships.is_owner` flag.

**Who and to whom:**
- Only a platform administrator can transfer.
- The new owner must be an active member who already holds the CHRO role (`identity.chro_role`). Roles are never granted implicitly.
- The previous owner keeps their membership and roles.

**How:**
- Locks are taken in order: tenant row, then the memberships involved.
- A transfer decided on a stale view (the `expected owner` captured when the form opened) is refused.
- Closed tenants are refused: provisioning, cancelled, deletion pending or deleted.
- It is audited `ownership_transferred` (or `ownership_assigned` for a tenant without one), with the operator, reason and before/after, and raised as a platform event.

**Console:** `tenants:owner {slug} [email] --operator= --reason=`.

## 6. Deletion and purge

**Workflow** (`TenantDeletionService`). Every step needs `platform.deletion.manage`, takes the tenant lock and then the request lock, and is audited in the tenant's stream and raised as a platform event.

```
Cancelled tenant (SaaS-3)
  → request (reason)                       one open request per tenant (unique tenant_id + is_open)
  → approval by a different operator       platform.deletion.require_second_operator
  → tenant Deletion pending (SaaS-3)       purge_after = now + platform.deletion.grace_days (30)
      ↳ cancel until the purge starts      tenant back to Cancelled, data intact
  → purge (after purge_after only)         scheduler or "Purge now"; a platform job
  → tenant Deleted (SaaS-3)
```

**Lifecycle changes:**
- SaaS-3's transition table gains `deletion_pending → cancelled`. It is refused to anything but `withdrawDeletion`, so a pending deletion is withdrawn only through the workflow, which closes its request.
- `tenants:lifecycle` no longer offers `deletion-pending` or `deleted`. Those would skip the approval, the grace period and the purge.

**Purge** (`TenantPurgeService`, `PurgeTenantJob`, `TenantPurgePlan`):
- **Plan first.** `TenantPurgePlan` is derived from the schema.
  - **Purged:** every `TenantSchema::TENANT_TABLES` table, `communication_webhook_events`, and the tenant's identity-plane rows (memberships, roles and their assignments; role permissions cascade with their roles). The `platform.deletion.retain_tables` tables are excepted.
  - **Order:** children before parents along every foreign key. Self-references and cycles are broken by nulling the nullable columns first.
  - **Refusal:** if anything outside the purge would be broken, the plan refuses before anything is deleted. A retained, platform or other table that depends on a purged table by RESTRICT or CASCADE counts. The one exception is `role_has_permissions`, which goes with its role.
- **Claim.** Under the tenant lock, then the request lock, the purge proceeds only when the request is due (Approved past `purge_after`, Failed below 5 attempts, or Purging with an expired lease) and the tenant is Deletion pending. The claiming worker takes a lease (`platform.deletion.lease_minutes`, default 15). A second worker finds the live lease and does nothing.
- **Steps.** Progress is recorded in `progress` after each step, and the lease is renewed each time:
  1. drop the tenant's waiting and failed jobs;
  2. delete files: every legacy path its purged rows still reference outside `tenants/{id}/` (including the fallback disk), its pre-SaaS-1 Filament export directories (`filament_exports/{id}/`), then `tenants/{id}/` on the local and public disks;
  3. delete the tenant's cache entries (`t:{id}:*`, database cache store);
  4. null the cycle columns;
  5. delete rows table by table, `WHERE tenant_id = ?` in chunks (`platform.deletion.chunk`).
- **Finish.** The request becomes Purged, the tenant Deleted, and the outcome is audited.
- **Failure.** The request becomes Failed, with the error, an audit entry and a critical event. It is never shown as done. `platform:sweep` retries it, resuming where it stopped, since deleting what is already deleted is a no-op.
- **Failure budget.** Failures (`failures`) are counted separately from runs (`attempts`). A run resumed after a worker's lease expired is not a failure. After 5 failures the sweep stops retrying and raises a critical event. An operator's "Purge now" retries it, resetting the failure budget (audited).
- **Retained by default** (owner decision D-S5-O5):
  - the tenant row;
  - `audit_logs`;
  - the billing tables;
  - `support_access_grants`;
  - plan and override history;
  - the platform records;
  - global identities (`users`), including identities that belonged only to this tenant.

**Console:** `tenants:deletion {request|approve|cancel|purge|status} {slug} --operator= --reason=`.

## 7. Audit

- **Append-only.** `AuditLog` throws on update and on delete. `PlatformArchitectureTest` forbids query-builder updates and deletes of `audit_logs` in application code.
- **Attributable.** `AuditLog::asPlatformOperator($operator, …)` records actor kind `platform` with the operator as `user_id`. Platform actions on a tenant are written to that tenant's stream; platform-wide ones (`platform_role_granted`, …) to the platform stream (`tenant_id` null).
- **Viewing.** The platform audit page combines both streams for platform actions.
- **SaaS-3/4 services.** They keep their console rule (no operator) and are called by SaaS-5 under the operator's attribution.

## 8. Compliance exports

`ComplianceExportService` and the `GenerateComplianceExport` platform job.

1. **Request.** A compliance operator gives a reason. A register entry is created in `compliance_exports`, audited and raised as an event.
2. **Generation, on the queue.**
   - **Contents.** Every tenant-owned and tenant-attributed table, retained tables included (an export is a copy), and the tenant's memberships, roles and its members' identities.
   - **Format.** JSON lines per table, streamed table by table.
   - **Exclusions.** Columns matching `EXCLUDED_COLUMNS` are excluded and listed in the manifest: passwords, tokens, secrets, hashes, MFA material, signatures, embeddings, API keys and credentials.
   - **Files.** A manifest of the tenant's files gives paths and sizes, not contents.
   - **Output.** One zip on the private disk (`platform/compliance-exports/{id}/`), with its SHA-256 and an expiry of `platform.compliance_exports.retention_days` (default 7).
3. **Download.** Only through the platform panel, by a compliance operator. Each download is audited and counted. There is no public or signed URL.
4. **Expiry.** `platform:sweep` deletes the artifact and marks the entry Expired. The register entry is never deleted.

## 9. Operational events and notifications

`PlatformEvents::record(type, severity, title, tenant, context, dedupeKey)`:
- writes `platform_events`, once per dedupe key;
- logs `platform.event`;
- for critical events, queues `PlatformEventMail` to `platform.notify_email`, if set. The mail carries the event id only, is encrypted, and is queued with no tenant after the commit.

**Sources:**
- provisioning failure;
- lifecycle actions from the panel;
- support requests, approvals, expiries and lapses;
- ownership transfers;
- deletion requests, approvals and cancellations;
- purge start, resume, completion, failure and exhaustion;
- compliance export request, readiness and failure.

Operators acknowledge events on the Events page.

## 10. Queues, scheduler, cache

| Item | Rule |
|---|---|
| `PurgeTenantJob`, `GenerateComplianceExport` | Platform jobs: dispatched inside `runWithoutTenant` with `Bus::dispatch`, so they never carry whichever tenant is current (architecture test). Queues `integrations` and `exports` (the documents worker, 300 s). Timeout 290 s, 1 try. A longer purge resumes from its lease via the sweep. |
| `PlatformEventMail` | `notifications` queue, no tenant, after commit, 3 tries |
| `platform:sweep` | Every 15 minutes, `withoutOverlapping(14)->onOneServer()`, listed in `TenantTasks::PLATFORM`. Each step is isolated. Idempotent: an ended grant is recorded once, a due purge collapses into one claim, and an expired export is deleted once. |
| `files:privatize-employee-photos` | Operator tenant task (`TenantTasks::OPERATOR`), run once per tenant after deploying: `tenants:run files:privatize-employee-photos --all` |
| Cache | No new cache entries. The purge removes the tenant's `t:{id}:` entries from the database store; other stores expire them by TTL (§security review). |

## 11. Carry-forward fixes

| ID | Fix |
|---|---|
| S2-A3 | The invitation link is `/invitations/open?token=…`. Apache logs `%U` (path only), so the token never reaches the access log. `/invitations/{token}` still opens links sent before. |
| S1-08 | A tenant's own surfaces carry its name (`Branding::tenantName()`: `branding.display_name`, else the name): careers index, feed and consent; candidate portal layout and mails; candidate messages; `{{company.name}}`; incentive statements. Offer letters carry the legal name (`Branding::tenantLegalName()`). The platform name (`platform.brand.name`) stays on platform surfaces: the panels, staff invitations, platform mail and AI Copilot. |
| S1-06 | Employee photos are private. Uploads go to the local disk, and `Employee::photoUrl()` returns a 5-minute signed link through `PrivateFileController`, which serves it to members of the tenant only. Photos not yet moved are still read from the public disk (`Employee::photoDisk`), and the forms use the disk the current photo is on, so nothing is lost before `files:privatize-employee-photos` runs. |
| S1-07 | Legacy (pre-SaaS-1) file paths are included in purge and export (`StorageAudit::REFERENCES`, with `fallback_disk`). The physical move stays deferred. |

## 12. Configuration (`config/platform.php`)

| Key | Default | Env |
|---|---|---|
| `brand.name` | `APP_NAME` | `PLATFORM_BRAND_NAME` |
| `notify_email` | none | `PLATFORM_NOTIFY_EMAIL` |
| `support.max_minutes` | 480 | `PLATFORM_SUPPORT_MAX_MINUTES` |
| `support.request_ttl_hours` | 72 | `PLATFORM_SUPPORT_REQUEST_TTL_HOURS` |
| `deletion.grace_days` | 30 | `PLATFORM_DELETION_GRACE_DAYS` |
| `deletion.require_second_operator` | true | `PLATFORM_DELETION_SECOND_OPERATOR` |
| `deletion.lease_minutes` | 15 | |
| `deletion.chunk` | 1000 | |
| `deletion.retain_tables` | audit, billing, support grants, plan and override history | |
| `compliance_exports.retention_days` | 7 | `PLATFORM_COMPLIANCE_EXPORT_RETENTION_DAYS` |
| `compliance_exports.disk` | `local` | |
| `compliance_exports.chunk` | 1000 | |

## 13. Code map

| Area | Classes |
|---|---|
| Authorisation | `PlatformCapability`, `PlatformAuthorization`, `User::canAccessPanel`, `EnsurePlatformMfa`, `PlatformPanelProvider` |
| Read models | `PlatformDirectory` (tenants, summary, audit, grants), `SupportWorkspace` |
| Support | `SupportAccessService`, `SupportScope`, `SupportGrantStatus`, `Filament/Pages/SupportAccess`, `Filament/Platform/Pages/SupportGrants`, `SupportWorkspace` |
| Lifecycle | `TenantAdministrationService` (calls SaaS-3), `TenantLifecycleService::withdrawDeletion` |
| Ownership | `TenantOwnershipService`, `tenants:owner` |
| Deletion | `TenantDeletionService`, `TenantPurgeService`, `TenantPurgePlan`, `PurgeTenantJob`, `TenantDeletionRequest`, `tenants:deletion` |
| Compliance | `ComplianceExportService`, `GenerateComplianceExport`, `ComplianceExport` |
| Events | `PlatformEvents`, `PlatformEvent`, `PlatformEventMail` |
| Sweep | `platform:sweep` (`PlatformSweep`) |
| Branding and files | `Branding`, `Employee::photoDisk/photoUrl`, `PrivatizeEmployeePhotos` |
| Tests | `tests/Feature/Platform/*`, `tests/Unit/Platform/PlatformArchitectureTest.php`, `tests/Concurrency/PlatformRaceTest.php` |

## 14. Performance (measured on the 100k copy, MySQL 8.4.11)

**Setup.** The R2 copy after the SaaS-5 migration:
- the 100k tenant has 100,063 candidates, 511 members and 17,834 audit entries;
- 999 more tenants were added for the tenant list.

**Method.** Each read is the query the panel makes, run three times. The table gives the cold and warm times, the queries per call, and MySQL's plan for the main query.

| Read | Cold / warm ms | Queries | Plan (main query) |
|---|---|---|---|
| Tenant list, page 1 (1 tenant) | 4.2 / 1.5 | 2 | `tenants` PRIMARY; per-row subselects by index (grants, open deletion, current plan) |
| Tenant list, 1,000 tenants: page 1 / last page / status filter / search | 6.2 / 2.9; 4.0 / 4.0; 4.0 / 3.7; 4.5 / 3.5 | 2 each | 10 rows read from `tenants`; subselects by index |
| Tenant detail summary (largest tenant: usage of every limit, owner, invitations, failed jobs) | 11.9 / 4.9 | 6 | — |
| Member options (ownership form, 500 shown) | 12.6 / 12.1 | 1 | — |
| Support grants, page 1 | 2.4 / 2.1 | 5 (eager loads) | PRIMARY |
| Grant check on every Livewire update (`usableGrant`) | 2.1 / 2.0 | 4 | — |
| Support workspace: diagnostics / people page 1 / audit page 1 | 13.7 / 10.8; 6.5 / 5.4; 10.1 / 6.7 | 12; 6; 7 | people: on this copy MySQL reads `users` (512 rows) first, then memberships by `(tenant_id, user_id)`; with many tenants that index can drive the join; audit: `audit_logs_tenant_idx` |
| Platform audit, page 1 | 2.8 / 2.6 | 3 | `index_merge` (tenant_id ∪ action), 37 rows examined |
| Platform audit, one tenant | 2.2 / 2.2 | 3 | range on `audit_logs_action_created_idx`, 35 rows |
| Deletion requests / compliance exports, page 1 | 0.7 / 0.3; 1.0 / 0.3 | 1 each | — |
| Dashboard counts | 2.3 / 1.6 | 5 | — |
| **Purge of the 100k tenant** (651,139 rows, 42 tables) | 67 s in total | chunks of 1,000 | `DELETE … WHERE tenant_id = ? LIMIT 1000` by the tenant index |

**Findings:**
- **No N+1.** The query counts are fixed: the tenant list costs 2 queries whether it shows 1 tenant or 1,000.
- **No full tenant-table scan.** The only full scan in a plan is `tenant_memberships` in the member-count subselect: the copy's 511 memberships all belong to one tenant, so the index has cardinality 1. With a fixed tenant id the same query uses the `(tenant_id, user_id)` index (`ref`, 1 row).
- **No provider calls.** Billing status is read from `BillingStatusService` (cached, never the provider).
- **No bulk loading.** Usage counts come from the entitlement registry. The people and audit views are paginated, and failed jobs are capped at 500.
- **Purge timing.** The 100k purge fits in one job run (290 s limit; 148 s while a full test suite ran on the same machine). A larger tenant continues from its lease through `platform:sweep`; resumed runs do not count as failures.
