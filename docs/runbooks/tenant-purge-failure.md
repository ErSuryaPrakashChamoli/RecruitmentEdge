# Runbook: Tenant Purge Failure

**For:** platform operators holding `platform.deletion.manage` when a tenant purge fails, stalls or is exhausted. This is the irreversible end of a tenant deletion.

**Status:** procedure. Every step uses pages and commands that exist (SaaS-5). Nothing alerts on a platform event outside the platform panel yet (production readiness: NO-GO, monitoring blocker).

## How a purge works (what not to fight)

**1. Deletion workflow** — `tenants:deletion {request|approve|cancel|purge|status} <slug> --operator=<email> [--reason=]`, or **Platform → Deletions**:

| Request status | Meaning |
|---|---|
| `requested` | The tenant must already be cancelled. Another operator approves |
| `approved` | **Two-person rule** (`PLATFORM_DELETION_SECOND_OPERATOR`, default on). The tenant becomes deletion-pending; the purge waits for the grace period (`PLATFORM_DELETION_GRACE_DAYS`, default 30) |
| `purging` | A worker holds a **15-minute lease** (`lease_owner`, `lease_until`), renewed after every table |
| `purged` | Done: the tenant is Deleted |
| `failed` | The run stopped on an error (`last_error`, `failures` + 1) |
| `cancelled` | Possible only from `requested` or `approved` |

**2. Purge job:**
- `PurgeTenantJob` runs on the **`integrations`** queue (the `queue-background` worker).
- It has `tries` 1 and a 290 s timeout. The queue never retries it: the platform sweep does.

**3. What a run does, in order, recording `progress` after each step:**
1. drop the tenant's queued and failed jobs;
2. remove its files (under `tenants/{id}/` on every disk, plus legacy paths its rows name);
3. remove its cache entries;
4. delete its rows table by table, children first, in chunks of 1 000.

A rerun resumes where the last stopped; deleting what is already gone is a no-op.

**A failed chunk:** rows are deleted in chunks, each statement keyed by the tenant id. `progress.tables` marks a table done only after its last chunk. A run that fails part-way through a table has already committed the earlier chunks of it; the next run starts that table again and finds those rows gone. Nothing is rolled back, and nothing is deleted twice.

**4. Retained:** the tenant row and these tables stay (`platform.deletion.retain_tables`):
- `audit_logs`, `billing_customers`, `billing_events`, `billing_invoices`, `billing_payments`, `billing_subscriptions`;
- `support_access_grants`, `tenant_entitlement_overrides`, `tenant_plan_assignments`.

Users (global identities) also stay.

**5. `platform:sweep`** (every 15 minutes) re-queues:
- every approved request past its grace period;
- every `purging` request whose lease expired (a resumed run, **not** a failure);
- every `failed` request with fewer than **5** failures (`TenantPurgeService::MAX_FAILURES`).

At 5 failures it raises **`purge.exhausted`** (critical) and stops retrying.

## 1. Detect

- **Platform → Deletions:** status, progress, runs (`attempts`), failures, error (`last_error`, the exception class and message, cut to 255 characters).
- **Platform → Events:** `purge.started`, `purge.resumed`, `purge.failed`, `purge.exhausted`, `purge.completed` (all critical).
- **Command line:** `php artisan tenants:deletion status <slug>`.
- **Log:** `platform.purge_failed` (`request_id`, `exception`).
- **Tenant audit stream:** `tenant_purge_started`, `tenant_purge_resumed`, `tenant_purge_failed`, `tenant_purge_requested`, `tenant_purge_completed`; earlier `tenant_deletion_requested`, `tenant_deletion_approved`, `tenant_deletion_cancelled`.
- **`ops:verify-integrity`** looks at:
  - `platform.purge_past_lease` — a purge whose lease expired;
  - `platform.deleted_tenant_without_purge` — a Deleted tenant without a purged request (a failure);
  - `platform.deletion_pending_without_open_request`.

## 2. Diagnose

| Symptom | Likely cause | Check |
|---|---|---|
| `purging`, lease expired, not moving | The worker died or the job hit its timeout | Is `queue-background` running and healthy? `php artisan queue:drain-status`; the `integrations` queue's age on `/health/queue`. The sweep resumes it within 15 minutes once a worker runs |
| `failed`, error `Purge plan refused: …` | A retained or platform table depends on a purged one in a way the purge would break (a schema change since SaaS-5) | **A code defect.** Do not retry: it fails the same way. Escalate to engineering; the purge waits |
| `failed`, a database error (lock wait, deadlock, connection) | Contention or an outage | Retries by the sweep usually carry it. Check the database's health |
| `failed`, a storage error | A disk unreachable or a permission problem | The storage mount on the worker; `php artisan storage:audit` |
| `purge.exhausted` | 5 failures | Read `last_error`. Fix the cause **before** retrying |
| Queued but nothing happens | The tenant is no longer deletion-pending | The run refuses and does nothing. `tenants:deletion status <slug>` shows the tenant's status |

## 3. Act

1. **Fix the cause first.** Retrying an unchanged failure only uses up the budget.
2. **Operator approval:**
   - The deletion itself was approved by two different operators before the grace period began. A retry does not need a new approval, and **no procedure here skips that approval**.
   - A retry is still a platform action. It is done by an operator holding `platform.deletion.manage`, as a named identity (`--operator=`), and is audited.
   - Record who decided to retry.
   - If the failure suggests the purge should **not** continue, do not retry. Escalate to the owner, because a started purge cannot be cancelled (step 6).
3. **Retry:**
   - **In the panel:** **Platform → Deletions → Purge now**;
   - **On the command line:**
     ```
     php artisan tenants:deletion purge <slug> --operator=<your email>
     ```

   On a `failed` request this **resets the failure count** to 0 (audited `tenant_purge_requested` with `failures_reset`). It is refused before the grace period ends.
4. **Two retries at once are safe.** A second worker finds the live lease and does nothing.
5. **Never, by hand:**
   - delete the tenant's rows;
   - set the request to `purged`;
   - edit `progress`, `lease_*` or `failures`.

   A partial purge marked done leaves tenant data behind and fails the integrity check.
6. **Cancelling is no longer possible** once the request is `purging` or `failed`: the purge has started deleting. The only way back is a database restore (`docs/runbooks/backup-restore.md`). That is an **owner decision**, and it would also bring back every other tenant's data to the backup's time.

## 4. Verify

```
php artisan tenants:deletion status <slug>    # "(deleted): no open deletion."
php artisan tenancy:verify                    # 0 violations (exits non-zero otherwise)
php artisan ops:verify-integrity              # Integrity OK
```

**In the panel:**
- **Deletions:** `purged`, progress complete;
- **Events:** `purge.completed`.

The tenant's audit stream ends with `tenant_purge_completed` (tables count, files removed).

## 5. Record

- the request id, the tenant slug, each failure's error class;
- the cause and its fix;
- who retried and when;
- the final verification.

Purge evidence for the tenant (its deletion request, events and audit rows) is kept as platform and retained records.

**Related:** `tenant-suspension.md`, `docs/saas-5-platform-control.md`, `docs/runbooks/queue-operations.md`, `security-incident.md`, `backup-restore-verification.md`.
