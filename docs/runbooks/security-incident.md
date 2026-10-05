# Runbook: Security Incident

**For:** the security lead and the platform on-call, for any suspected compromise. Examples:
- a staff account taken over;
- a platform operator account misused;
- data seen by someone who should not see it;
- suspicious activity in the audit trail;
- a reported vulnerability being exploited.

For the specific cases, see `leaked-api-credential.md`, `compromised-integration.md` and `app-key-and-secret-rotation.md`.

**Status:** procedure. Every containment step uses commands and pages that exist. **What does not exist yet** (production readiness: NO-GO):
- monitoring that detects an incident by itself;
- a log store outside the hosts;
- an external penetration test;
- network egress controls;
- an off-host copy of the audit trail.

**Notification** of tenants, individuals or authorities is an **owner / security / legal decision**. This runbook does not set notification duties or deadlines.

## 1. Open the incident

- Name an incident lead and an incident id. Use the id in every `--reason=` below, so the audit trail links back to the incident.
- **Record the start time** (UTC) and what was reported, by whom.
- **Never** paste secrets, tokens or `.env` values into the incident record.

## 2. Contain — pick what applies

| Threat | Action | Effect | Audit |
|---|---|---|---|
| A **staff identity** compromised (any tenant) | `php artisan identity:disable <email> --reason="Incident <id>: …"` | Signs in nowhere, every session ended at once, **its API credentials stop working** (a credential acts only while its owner is allowed in) | `identity_disabled`; log `platform.identity_disabled` |
| A member of **one tenant** | Tenant admin: **Users → Revoke** (or Suspend) and **Sign out everywhere** | Access to that tenant ends; other tenants unaffected | `access_suspended` / `access_revoked`, `roles_removed`, `sessions_revoked`, `signed_out_everywhere` |
| A **platform operator** | `php artisan platform:operator revoke <email> <role> --reason=…`, plus `identity:disable` if the identity itself is compromised | No platform capability; no tenant access is ever implied by a platform role | `platform_role_revoked`; log `platform.role_revoked` |
| Support access in use | **Platform → Support grants → End** (with a reason), or the tenant's **Support Access → Revoke** | The grant ends at once; the operator's support workspace closes | `support_access_*` |
| An API credential | Revoke it (`leaked-api-credential.md`) | The next request gets 401 | `api_credential_revoked` |
| A tenant's whole API | `php artisan tenants:entitlement <slug> api.access off --reason="Incident <id>: …"` | Every API request of the tenant gets 403 | `entitlement_override_created` (or `_changed`) |
| An integration (webhook) | Disable it (`compromised-integration.md`) | Outbound queued deliveries fail unsent; inbound refused | `integration_connection_disabled` |
| A tenant's whole webhook traffic | `php artisan tenants:entitlement <slug> integrations.webhooks off --reason="Incident <id>: …"` | No webhook of the tenant works; **Disable** stays available | `entitlement_override_created` (or `_changed`) |
| A whole tenant must be stopped | `php artisan tenants:lifecycle <slug> suspend --reason="Incident <id>: …"` | Members cannot use the tenant; data is kept. **A blunt tool:** an owner decision when the tenant is a customer | `tenant_suspended` |
| A candidate portal account | **Candidate Portal Accounts → Revoke** | The account is deactivated; the candidate can no longer sign in | `portal_deactivated` |
| The application key or another secret leaked | `app-key-and-secret-rotation.md` | | |

**Re-enable** only with the lead's decision, recorded:
- `identity:disable <email> --enable --reason=…`;
- `platform:operator grant …`;
- `tenants:lifecycle <slug> activate --reason=…`.

## 3. Preserve evidence — before anything is cleaned up

1. **Audit trail.** `audit_logs` is append-only in the application (`.ai/rules/concerns.md`).
   - **Database:** `php artisan audit:protect status` shows whether the database triggers are also installed. Installing them is an **owner decision** (D-S7: audit triggers vs application-only immutability). Do not install or remove them during an incident without that decision.
   - **Copy:** there is no off-host copy (backup and log-store blockers). For one tenant, a compliance operator (`platform.compliance.manage`) can request a **compliance export** in **Platform → Tenant detail**. It includes every tenant table, among them the tenant's audit rows, excluding credential columns. It is registered, checksummed, downloaded only in the panel (audited) and expires.
   - **Never** export through ad-hoc SQL to a laptop.
