# SaaS-3 — Decision Register

**For:** the project owner, Security, Operations and Engineering.

**Status:**
- Every decision below is implemented on `feature/saas-3-provisioning-entitlements`, branched from `869d89a` (SaaS-2).
- Each was taken inside the SaaS-3 brief and can be revisited; the "Revisit when" column says when.
- Where a decision affects customer data or money, the commercial policy is **not** invented: the code is safe either way, and the choice is listed as an open owner decision (§2).

## 1. Decisions (implemented)

| ID | Decision | Why | Alternatives rejected | Revisit when |
|---|---|---|---|---|
| D-S3-01 | **The entitlement registry is code** (`App\Enums\Entitlement`): four features, two limits. Nothing outside it can be granted; code names keys through the enum only (architecture test). | One authority; a typo cannot create an ungated capability. | Free-form keys in the database (silent typos, keys nobody checks). | A new gated capability: add a case and a value in every plan version. |
| D-S3-02 | **Precedence: tenant usable? → override in force → pinned plan version → denied.** | Brief: plan → override → effective; missing fails closed; an unusable tenant gets nothing. | Treating a missing key as unlimited, or as "not yet configured, allow". | — |
| D-S3-03 | **"Unlimited" is an explicit flag** (`is_unlimited`). A limit with neither a number nor the flag grants nothing. | Brief: no magic values (-1, 0, null, 999…). | Sentinel numbers. | — |
| D-S3-04 | **The plan catalog is code (`PlanCatalog`), published by an idempotent `plans:sync`.** A published version is never edited: the sync refuses a definition that differs from what was published (it also detects drift made in the database), and the models refuse updates and deletes. Retire instead. | Versioned, reviewable plans; a tenant's entitlements never change because someone edited a definition. | Plans edited in a UI or by migrations (no review, silent changes for every tenant). | SaaS-5 platform console (it publishes new versions through the same service). |
| D-S3-05 | **Tenants are pinned to a plan version**; one current assignment per tenant (unique `(tenant_id, is_current)`, with `is_current` null for history); every assignment needs a reason and is audited old → new. | Brief: explicit assignment, history, one current. | A plan code on the tenant row (no history, no version pinning). | — |
| D-S3-06 | **Existing tenants are assigned the internal `legacy` plan** (every feature, no limits) by the migration. | No behaviour change on upgrade: everything that worked keeps working. Internal plans are not offered to new tenants. | Assigning a commercial plan in a migration (a money decision, and could cut a customer off). | Owner decision D-S3-O5. |
| D-S3-07 | **The tenant row is the commercial lock.** Plan assignment, overrides, lifecycle transitions, limit consumption and invitation acceptance all take `SELECT … FOR UPDATE` on it. Invitation acceptance now takes it exclusively (it was shared in SaaS-2). | One ordering point: a consumption sees the old commercial state or the new one, never a mix. A shared lock later upgraded by the seat check would deadlock two acceptances. | A separate lock table; advisory locks (not transactional with the data). | Very high write concurrency per tenant (measure first). |
| D-S3-08 | **Usage is counted from the authoritative records** each time, with a locking read under the tenant lock. No counters. | Nothing can drift, so there is nothing to reconcile. Both limits are cheap indexed counts. `tenants:usage` is the read-only check. | Counter columns (drift, reconciliation jobs). | A metered entitlement (AI tokens, storage): SaaS-4 adds a ledger. |
| D-S3-09 | **Limits are enforced at the operation (`consume()`), plus non-atomic backstops on the records** (requisition created / restored as active, membership becoming Active, export record). | Atomic where the application acts; a path that forgot `consume()` is still refused. | UI-only checks (bypassable), or backstops alone (not atomic). | — |
| D-S3-10 | **What counts:** active requisitions = every requisition not Closed or Cancelled (drafts and on-hold included); seats = Active memberships (the owner included; suspended and revoked do not count). | Matches the existing meaning of "closed" in the product; conservative. | Counting only Open requisitions (a draft could be opened past the limit). | Owner decisions D-S3-O2 / O4. |
| D-S3-11 | **A downgrade never deletes or deactivates anything.** Over-limit tenants keep every record; only adding beyond the limit is refused; closing or suspending frees room. Feature-off: work already in progress winds down visibly (pending automation runs cancelled with a reason; queued AI work skipped; external job posts can still be unpublished or paused). | Brief: no automatic deletion or silent deactivation. | Auto-closing the newest records; failing queued work (retry storms, confusing errors). | — |
| D-S3-12 | **Authorisation and entitlement are separate gates; both must pass.** | Brief: neither replaces the other. | Granting permissions from the plan. | — |
| D-S3-13 | **The trial is evaluated on every request** from `trial_ends_at` (`Tenant::effectiveStatus()`); the hourly sweep only records the expiry, re-checking each tenant under its lock. An ended trial is Suspended (`trial_expired`): sign-in, careers site, portal and background work close; no data is touched. `extendTrial` re-opens it; `activate` converts it. | Brief: deterministic trial, not only by the scheduler. | Expiry by the scheduler alone (the trial runs on while the scheduler is down). | Owner decision D-S3-O3 (grace period / read-only). |
| D-S3-14 | **Lifecycle transition table** (`TenantLifecycleService::TRANSITIONS`); the service is the only writer of the status (architecture test). Cancelled, deletion pending and deleted are states only. | Brief: explicit, audited lifecycle; nothing deleted. | Free status updates. | Data-retention phase (purge). |
| D-S3-15 | **Paused work resumes on reactivation (closes S1-05).** Jobs refused at the queue guard (`TenantUnavailable`) for a Suspended tenant or an ended trial are retried after the reactivation commits, once, audited. A cancelled or deleted tenant's work is never resumed. | SaaS-1 left this as an operator step (D-S1-O4). | Holding jobs in the queue (blocks other tenants' work); dropping them (data loss). | — |
| D-S3-16 | **Provisioning is one platform service: reserve the slug, then idempotent steps in one transaction under the tenant lock; usable only at the last step.** The request's fingerprint (name, owner, plan) makes a retry "the same request"; a different request for a taken slug, or any request for a closed tenant's slug, is refused. | Brief: deterministic, idempotent, transactional, never partly live. | A sequence of independent steps (partial tenants); reusing closed slugs (another customer's URL and history). | SaaS-5 console (same service). |
| D-S3-17 | **The initial owner joins through an invitation** (SaaS-2: invitations are the only way in), as CHRO with `is_owner` (one owner per tenant). An existing identity is reused. | No password ever set by platform staff; no duplicate identity. | Creating the owner's login directly. | Ownership transfer (SaaS-5). |
| D-S3-18 | **Commercial mutations are platform-only** (`PlatformOperatorGate`: a platform administrator, or the console); no tenant route, page, Livewire component or job reaches them (architecture test). Tenant administrators see their own plan, usage and trial read-only. | Brief: platform-only commercial mutations; never expose assignment to tenant users. | A tenant-side "upgrade" button (that is SaaS-4, with payment). | SaaS-4 self-service upgrade. |
| D-S3-19 | **Commercial events are audited in the tenant's own stream** (the tenant can see its plan and status history) **plus a platform log line**; catalog publishing in the platform stream. | Brief: auditing; the tenant is the subject. | Platform-only audit (the tenant cannot explain its own history). | SaaS-5 platform audit console. |
| D-S3-20 | **Cache key `t:{tenant}:entitlements:v{entitlement_version}`, 600 s, memoised per request; the version is bumped in the change's own transaction and read before the data.** | Brief: tenant-aware, versioned keys; no stale decision after a change (MySQL-proven with a shared cache store). | Deleting keys on change (races with a concurrent reader re-filling the old value). | — |
| D-S3-21 | **Job policy:** a tenant that is not usable → the queue guard fails the job (resumable, D-S3-15). A feature that is off → the job is **skipped** (`SkipWithoutEntitlement`), logged. Job-board publishing re-checks at run time. The lifecycle sweep is a platform task. | No retry storms for a plan decision; nothing runs that the plan excludes. | Failing feature-off jobs (they would be retried for nothing). | — |
| D-S3-22 | **No prices, no payment state machine.** `past_due` is a manual platform transition only. *Superseded by SaaS-4 (D-S4-07, D-S4-16): billing drives it through the bridge.* | Brief: not a billing project. | — | SaaS-4. |
| D-S3-23 | **Fixtures pin plans explicitly.** `TenantFactory` pins `legacy` by default (existing tests keep their behaviour); `onPlan()`, `withoutPlan()`, `trial()`; `CommercialWorld` builds tenants A–E. | Tests stay deterministic; commercial tests state their plan. | Implicit defaults hidden in the service. | — |
| D-S3-24 | **Without the AI assistant, AI is refused where it is asked for, not only where it runs:** intelligence-page AI requests answer Unavailable with the plan message; the knowledge base (documents, articles) is an AI module, hidden and refused; AI conversation, usage and action logs stay readable as history. | A request whose job is then skipped would sit in Processing until the stale sweep; knowledge added while AI is off would never be indexed. | Skipping silently in the job only. | — |
| D-S3-25 | **In-flight work completes; new work is decided at its start.** An export, job or request that started before a commercial change finishes; anything it adds to a limit is re-decided under the tenant lock. The full per-situation policy is in `saas-3-provisioning-entitlements.md` §7. | Brief §24: deterministic and documented. Interrupting half-written exports or requests would leave partial results. | Cancelling running work on every change. | — |

## 2. Open (owner action)

| ID | Question | Owner | Recommendation |
|---|---|---|---|
| D-S3-O1 | **The commercial catalog**: which plans, and what each includes (today `starter` / `growth` / `enterprise` are development defaults: 10 / 50 / unlimited requisitions, 5 / 25 / unlimited seats, AI and automation from Growth). | Product + Commercial | Decide before any customer is provisioned on them; publish as v1 of the real plans (or v2 of these). |
| D-S3-O2 | Do draft and on-hold requisitions count toward the active limit? (today: yes, everything not Closed or Cancelled) | Product | Keep: otherwise a draft can be opened past the limit. |
| D-S3-O3 | What happens when a trial ends? (today: access closes; data kept) Grace period? Read-only access? Default trial length? (today: given at provisioning, 1–90 days) | Product + Commercial | Keep closing at the end, with a 14-day trial and extensions by the platform; reconsider read-only in SaaS-4. |
| D-S3-O4 | Does the owner take a seat? Will service accounts or platform support ever count? (today: owner counts; neither of the others exists) | Product | Keep. |
| D-S3-O5 | When, and to which plan, is Tenant #1 (and any other existing tenant) moved off `legacy`? | Commercial | Not before D-S3-O1; communicate first; a downgrade keeps all data (D-S3-11). |
| D-S3-O6 | Who may run the `plans:*` and `tenants:*` commands in production? | Ops + Security | The platform administrators named for D-S2-O1. |
| D-S3-O7 | Retention after cancellation: how long until deletion, and what is purged? (today: states only, nothing purged) | Legal + Security | Decide before any tenant is cancelled. |

## 3. Carried forward (not reopened)

| Finding | From | Destination | Status after SaaS-3 |
|---|---|---|---|
| S1-10 permission cache churn | SaaS-1 | SaaS-7 | Unchanged. SaaS-3 adds no permission-cache writes (entitlements have their own versioned cache). |
| Invitation token in the web-server access log (first request) | SaaS-2 | SaaS-5 | Unchanged. Owner invitations use the same mechanism. |
| Password reset timing | SaaS-2 | SaaS-7 | Unchanged. |
| Platform panel | SaaS-2 | SaaS-5 | Unchanged; commercial operations are console commands until then. |
| SSO / SAML / SCIM / passkeys / service accounts | SaaS-2 | Later identity phase | Unchanged; not started. |
| S1-05 suspended tenant's queued work | SaaS-1 | SaaS-3 | **Closed** (D-S3-15). |

## 4. Supersedes

| Earlier | Now |
|---|---|
| S1-05 / D-S1-O4 (suspended tenant's queued work needs an operator retry) | Closed by D-S3-15. |
| SaaS-2 invitation acceptance read the tenant with a shared lock (D-S2-11) | Exclusive lock (D-S3-07); behaviour otherwise unchanged. |
| SaaS-1 `TenantStatus` was set directly (factories, seeders, tests) | Application code: only `TenantLifecycleService` (D-S3-14). Fixtures still set it directly. |
