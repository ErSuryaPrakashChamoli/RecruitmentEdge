# SaaS-5 — Decision Register

**For:** the project owner, Legal, Security, Operations and Engineering.

**Status:**
- Every decision in §1 is implemented on `feature/saas-5-platform-control`, branched from `9a3d04b` (SaaS-4).
- No legal or commercial policy was invented. Where a choice belongs to the owner — support duration, deletion grace, retention, operator roles, restoration — the code is configurable, the default is the safer choice, and the question is listed in §2.

## 1. Decisions (implemented)

| ID | Decision | Why | Alternatives rejected | Revisit when |
|---|---|---|---|---|
| D-S5-01 | **Platform capabilities are derived from the three SaaS-2 platform roles** (administrator, support, compliance), smallest safe mapping (`PlatformCapability::rolesGranting`). No new operator data. | Explicit capabilities without a second RBAC; roles are already granted and audited with `platform:operator`. | Spatie permissions for operators (would mix the tenant and platform planes); one "super admin". | D-S5-O7 |
| D-S5-02 | **A separate Filament panel at `/platform`, without tenancy, code under `app/Filament/Platform/**`.** Admission is by an active platform role (`User::canAccessPanel` per panel). | The tenant panel never discovers platform pages; a tenant role can never reach them. | Platform pages inside the tenant panel; a separate application. | — |
| D-S5-03 | **MFA is required for every platform operator**, whatever the role, under the existing switch (`identity.mfa.enforce`). | Platform access reaches every tenant's metadata and support views. | Per-role MFA (the tenant panel rule). | — |
| D-S5-04 | **No impersonation.** Support sees read-only views in the platform panel through a tenant's grant: diagnostics, people or audit, one scope at a time. | Sign-in-as mixes identities, bypasses the tenant's own authorisation and makes actions ambiguous in the audit. | Sign-in-as with a banner. | A support need the three scopes cannot meet (new scope, same model). |
| D-S5-05 | **Support access is requested by the operator and approved by the tenant** (its `users.access.manage` holders), within the scopes and minutes asked. Tenants can still grant directly (SaaS-2). | The tenant decides who sees its data; the operator cannot widen it. | Platform-approved access; standing access. | D-S5-O2 |
| D-S5-06 | **A grant is checked on every request and Livewire update; each use (page load, scope switch) is counted and audited under the grant's row lock.** Expiry is decided by time; the sweep only records it. | Fails closed for pages left open, revoked grants, expired grants and lost roles. | Checking only at page load. | — |
| D-S5-07 | **Ownership moves the existing `is_owner` flag, only to an active member who already holds CHRO, by a platform administrator,** under the tenant lock and then the membership locks, with stale-view protection. The previous owner keeps their roles. | No new ownership model (brief). Ownership never grants a role implicitly. | Tenant-initiated transfer (D-S5-O3); granting CHRO automatically. | D-S5-O3 |
| D-S5-08 | **Deletion is a workflow on a Cancelled tenant: request → approval by a second operator → deletion pending + grace → purge → deleted;** cancellable until the purge starts. SaaS-3 gains `deletion_pending → cancelled`; `tenants:lifecycle` no longer marks deletion-pending or deleted. | Deletion is irreversible: two people, a waiting period, and a single path. | Direct lifecycle transitions (the SaaS-3 CLI path). | D-S5-O4, O9 |
| D-S5-09 | **The purge plan is derived from the schema and validated before anything is deleted.** It covers every tenant-owned table, the tenant's provider callbacks and its identity-plane rows, minus the retained tables. It deletes children before parents and refuses if anything outside the purge would break. | A new tenant table is purged by construction. Foreign-key order is correct on MySQL. A misconfiguration deletes nothing. | A hand-maintained table list; `ON DELETE CASCADE` from `tenants`. | — |
| D-S5-10 | **The purge is resumable and single-worker:** a claim and lease under the tenant lock, progress after each step, chunked deletes keyed by the tenant id, failure recorded and retried up to 5 failures (runs resumed after a lease are not failures), then a critical event; an operator's "Purge now" grants a new budget (audited). | Large tenants and worker crashes must neither duplicate nor half-finish silently. | One transaction (too long for a large tenant); fire-and-forget. | — |
| D-S5-11 | **Retained after a purge by default:** the tenant row, `audit_logs`, the billing tables, `support_access_grants`, plan and override history, the platform records, and global identities. | Financial, audit and access evidence must not disappear with the tenant before a retention policy says so. | Deleting everything. | D-S5-O5 |
| D-S5-12 | **The audit trail is append-only** (model guard plus architecture test). Platform actions are recorded with actor kind `platform` and the operator. | Attributable and tamper-evident without a redesign. Database-level immutability needs a production DBA decision. | Triggers or a separate audit store now. | D-S5-O10 |
| D-S5-13 | **Compliance exports are registered, generated on the queue into one private zip with a SHA-256, downloadable only by compliance operators (audited), and deleted when they expire.** Credentials are never exported. File contents are listed, not copied. | Brief: what, which tenant, who, when, why, status, expiry. Bounded exposure of a full-tenant artifact. | Synchronous export; signed public links; copying files into the archive. | D-S5-O6 |
| D-S5-14 | **Platform events are idempotent per key. Critical events are mailed to one operations address, with no tenant on the queue.** | Operators need a feed, not log greps. The mail must never be refused by a closed tenant's queue guard. | Tenant notifications for platform events. | D-S5-O8 |
| D-S5-15 | **The invitation token moves to the query string; the path form stays for links already sent** (S2-A3). | The access log records the path only (`%U`). | A token exchanged by POST from a fragment (needs JavaScript in mail clients' browsers). | — |
| D-S5-16 | **Tenant surfaces carry the tenant's name (display name, legal name on offer letters); platform surfaces carry `platform.brand.name`** (S1-08). | Candidates deal with the organisation. | Per-tenant logos and colours (not in scope). | Tenant branding UI (later). |
| D-S5-17 | **Employee photos are private (signed, members of the tenant only); existing photos move with an idempotent command; reads fall back to the public disk until moved** (S1-06). | A leaked URL no longer exposes a photo. Deploying never loses one. | A migration moving files; breaking unmoved photos. | — |
| D-S5-18 | **Legacy Tenant #1 file paths stay in place; purge and export include them** (S1-07). | The physical move needs a production-copy rehearsal of the storage. | Moving them now. | Production-copy rehearsal (D-S5-O11). |
| D-S5-19 | **SaaS-5 services authorise the operator's capability, then call SaaS-3, which stays the only writer of lifecycle state.** Lifecycle actions pass the administrator through SaaS-3's gate. Deletion steps call SaaS-3 with no operator (its console rule), under the operator's audit attribution. | A compliance operator may approve a deletion; SaaS-3's gate admits administrators only. The operator is still on the record. | Widening SaaS-3's gate. | — |

## 2. Owner decisions (open)

| ID | Decision | Current safe default | Owner | Impact | Required before production? | Destination |
|---|---|---|---|---|---|---|
| D-S5-O1 | Support-access maximum duration | 480 minutes (`platform.support.max_minutes`); requests lapse after 72 h | Owner + Security | Longer windows widen exposure | Yes | Config |
| D-S5-O2 | Support approval requirement: always the tenant? Break-glass for incidents? | Always the tenant's access administrators; no break-glass | Owner + Legal | Without break-glass, support cannot see a tenant that is down or unresponsive | Yes | Policy, then a break-glass flow if wanted (later) |
| D-S5-O3 | Ownership transfer policy: who may request it (tenant, platform), what evidence is needed | Platform administrators only, with a reason; the new owner must be an active CHRO | Owner + Legal | Disputed ownership needs a documented process | Yes | Policy |
| D-S5-O4 | Deletion grace period | 30 days (`platform.deletion.grace_days`) | Owner + Legal | Contractual exit terms | Yes | Config |
| D-S5-O5 | Retention after deletion (audit, billing, support grants, plan history) and when they are erased | Kept indefinitely (`platform.deletion.retain_tables`); nothing erases them | Legal + Finance | Indefinite retention of personal data in audit rows may conflict with privacy law; billing records must be kept for statutory periods | Yes | Retention policy, then a retention job (later phase) |
| D-S5-O6 | Compliance export retention, and whether exports may be sent to the customer | 7 days (`platform.compliance_exports.retention_days`); downloaded by compliance operators only | Legal + Security | How the artifact leaves the platform | Yes | Config + process |
| D-S5-O7 | Platform operator roles and who holds them | administrator / support / compliance, mapped as D-S5-01; granted with `platform:operator` | Owner + Security | Separation of duties | Yes | Named operators |
| D-S5-O8 | Platform notification address and on-call routing | None set (`PLATFORM_NOTIFY_EMAIL`): events are visible on the panel only | Operations | Critical events (purge failure, provisioning failure) need someone watching | Yes | Config |
| D-S5-O9 | Purge authorization: two people always? Who may press "Purge now"? | Two different operators to approve (`require_second_operator`); any deletion operator may start a due purge; the scheduler purges when due | Owner + Legal | Irreversible deletion | Yes | Config / policy |
| D-S5-O10 | Audit immutability at the database level (privileges, triggers, external log shipping) and audit retention | Application-level guard; the database user can still modify rows | Security + DBA | A database administrator could alter the trail | Recommended | Production hardening (SaaS-7) |
| D-S5-O11 | Tenant restoration policy after a purge | None: a purge is final; restoration only from backups, outside the application | Owner + Legal | Customer expectations after an exit | Yes | Policy (+ backup retention) |
| D-S5-O12 | Production-copy rehearsal of the SaaS-5 migration and the photo move | Rehearsed on development and 100k copies only | Owner + Operations | Real data shapes and storage | Yes | Release checklist |
| D-S5-O13 | Identities left without any membership after a purge (name, email, password hash, MFA secret in `users`) | Kept; they can sign in nowhere (no accessible tenant), and `identity:disable` can close them | Legal + Security | Personal data of a departed customer's staff outlives the tenant | Yes | Retention policy (D-S5-O5), then an identity erasure step (later phase) |

## 3. Carried forward (not reopened)

| Finding | From | Destination |
|---|---|---|
| Production payment provider, prices, GST, invoice compliance, production-copy billing rehearsal (D-S4-O1…O11) | SaaS-4 | Owner; not addressed in SaaS-5 (brief) |
| S1-10 permission cache churn | SaaS-1 | SaaS-7 |
| Password reset timing | SaaS-2 | SaaS-7 |
| SSO / SAML / SCIM / passkeys / service accounts | SaaS-2 | Later identity phase |
| Catalog values, legacy tenants' plan, retention (D-S3-O1, O5, O7) | SaaS-3 | Owner (retention now also D-S5-O5) |
| S1-07 physical move of legacy paths | SaaS-1 | D-S5-O12 |
| Time-of-day test sensitivity: 10 tests fail between 00:00 and 05:30 IST (18:30–24:00 UTC) because the app clock is UTC and business dates are IST; `AutomationExecutionAuthorityTest` (daily message cap) fails between 21:00 and 24:00 UTC because it travels 3 hours across midnight. Each was confirmed to pass with the clock outside its window. The final runs are taken outside these windows. | pre-existing | Test hygiene backlog |

## 4. Supersedes / extends

| Earlier | Now |
|---|---|
| SaaS-2: support grants are created by the tenant and open nothing | Requested or granted, scoped, used through the support workspace (D-S5-05, 06) |
| SaaS-3: `deletion_pending` and `deleted` set by `tenants:lifecycle` | Only through the deletion workflow (D-S5-08) |
| SaaS-3: "nothing deletes data" | The purge deletes a tenant's data after the workflow (D-S5-08…11) |
| SaaS-1 S1-06 / S1-07 / S1-08, SaaS-2 S2-A3 | Fixed or included (D-S5-15…18) |
