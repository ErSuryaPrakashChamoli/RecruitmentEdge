# SaaS-5 — Security Review: Platform Administration, Compliance & Operational Control

**For:** Security, Legal, the project owner and Engineering.

**Scope:**
- the platform/tenant boundary;
- platform authorisation;
- support access;
- ownership transfer;
- tenant deletion and purge;
- audit integrity;
- compliance exports;
- file, queue and cache safety;
- concurrency;
- the SaaS-1/SaaS-2 carry-forwards fixed here: S1-06 photos, S1-08 branding, S2-A3 invitation token.

Reviewed on `feature/saas-5-platform-control` (on top of `9a3d04b`), verified on SQLite and MySQL 8.4.11.

**Result:**
- **No Critical or High finding is open.**
- Defects found while building SaaS-5, and by an independent review before commit, were fixed (§3).
- Remaining items are Medium, Low or Info, each with a disposition and destination (§6).

## 1. Threat model

| Actor | Wants | Boundary that stops it |
|---|---|---|
| Tenant administrator (CHRO, `users.access.manage`) | platform controls; another tenant's data; another tenant's support grants | No platform role, so `canAccessPanel(platform)` is false and the session is signed out. Services check the grant's tenant. Tenant scopes apply on every model. |
| Tenant member | escalate to tenant administrator or platform | Unchanged SaaS-2 authority guards; the platform plane is unreachable |
| Platform support operator | standing or broad access to a tenant; another operator's grant; impersonation | Tenant approval within what was asked; per-request checks; scoped read-only views; no sign-in-as; every use audited |
| Platform compliance operator | another tenant's data inside an export; credentials | Every export query keyed by the tenant id; credential columns excluded; downloads audited |
| Platform administrator | an unilateral irreversible deletion; changes left unattributed | Two-person approval and grace period; append-only audit with the operator on every action |
| Stolen operator session | persistent access | MFA required; revoked roles, disabled identity or a pre-revocation session are signed out on the next request |
| Stale page, stale job, stale cache | acting after access ended or the tenant was deleted | Grant re-checked on every Livewire update; purged tenant's jobs dropped, and the queue guard refuses a deleted tenant; cache entries removed |
| Concurrent operators or workers | double transfer, double purge, approval racing revocation | Tenant row lock first, then the record's own lock; claims with leases (§5) |

## 2. Attacks and the tests that try them