2. **Logs:** copy the application log files of the period (`storage/logs/*`, daily files; `LOG_DAILY_DAYS` sets the retention, 0 keeps them) to the incident store before the retention removes them. Every log line carries `request_id`, `tenant_id` and `user_id` from the log context; secrets are redacted.
3. **Database snapshot:** if the database itself may have been altered, take a backup now (`docs/runbooks/backup-restore.md` §2) and keep it apart from the rotation. It is a procedure until the backup infrastructure exists.
4. **Note the system state:**
   - `php artisan tenancy:verify`;
   - `php artisan ops:verify-integrity`;
   - `docker compose ps`;
   - the image tag running.

## 4. Investigate

1. **Sign-in activity:**

   | Event | Recorded as |
   |---|---|
   | Staff sign-in failed | log `identity.login_failed` (`user_id` when known, guard); audit `login_failed` |
   | Staff locked out (10 failures, 900 s) | log `identity.login_locked_out` (`ip`); audit `login_locked_out` |
   | Candidate portal sign-in failed | audit `portal_login_failed` |
   | API authentication failed | log `api.authentication_failed`; audit `api_authentication_failed` |
   | Sign-in attempts for unknown accounts | the platform audit stream (**Platform → Platform audit**) |

2. **What the actor did:** **Administration → Audit log** (per tenant). Filter by:
   - user;
   - actor kind (`user`, `candidate`, `automation`, `ai`, `scheduler`, `console`, `queue`, `system`, `platform`, `api`, `integration`);
   - action, auditable type;
   - period;
   - request id (follows one request).

   Platform operators' acts inside a tenant are attributed to the operator and the support grant.
3. **Platform side:**
   - **Platform → Events** — lifecycle, deletion, support and purge events;
   - **Platform → Platform audit** — sign-ins for unknown accounts, platform commands.
4. **Suspected cross-tenant activity** — someone saw or changed another tenant's data:
   1. **Verify:**
      - `php artisan tenancy:verify` (0 violations expected): the verifier checks every tenant reference;
      - `php artisan ops:verify-integrity`.

      **A violation is a code defect:** escalate to engineering at once. Keep the output.
   2. **Refused attempts:** search the error log for the exception classes `App\Services\Tenancy\CrossTenantViolation` (a write across tenants, always refused; the message names tables and ids only) and `MissingTenantContext` (a tenant query with no tenant).
      - Use the line's `request_id`, `tenant_id` and `user_id` to find the request.
      - A refused attempt means the guard worked, but the code path that tried it is a defect to fix.
   3. **Legitimate ways one identity sees two tenants** — rule these out before concluding:
      - **Membership:** the identity is a member of both (**Users** page of each tenant; entering a tenant is audited `tenant_selected` / `tenant_switched`).
      - **Platform support:** a support grant was active (`support_access_used` in the tenant's audit, with the operator and the grant).
      - **Compliance export:** an operator downloaded an export (audited `compliance_export_downloaded`).
   4. **Neither applies and data crossed:**
      - treat it as a confirmed incident;
      - suspend the affected tenants if the owner decides (`tenant-suspension.md`);
      - preserve evidence (§3);
      - do not attempt a data repair by hand.
5. **Scope:** list the affected tenants, identities, records and the period. This is the basis for the notification decision.

## 5. Eradicate and recover

- **Remove the way in:**
  - rotate what leaked (`app-key-and-secret-rotation.md`);
  - fix the defect, then deploy through the normal release (`docs/production-release-checklist.md`).
- **Reset MFA** for affected staff where the factor may be compromised (**Users → Reset MFA**, tenant administrator).
- **Verify:**
  - `php artisan tenancy:verify`;
  - `php artisan ops:verify-integrity`;
  - `php artisan ops:preflight`.

## 6. Post-incident review

Within the period the owner sets, the lead runs a blameless review and records:
- the timeline (detection, containment, eradication, recovery);
- the cause;
- why it was not detected earlier;
- what worked;
- what did not.

Each follow-up gets an owner. Typical follow-ups:
- a test that reproduces the cause;
- a monitoring signal (none alert yet);
- a runbook correction;
- a penetration-test scope item;
- an owner decision that was missing.

## 7. Close

- [ ] Containment actions recorded with times (each is in the audit trail with the incident id).
- [ ] Evidence preserved (audit export, logs, snapshot), with its location.
- [ ] Affected tenants, identities, records and period listed.
- [ ] Cause found; fix deployed or scheduled.
- [ ] Accounts re-enabled only by decision.
- [ ] **Notification decision recorded: owner / security / legal.**
- [ ] Follow-ups:
  - monitoring rule;
  - test;
  - penetration-test scope item.

**Related:** `leaked-api-credential.md`, `compromised-integration.md`, `app-key-and-secret-rotation.md`, `tenant-suspension.md`, `docs/runbooks/incident-recovery.md`, `docs/saas-2-identity-access.md`, `docs/saas-5-platform-control.md`.
