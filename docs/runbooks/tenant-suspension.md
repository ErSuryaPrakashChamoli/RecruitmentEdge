# Runbook: Tenant Suspension, Reactivation and Cancellation

**For:** platform operators holding `platform.tenants.manage`, when a tenant must be stopped (an incident, a commercial decision, a request) or brought back.

**Status:** procedure. The pages and commands exist (SaaS-3, SaaS-5). **When** to suspend a customer is not set here: that is an owner decision (commercial terms; incident policy). Deletion is a separate, two-person workflow (`tenant-purge-failure.md`).

## What each state does

| Status | Staff sign-in, careers site, portal, API | Background work (jobs, listeners, scheduled tasks) | Data |
|---|---|---|---|
| `trial`, `active`, `past_due` | open (usable) | runs | kept |
| `suspended` | **closed** | **paused:** queued work fails at the queue guard and waits in `failed_jobs`; it is **retried automatically** when the tenant becomes usable again | kept |
| `cancelled` | closed | stopped; **never resumed** | kept; the only state a deletion can start from |
| `deletion_pending`, `deleted` | closed | stopped | deletion workflow (`tenant-purge-failure.md`) |

**Transitions** (`TenantLifecycleService::TRANSITIONS`):
- active → past due, suspended or cancelled;
- trial → active, suspended or cancelled;
- past due → active or suspended;
- suspended → active or cancelled;
- cancelled → deletion pending (by the deletion workflow only).

**An expired trial** reads as suspended (reason `trial_expired`) from the moment it ends.

## 1. Suspend

1. **Platform → Tenants → (tenant) → Suspend**, with a reason ("Incident <id>: …" or the commercial reference).
   - Requires `platform.tenants.manage`.
   - **Audit:** `tenant_suspended` in the tenant's stream.
   - **Event:** `tenant.suspended` (platform). Status reason `commercial`.
2. **Command-line equivalent:** `php artisan tenants:lifecycle <slug> suspend --reason="…"`. It is audited `tenant_suspended`, but it names no operator and raises no platform event, so prefer the panel.
3. **Billing also suspends:** an unpaid, cancelled or expired subscription suspends the tenant with source `billing`. Its status reason shows on the tenant detail (for example `billing_unpaid`). Coordinate with Finance before reactivating such a tenant.
4. **Takes effect at once:**
   - **API:** the next request gets `tenant_unavailable`, even with a valid credential.
   - **Inbound webhooks:** refused.
   - **Staff:** cannot enter the tenant.
   - **Members:** keep their identities and other tenants.

## 2. Reactivate

1. **Platform → Tenants → (tenant) → Activate**, with a reason. **Audit:** `tenant_activated`.
2. **Paused work resumes:** from suspended (or an expired trial, or ended access), the tenant's failed jobs are queued again after the change commits. Check **Failed background jobs** on the tenant detail. A count that stays above zero is work that failed for another reason: `docs/runbooks/queue-operations.md` §3.
3. **Check:**
   - a staff member of the tenant can sign in;
   - if the API is entitled, a request succeeds.

## 3. Cancel

1. **Platform → Tenants → (tenant) → Cancel**, with a reason. **Audit:** `tenant_cancelled`.
2. **Effect:**
   - background work stops and is **not** resumed later;
   - data stays;
   - cancellation is the precondition for a deletion request.
3. **There is no way back from cancelled** to a usable state. Its only transition is to deletion pending, and withdrawing a deletion returns the tenant to cancelled, not active. A tenant that may come back should stay **suspended**. Cancel deliberately.
4. **Retention** after cancellation is an owner decision (D-S3-O7). Nothing is purged until a deletion is requested and approved by two operators (`tenant-purge-failure.md`).

## 4. Verify and record

- **Tenant detail:** status, status reason, failed background jobs.
- **Platform → Events:** `tenant.*`.
- **Records:** the tenant's audit stream (**Platform → Tenants → (tenant) → Audit**).
- `php artisan ops:verify-integrity` — no `platform.*` failure; `identity.usable_tenant_without_active_owner` if a reactivated tenant has no owner.
- **Record:** the reason, who, when; the customer communication (an owner decision).

**Related:** `tenant-purge-failure.md`, `security-incident.md`, `docs/saas-3-provisioning-entitlements.md`, `docs/saas-5-platform-control.md`.