| Attempt | Result | Test |
|---|---|---|
| Tenant administrator opens `/platform` | signed out, sent to the platform login | `PlatformPanelAccessTest` |
| Platform operator opens `/admin/{tenant}`, or acts in a tenant | redirected; not a member; no permission | `PlatformPanelAccessTest`, `PlatformBoundaryTest` |
| Operator without the capability opens a page (support → deletions, exports, audit; compliance → events, support) | 403 | `PlatformPanelAccessTest` |
| Operator without the capability calls a service (transfer, deletion, export, download, support request) | `DomainException` naming the capability | `OwnershipTransferTest`, `TenantDeletionWorkflowTest`, `ComplianceExportTest`, `SupportAccessWorkflowTest` |
| Disabled identity or revoked role keeps using an open session | no capability; signed out on the next request | `PlatformPanelAccessTest` |
| Operator without MFA | redirected to MFA set-up before any page | `PlatformPanelAccessTest` |
| Support access without the tenant's approval | the request opens nothing (workspace 403) | `SupportAccessWorkflowTest` |
| Approving more scopes or minutes than asked; approving without `users.access.manage` | refused | `SupportAccessWorkflowTest` |
| Another tenant's administrator sees, approves, denies or revokes a tenant's grant | invisible; refused (`another organisation`) | `SupportAccessWorkflowTest`, `PlatformBoundaryTest` |
| Wrong operator opens someone else's grant | 403 | `SupportAccessWorkflowTest` |
| Scope not granted (URL or Livewire tampering) | 403. The scope and grant id are `#[Locked]`; a scope switch is offered only for covered scopes. | `SupportAccessWorkflowTest` |
| Expired, revoked, role lost or tenant closed while the page is open | the next Livewire update is a 403 | `SupportAccessWorkflowTest` (4 cases) |
| Support asks a closed tenant | refused | `SupportAccessWorkflowTest` |
| Ownership to a non-member, a suspended member, a member of another tenant, or a member without CHRO | refused, owner unchanged | `OwnershipTransferTest` |
| Ownership decided on a stale view; concurrent transfers | refused (`owner changed meanwhile`); serialised | `OwnershipTransferTest`, `PlatformRaceTest` |
| Ownership of a closed tenant | refused | `OwnershipTransferTest` |
| Deleting an open tenant; one-person deletion; purging before the grace period | refused | `TenantDeletionWorkflowTest` |
| Deleting through the old lifecycle command (`tenants:lifecycle … deleted`) | refused (workflow only) | `TenantDeletionWorkflowTest` |
| Cross-tenant purge: other tenants' rows, other tenants' files, global identities | untouched (row counts per table per tenant; another tenant's legacy file kept) | `TenantDeletionWorkflowTest`; R1b/R2 rehearsals (every other table checksummed) |
| Duplicate purge; second worker; resumed purge; failed purge | runs once; a live lease keeps others out; failure recorded, never shown as done; resumes | `TenantDeletionWorkflowTest`, `PlatformRaceTest` |
| Misconfigured retention that would break or cascade a retained table | the plan refuses before anything is deleted | `TenantDeletionWorkflowTest` |
| Audit tampering (update, delete) | refused by the model; architecture test forbids query-builder updates and deletes | `PlatformOperationsTest`, `PlatformArchitectureTest` |
| Platform audit page reading a tenant's business records | not listed (platform actions and the platform stream only) | `PlatformOperationsTest` |
| Export containing another tenant's rows or credentials | none: every row is the tenant's; no column matching the credential pattern | `ComplianceExportTest` |
| Downloading an export without the capability, after expiry, or through a URL | refused; no URL exists | `ComplianceExportTest` |
| Employee photo opened without signing in, by another user's signed link, or from another tenant | 403 / 404 | `CarryForwardFixesTest` |
| Invitation token in the access log | the path is `/invitations/open`; the token is in the query string, which the `%U` log format does not record | `CarryForwardFixesTest`, `InvitationLifecycleTest` |
| Stale queued job of a purged tenant | dropped by the purge; refused by the queue guard (`its status is deleted`) | `TenantDeletionWorkflowTest` |
| Stale cache of a purged tenant | its `t:{id}:` entries and locks are removed; other tenants' entries kept | `TenantDeletionWorkflowTest` |
| Platform job carrying the current tenant | impossible: platform jobs are queued with no tenant | `PlatformArchitectureTest` |
| Platform UI reachable from the tenant plane; tenant code calling platform services | none | `PlatformArchitectureTest`, `IdentityPlaneArchitectureTest`, `CommercialArchitectureTest` |

## 3. Defects found and fixed during SaaS-5

| ID | Severity | Defect | Fix |
|---|---|---|---|
| S5-F1 | High (fixed before commit) | After the support-access rewrite, a tenant administrator could revoke another tenant's grant: the grant was locked across tenants. | `revoke()` checks that the grant belongs to the current tenant (`assertTenantAdministrator`). Covered by `PlatformBoundaryTest`, `SupportAccessWorkflowTest` and mutant M41. |
| S5-F2 | Medium | Platform jobs and platform mails dispatched inside a tenant would carry that tenant. A closed tenant's queue guard would then refuse them: a purge or a critical mail would never run. | Queued with no tenant (`runWithoutTenant` + `Bus::dispatch`); enforced by an architecture test |
| S5-F3 | Medium | The purge plan was built after the queue and file steps, so a refused plan had already dropped jobs and files | The plan is validated before anything is deleted (test: nothing deleted on refusal) |
| S5-F4 | Medium | The platform audit read included console-attributed entries, which are tenant business records, and could not use an index | Platform actions and the platform stream only, both indexed |
| S5-F5 | Medium | Moving photo uploads to the private disk would silently null unmoved photos on the next save: Filament drops a stored file it cannot find | The form uses the disk the photo is on (`Employee::photoDisk`) |
| S5-F6 | Medium | `tenants:lifecycle` could mark a tenant deleted with no approval, grace period or purge | Refused; deletion goes through the workflow only |
| S5-F7 | Low | The support workspace built the people query on every request, even for the diagnostics view, so a diagnostics-only grant got errors | No grant-scoped query for a scope that is not shown |
| S5-F8 | Low | The people view selected the MFA secret to show "enrolled" | A boolean expression; the secret is never read |
| S5-F9 | Low | Rolling back the migration left `starts_at` / `expires_at` nullable | `down()` dates never-started grants as ended and revoked, then restores NOT NULL (rehearsed) |

An independent read-only review of the whole SaaS-5 change set, before commit, found no Critical or High defect. It found the following, all fixed, each with a test and a mutant that undoes the fix (M43–M48, all killed):

| ID | Severity | Defect | Fix |
|---|---|---|---|
| S5-F10 | Medium | The panel's ownership form held the expected owner in a hidden *field*, which Filament does not submit, so the stale-view guard never reached the service | A `Hidden` form component, which is submitted; Livewire test of a transfer decided on a stale view |
| S5-F11 | Medium | A purge that failed 5 times could never be recovered. "Purge now" reported success and did nothing; every lease-expiry resume counted as an attempt. | Failures are counted separately from runs (`failures`). "Purge now" on a failed purge resets the failure budget, audited with the previous count. The exhaustion event is raised per run. |
| S5-F12 | Medium | `deletion_pending → cancelled` was open to a plain `cancel`, leaving the deletion request approved and open, and requeued every sweep | Only the deletion workflow (`withdrawDeletion`) may take that step. The sweep queues purges only for deletion-pending tenants. |
| S5-F13 | Low | An interrupted photo move could leave a partial private copy that was served and never repaired | The copy is written beside the target and moved into place; a copy whose size differs is replaced |
| S5-F14 | Low | The expiry sweeps paged by offset while rows left the filter, so they skipped rows beyond 1,000 | Keyset paging (`lazyById`); test with 1,005 exports |
| S5-F15 | Low | Pre-SaaS-1 Filament export files (`filament_exports/{id}/`) were left by a purge and missing from export manifests | Removed with the tenant's export rows and listed in the manifest |

## 4. Layers

- **Panel:** a separate panel without tenancy; admission by platform role; MFA; persistent session and role checks.
- **Pages:** `canAccess()` by capability (architecture test). Record ids are `#[Locked]`. Actions are visible by capability and status.
- **Services:** authorise the capability, then lock (tenant row, then their record), then check state, then audit and raise an event. SaaS-3 services stay the only writers of lifecycle.
- **Data:**
  - Platform records (`tenant_deletion_requests`, `compliance_exports`, `platform_events`) are classified PLATFORM. They survive a purge and are never tenant-scoped.
  - Support grants stay tenant-owned.
  - Every cross-tenant read is in one of three allow-listed classes.
- **Audit:** append-only; actor kind `platform` with the operator; tenant actions in the tenant's stream.

## 5. Concurrency (MySQL 8.4.11, `tests/Concurrency/PlatformRaceTest.php`)

Each race holds the first operation's transaction open while a forked contender attempts the second. Each asserts four things:
- the contender blocked;
- it waited on the contested lock (`performance_schema.data_locks`), not on something earlier;
- it decided on the committed outcome;
- the final state.

| Race | Contested lock | Without the lock |
|---|---|---|
| transfer vs transfer | tenant row (`TenantOwnershipService`) | the stale transfer is applied |
| audit ordering (two transfers) | tenant row | the second records the stale previous owner |
| approval vs revocation | tenant row (`approve`) | a revoked grant becomes active |
| use vs expiry | grant row (`open`) | a use is recorded on an expired grant |
| deletion approval vs cancellation | tenant row (`TenantDeletionService`) | a cancelled deletion becomes pending |
| purge vs cancellation | tenant row (purge claim) | a cancelled deletion is purged |
| duplicate purge workers | tenant row (purge claim) | two purges |
| purge retry (two resumers) | tenant row (purge claim) | two resumptions |
| lifecycle (cancel) vs ownership transfer | tenant row (`TenantOwnershipService`) | ownership changes on a cancelled tenant |
| lifecycle (suspend) vs tenant support approval | tenant row (`approve`) | support approved for a suspended tenant |

**Lock mutants:** each of the five locks was removed in turn. Every race that depends on it failed, and each mutant was killed: R-M1 (3 races), R-M2 (2), R-M3, R-M4, R-M5 (3).

## 6. Open and accepted items (no Critical, no High)

| ID | Severity | Item | Disposition | Owner | Destination |
|---|---|---|---|---|---|
| S5-R1 | Medium | Audit immutability is enforced by the application. A database user with write rights can still alter `audit_logs`. | Accepted for now | Security + DBA | D-S5-O10; production hardening (SaaS-7): privileges or shipping to an append-only store |
| S5-R2 | Medium | Retained records (audit, billing, support grants, plan history) and the identities of a purged tenant's members are kept indefinitely | Accepted until the retention policy | Legal | D-S5-O5, D-S5-O13; retention job (later phase) |
| S5-R3 | Low | Purged tenant cache entries are removed only from the database cache store (the default). Other stores keep them until their TTL. | Accepted; the tenant can no longer be entered | Engineering | If the cache store changes: add that store's removal |
| S5-R4 | Low | Waiting jobs are dropped from the database queue only. Jobs on another driver stay until a worker refuses them (tenant deleted). | Accepted; refused by the queue guard | Engineering | Same as R3 |
| S5-R5 | Low | Compliance archives are stored unencrypted on the private disk for up to 7 days | Accepted; disk encryption is infrastructure | Security | D-S5-O6 |
| S5-R6 | Low | The platform and tenant panels share the staff session. An identity that is both an operator and a member has one session for both. | Accepted; MFA required, roles re-checked on every request | Security | Revisit with SSO (later identity phase) |
| S5-R7 | Low | No break-glass support access: support cannot see an unresponsive tenant | Accepted (safer) | Owner | D-S5-O2 |
| S5-R8 | Low | Any deletion operator may start a due purge, including the requester | Accepted; approval already needed a second operator | Owner + Legal | D-S5-O9 |
| S5-R9 | Info | Support People scope shows member names and emails | By design, with the tenant's approval | — | — |
| S5-R10 | Info | Legacy Tenant #1 file paths are not moved | Purge and export include them | Operations | D-S5-O12 |

## 7. Mutation checks

**Logic mutants.** 48 mutants, each applied alone. The SaaS-5 test file covering the area was run against each, and every mutant was killed.

| Area | Mutants (all killed) |
|---|---|
| Authorisation | M01 capability ignores roles; M02 platform panel open to any identity; M03 MFA not required; M04 support may delete |
| Support access | M05 approval beyond the minutes asked; M06 beyond the scopes asked; M07 someone else's grant; M08 scope not covered; M09 inactive grant usable; M10 use not audited; M11 deciding another tenant's request; M34 workspace not re-checked on update; M37 request to a closed tenant; M41 revoking another tenant's grant¹ |
| Ownership | M12 owner without CHRO; M13 stale owner accepted; M14 closed tenant |
| Deletion and purge | M15 one-person deletion; M16 deleting an open tenant; M17 purge before grace (panel); M18 purge claim before grace; M19 live lease ignored; M20 plan never refuses; M21 retained tables purged; M22 legacy files kept; M23 queued work kept; M35 lifecycle command skips the workflow; M36 cancellation leaves the tenant pending; M40 failed purge marked done; M42 foreign-key order ignored |
| Exports | M24 credentials exported; M25 other tenants exported; M26 download unauthorised; M27 expired export kept; M39 download not audited |
| Audit and events | M28 audit editable; M29 critical event not mailed; M30 every event mailed |
| Carry-forwards | M31 token back in the path; M32 platform name on tenant surfaces; M33 photos public; M38 a photo link opens for another user |
| Review fixes | M43 stale owner not sent by the panel; M44 exhausted purge not reset by "Purge now"; M45 plain cancel of a pending deletion; M46 partial private photo kept; M47 expiry paged by offset; M48 legacy export files kept |

**Lock mutants.** 5 mutants, each run against the MySQL races (§5); all killed. R-M2 is killed by both the revocation race and the suspension race.

### 7a. Surviving mutants

None in the final run.

¹ M41 first survived the targeted run, because its killing test lives in the SaaS-2 `PlatformBoundaryTest`. A cross-tenant revoke assertion was added to `SupportAccessWorkflowTest`, after which M41 was killed.

An earlier full-suite run of the same mutants was discarded and repeated, for two reasons:
- its runner could not run the selected paths in parallel, which made every result look "killed";
- a concurrent test run shared the faked storage directory and interfered with it.

## 8. Verification

The final run started at 00:00 UTC, outside the pre-existing time-of-day windows (decision register §3).

| | SQLite | MySQL 8.4.11 |
|---|---|---|
| Full suite | 2,595 / 2,595 | 2,595 / 2,595 |
| SaaS-1 (tenancy) | 105 / 105 | 105 / 105 |
| SaaS-2 (identity) | 101 / 101 | 101 / 101 |
| SaaS-3 (commercial) | 86 / 86 | 86 / 86 |
| SaaS-4 (billing) | 90 / 90 | 90 / 90 |
| SaaS-5 (platform) | 58 / 58 | 58 / 58 |
| Architecture | 26 / 26 | 26 / 26 |
| Security | 299 / 299 | 299 / 299 |
| Concurrency (all races) | — | 51 / 51 (SaaS-5: 10) |

**Rehearsals:** `tenancy:verify --all` reported 0 violations on every copy: R1 400 checks, R1b 403, R2 422, and 410 after the 100k purge.

## 9. What this review does not cover

- SSO, SAML, SCIM, passkeys; the API platform; billing provider security (SaaS-4 open decisions).
- Infrastructure: disk encryption, database privileges, backups, network.
- A production-copy rehearsal (D-S5-O12).
