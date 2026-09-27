# Phase 8.4: Access & Identity Lifecycle

## 1. Purpose

Phase 8.3 made the path from candidate to employee trustworthy. Before Phase 8.4, nothing governed the identity after that point:

- a person who left kept their login, roles, sessions, team visibility, AI approvals and automation rules;
- delegated administrators could grant themselves authority;
- nothing about access was audited.

Phase 8.4 makes employment, identity, access, roles, the hierarchy, responsibility and asynchronous authority behave as one controlled system:

> PERSON → EMPLOYMENT → IDENTITY → ACCESS → ROLE → PERMISSION → HIERARCHY → RESPONSIBILITY → ASYNC AUTHORITY → AUDIT

When anything upstream changes, everything downstream is re-validated. **Current state is authoritative; historical state is preserved.**

## 2. State model

Employment and access are **separate states**.

| Employment (`employees.status`) | Access (`users.access_status`) |
|---|---|
| Active | Active: may sign in and act |
| Inactive (a reversible pause) | Suspended: login blocked, sessions ended, roles kept dormant |
| Separated (the separation took effect) | Revoked: login blocked, sessions ended, **roles removed** (recorded in `revoked_roles` and the audit) |

**Mapping, applied by the services:**
- Active → Active.
- Inactive → Suspended.
- Effective separation → Revoked.

**Restoring access:**
- From Suspended, the dormant roles apply again.
- From Revoked, the login gets **only the base role** (`identity.base_role`, `employee`). Nothing privileged is ever restored automatically.

**Separation timing.** A separation is recorded with its last working day (`separation_date`).
- Recording it changes nothing.
- It takes effect **the day after**: employment becomes Separated and access is Revoked.
- A future-dated separation never ends access early. An administrator can choose "End system access now" for someone leaving before their last day.

## 3. Architecture: one authoritative service per identity fact

All of these live in `app/Services/Identity/`.

| Service | Owns |
|---|---|
| `StaffAccessService` | `users.access_status`; `permits()` (the access gate); suspend, restore, revoke |
| `EmploymentGate` | Employment facts the gate honours before the state catches up (a separation that took effect but hasn't been applied) |
| `EmployeeLifecycleService` | `employees.status`; the separation lifecycle: record, correct, apply, cancel, rehire, deactivate, reactivate |
| `IdentityProvisioningService` | Creating logins; `users.employee_id` (the employee link); provisioning on conversion; rehire reactivation; invitations |
| `RoleAssignmentService` | Granting and removing roles; role create, update and delete; the base-role grant |
| `AuthorityGuard` | Shared delegated-administration rules (permission, never yourself, hierarchy scope) and last-CHRO protection |
| `HierarchyIntegrityService` | `employees.reports_to_id`; scoped, locked, cycle-safe moves; delete and restore |
| `SessionRevocationService` | Session epoch, session rows and remember-me tokens |
| `CredentialService` | Administrator password reset, email-change requests, sign out everywhere |
| `MfaService` | Who must use MFA; the audited MFA lifecycle |
| `OwnershipHandoffService` | What happens to a departed person's current work |
| `IdentityAuditor` | The read-only checks behind `identity:audit` |

**Guarded attributes.** These can only be written inside `LifecycleGuard::allow()`, which only the services above may open; the Phase 8.3 architecture test lists them.
- `User.access_status`, `User.employee_id`
- `Employee.status`, `Employee.reports_to_id`
- The separation date and lifecycle columns

Filament forms, table actions, tools, jobs and automation call the services. **The service layer is the security boundary.**

**The access gate (fail closed).**
- `User::canAccessPanel()` and `User::hasPermissionTo()` both ask `StaffAccessService::permits()`: access is Active and no effective separation is waiting.
- `Gate::before` denies every ability to a non-permitted login.
- A suspended or revoked person therefore holds **no permission anywhere**: panel, Copilot tools, automation ownership or jobs.
- Decisions are memoised per request and invalidated by any identity change and by a change of date.

## 4. Sessions and tokens

- **The session epoch.** Every session carries the user's `session_epoch`, stamped at sign-in.
  - `EnforceStaffAccess` is a persistent panel middleware, prioritised before `Authenticate`, so Livewire requests are covered too.
  - On every request it signs out a login that is no longer permitted, or a session from an older epoch. A separation that has taken effect is applied on the spot.
- **What suspension, revocation, a password reset, an MFA reset or "sign out everywhere" do:**
  - bump the epoch, which invalidates every session whatever the session driver;
  - delete the user's stored sessions (database driver);
  - cycle the remember-me token.
- **API tokens.** The application has **no API tokens**: no Sanctum, Passport or `personal_access_tokens`. The epoch and the remember token cover every bearer credential that exists.
  - An architecture test fails if a token package or table appears, so token revocation is wired in before any API ships.
  - Phase 8.4 does not add a token architecture.

## 5. Roles and authority

- **Identity by key.** Roles are identified by an immutable `key`, not their editable name (`App\Models\Role`, configured as Spatie's role model).
  - The six seeded roles are keyed.
  - CHRO is **protected** (`identity.protected_roles`): it cannot be deleted, its key never changes, and a rename keeps the protection.
  - A role recreated under the old name has no key and grants no CHRO authority.
- **Rules, all in `RoleAssignmentService`:**
  - nobody changes their own roles or employee link, or edits or deletes a role they hold;
  - you can only grant a role, or give a role permissions, that you hold yourself;
  - a protected role is granted or removed only by a holder of it, and its permissions cannot be edited in the UI;
  - assignments stay inside the administrator's hierarchy and never target a revoked login.
- **Last CHRO.** The last *effective* CHRO (Active and permitted) cannot be suspended, revoked, separated, deactivated or have the role removed. Refused attempts are audited (`last_chro_protected`).
- **Forms.** The Filament Users and Roles forms are plain option lists handed to the services; an architecture test forbids relationship syncs. Users are never deleted from the UI: access is revoked instead.

## 6. Hierarchy

- **Who can move people:** `HierarchyIntegrityService::reassign` needs `hierarchy.reassign` (org chart) or `users.manage` (employee form).
  - The employee and the new manager must both be inside the actor's hierarchy (unless view-all). Nobody can pull an outsider under themselves.
  - Nobody moves themselves, or a protected-role holder whose role they don't hold.
  - The new manager must be a current employee.
  - The cycle check runs under row locks, so two concurrent moves cannot create a loop. `EmployeeObserver` stays as the model-level backstop.
- **Deletion:**
  - Refused while the employee has current direct reports or an active login.
  - Always soft: the closure rows stay, so the person's history remains visible to the managers above.
  - Permanent deletion is never allowed.
  - Restoring is hierarchy-scoped and never restores access.
- **Deleted recruiters no longer break pages.** `HierarchyService::canView()` is null-safe, and the historical employee relations policies use (application recruiter, interviewer, owners, snapshots, targets) resolve soft-deleted employees, so attribution stays.

## 7. Provisioning, rehire and separation cancellation

**Conversion** (`EmployeeConversionService`, needs `employees.convert` in scope; `users.manage` is not a substitute) creates, in one transaction:
1. the employee, placed under a manager: chosen in the convert action, defaulting to the requisition's reporting / hiring / owning manager, and required to be a current employee in scope;
2. a login with the base role only, Active access and a random password; a single-use set-password invitation is emailed after commit;
3. retirement of the candidate portal login (candidate history kept);
4. audit rows, plus `UserProvisioned` and `EmployeeConvertedFromCandidate` after commit.

A missing or already-used email is refused before anything is written.

**Rehire.** A candidate whose earlier employee record is Separated (even soft-deleted) is converted back into **the same employee record**.
- The record gets a new placement and manager.
- The existing login is restored through `StaffAccessService` with the base role only.
- The separation and joining history stays. The old unique-constraint failure is gone.

**Separation cancellation** needs `employees.separation.cancel` in scope and a reason; it is audited and dispatches `SeparationCancelled`.
- **Before it takes effect:** nothing else changes.
- **After it took effect:**
  - employment becomes Active again;
  - access **stays Revoked** until an administrator restores it deliberately (base role only);
  - paused automation stays paused;
  - outcomes recorded from that separation are voided through `OutcomeService` (a new version; the original is kept).
- A separation followed by a rehire cannot be cancelled.

## 8. AI authority

- **Page lock.** `AiCopilot::$conversationId` is `#[Locked]`, and approve / reject find a tool call only inside a conversation the signed-in user owns.
- **What a proposal records** (`ai_tool_calls`):
  - `requested_by` (immutable);
  - `expires_at` (default 30 minutes, `ai.actions.pending_ttl_minutes`);
  - an authority fingerprint: roles, employee, view-all, and the requester's own management chain.
- **Checks immediately before execution** (`ActionExecutor::approve`):
  - the approver is the requester;
  - the window is open;
  - the fingerprint is unchanged;
  - plus the Phase 8.1 checks: permission, tool permission, feature flag, rate limit.
- **Otherwise** the call is retired (`Expired` / `Invalidated`), the conversation is told, and it never runs.
- **Scope.** Team growth doesn't invalidate a proposal. A target the requester can no longer see is refused by the tool's own re-scoping at execution.
- **Other paths:**
  - Suspension, revocation and role changes invalidate the person's pending actions (listener).
  - `ai:expire-pending-actions` runs every five minutes.
  - The Phase 8.3 atomic claim is unchanged; approval executes synchronously, so no approved action waits in a queue.
  - The Phase 8.1 privacy boundary (projector, sanitiser, egress, tool contract) is untouched.

## 9. Automation, handoff and notifications

**Automation authority.** Before every execution, `AutomationEngine::perform` checks the rule's accountable owner:
- the owner exists and is permitted;
- they still hold `automation.activate`;
- the rule's scope is still theirs.

Otherwise the rule is paused (audited) and nothing runs. Re-activation by someone with the authority makes them the owner (audited transfer).

**Ownership handoff** (`OwnershipHandoffService` via `ProcessOwnershipHandoffJob` on the `automation` queue; unique; idempotent per loss of access). On suspension or revocation:
- the person's automation rules are paused;
- their Action Center items move to the nearest reachable manager, else an HR administrator (`identity.handoff_fallback_permission`);
- their open applications, requisitions, interviews and direct reports are counted into an `ownership_handoffs` record, plus one Action Center task for the responsible person.

**Historical owners are never rewritten.** Current responsibility changes only when someone reassigns the work through the existing audited actions. A handoff is marked complete (Access Review) only once nothing is still attributed to the person. Reassigning an application's recruiter now requires an active recruiter.

**Notification routing.** Every staff alert goes through `NotificationDispatchService::alert()`: the hourly recruitment alerts, listeners and automation. It reaches the recipient only if they are *reachable* (a current employee, not deleted, with a permitted login). Otherwise it goes to the nearest reachable manager, then an HR administrator, and is otherwise dropped and logged.

## 10. Credentials and MFA

- **Password policy** (`Password::defaults()`, used by the Users form, profile, reset page and services):
  - 12 or more characters, mixed case, a number and a symbol;
  - `NotCommonPassword` (common words however decorated or spelt with look-alikes);
  - an optional breached-password check, `identity.password.check_breached`.
- **Staff password reset** (panel `passwordReset()`): broker tokens are single-use and expire in 60 minutes, and requests are throttled. A link is never sent to a suspended or revoked login. A completed reset signs out every other session.
- **Administrator password reset** follows the same policy, signs the person out everywhere, and is audited without the value.
- **Email changes are verified.**
  - The profile uses `emailChangeVerification()`.
  - An administrator's change only sends the verification link; it applies once the person confirms it while signed in, and the old address is told and can block it.
- **Sign out everywhere:** from your profile (this session stays), or for someone else with `users.access.manage` in scope.
- **Sign-in:** `StaffLogin` adds a per-account lockout (10 failures in 15 minutes, across IPs, password or MFA) to Filament's per-IP throttle.
- **MFA:** authenticator app with recovery codes, using Filament v5 and the installed `pragmarx/google2fa`.
  - It is **required** for the role keys in `identity.mfa.required_roles` (CHRO, VP HR, Manager) and for holders of any `identity.mfa.privileged_permissions`, switched by `identity.mfa.enforce`.
  - `EnsureStaffMfa` sends someone who hasn't enrolled to set-up before any page.
  - A person who must use MFA cannot turn it off. An administrator can reset it; this is audited and signs the person out.
  - Secrets and recovery codes are encrypted, hidden attributes: never in serialization, the audit log, AI, notifications or responses.

## 11. Audit and observability

Every identity change is written to `audit_logs`. Each row carries:
- the actor (staff `user_id`, or null for the system);
- the target;
- old and new values;
- a reason or source;
- the IP address;
- **`request_id`**, a correlation id from `AssignRequestId` (an incoming `X-Request-Id` is kept), shared with the logs and queued jobs through `Context`.

**Actions recorded:**

| Area | Actions |
|---|---|
| Sign-in | `login`, `logout`, `login_failed` (known logins; unknown addresses only logged), `login_locked_out`, `mfa_challenge_failed` |
| Passwords | `password_changed`, `password_reset_requested`, `password_reset_completed`, `password_reset_by_admin` |
| Email | `email_change_requested` (the change itself is audited as an update) |
| Access | `access_suspended`, `access_restored`, `access_revoked`, `sessions_revoked`, `signed_out_everywhere` |
| Roles | `roles_changed`, `roles_removed`, `roles_assigned`, role `created`, `permissions_updated`, `deleted`, `protected_role_refused`, `last_chro_protected` |
| Employee | `employee_linked`, `user_provisioned`, `invitation_sent`, `employment_active`, `employment_inactive`, `employment_separated`, `employee_rehired`, `reporting_line_changed` |
| Separation | `separation_effective`, `separation_cancelled` |
| MFA | `mfa_enabled`, `mfa_disabled`, `mfa_disable_refused`, `mfa_reset_by_admin`, `mfa_recovery_codes_regenerated` |
| Automation and handoff | `automation_rule_paused_authority`, `automation_rule_owner_transferred`, `ownership_handoff_started`, `ownership_handoff_completed` |
| AI actions | Expired / Invalidated tool calls are recorded in `ai_action_logs` |

**Structured logs** (`identity.*`) carry ids only. Passwords, tokens, MFA secrets, compensation and AI content are never logged.

**Events**, all after commit and ids only: `EmployeeAccessSuspended`, `EmployeeAccessRevoked`, `EmployeeAccessRestored`, `EmployeeSeparated`, `SeparationCancelled`, `UserProvisioned`, `UserRoleChanged`.

## 12. Access Review and admin UI

- **Access Review** (Administration, `access.review`; hierarchy-scoped with no global bypass; constant query count). Every login with:
  - employment, access (with reason), roles, permission count, manager;
  - MFA (Enabled / Required — pending / Not configured);
  - last sign-in, live sessions, pending AI actions, open handoffs and separation.
  - It offers the same confirmed, reasoned actions as the Users screen, plus "Mark handoff complete".
- **Users screen:** employment, access and MFA badges; last sign-in; hierarchy-scoped. Actions (never on yourself): **Suspend**, **Restore**, **Revoke**, **Sign out everywhere**, **Reset MFA**.
- **Employees:** **Deactivate** / **Reactivate** replace the editable status field.
- **Separations:** a lifecycle column (Scheduled / Effective / Cancelled) and **Cancel separation**.
- **Joinings:** the convert action asks for the manager and also performs rehires.

## 13. Outcome Loop compatibility

A minimal change was needed for the new states, and it gives the same results for existing data.

In `OutcomeCalculator` the separation considered is the one that ended *this* employment: the earliest non-cancelled separation dated on or after the snapshot's `joined_on`. A cancelled separation, or one from before a rehire, never counts.

An employee whose status is now Separated observes as Active at checkpoints before the separation date — exactly what the unchanged status showed before 8.4. Outcome records stay immutable; cancellation voids through `OutcomeService`. The whole Phase 8.2 suite passes unchanged.

## 14. Metric fixes (D12)

Only the three confirmed defects were fixed; no other metric definitions changed.
1. **Copilot offer acceptance rate** compared an enum with strings and was always blank. It now compares `OfferStatus` cases.
2. **Copilot `time_to_hire`** ignored `department_id` for the average. The average now takes the same department filter as cost and joins.
3. **Analytics time-to-hire mean** now counts whole calendar days (start of day to start of day) and leaves out a start after the joining date. These are the Phase 8.3 hiring-snapshot semantics.

## 15. Commands and schedule

| Command | What | Schedule |
|---|---|---|
| `identity:enforce-separations [--dry-run]` | Applies separations that took effect (idempotent, per-record isolation, last CHRO protected) | Hourly (`identity.scheduled_enforcement`) |
| `ai:expire-pending-actions` | Expires AI actions past their window | Every 5 minutes |
| `identity:reconcile-access [--execute]` | Backfill; dry run by default. Applies due separations and suspends logins of inactive or deleted employees, through the services, idempotent | Manual |
| `identity:audit` | Read-only; ids only; non-zero on ERROR. Covers access vs employment, CHRO coverage, protected keys, hierarchy closure, stale AI actions, automation authority, MFA coverage, handoffs | Manual / deployment gate |

## 16. Configuration

**`config/identity.php`:**
- `scheduled_enforcement`
- `protected_roles`
- `base_role`
- `chro_role`
- `handoff_fallback_permission`
- `mfa.enforce`, `mfa.required_roles`, `mfa.privileged_permissions`
- `password.min_length`, `password.check_breached`

**Environment switches:**
- `IDENTITY_SCHEDULED_ENFORCEMENT`
- `IDENTITY_MFA_ENFORCE` (phpunit sets it false; the MFA tests enable it)
- `IDENTITY_PASSWORD_CHECK_BREACHED`
- `AI_ACTION_PENDING_TTL_MINUTES`

**New permissions** (grant migration by role key; VP HR and CHRO): `users.access.manage`, `access.review`, `employees.separation.cancel`.

## 17. Migrations (all additive; 137 → 145)

1. `2026_09_26_204219_add_access_state_to_users_table`: access state, reason, source, revoked roles, session epoch, last sign-in. Every existing user starts as `active`.
2. `2026_09_26_204338_add_key_to_roles_table`: key and is_protected. The seeded roles are keyed by current name and `chro` is protected.
3. `2026_09_26_204641_grant_phase_eight_four_permissions`: data, granted by key.
4. `2026_09_26_210106_add_lifecycle_to_employee_separations_table`:
   - `effective_applied_at` and cancellation columns;
   - `users.access_source`;
   - the unique key on `employee_id` becomes a plain index (an index change only).
5. `2026_09_26_212307_add_identity_to_ai_tool_calls_table`:
   - requester, expiry, fingerprint and invalidation columns;
   - backfill: requester = conversation owner; old pending calls expire (created + 30 minutes).
6. `2026_09_26_213331_add_request_id_to_audit_logs_table`.
7. `2026_09_26_214218_add_mfa_to_users_table`.
8. `2026_09_27_033922_create_ownership_handoffs_table`.

**Existing data is never silently rewritten.** Deployment changes nobody's stored access. The gate refuses only logins whose separation has already taken effect. `identity:reconcile-access` (dry run first) maps the rest.

## 18. Deployment

1. Take a **full database backup**.
2. Enable maintenance mode (`php artisan down`) for the migration window.
3. Run `php artisan migrate --force`.
4. Run `php artisan config:cache`, `route:cache` and `view:cache`. The permission cache is flushed by the grant migration.
5. Deploy **both** queue workers (`queue`: communications, automation, default; `queue-background`: intelligence, integrations, default), then run `php artisan queue:restart`.
6. For the first window only, set `IDENTITY_SCHEDULED_ENFORCEMENT=false`, then enable the scheduler. `ai:expire-pending-actions` can run straight away.
7. Keep `IDENTITY_MFA_ENFORCE=true`. Privileged users enrol at their next sign-in, so tell them first.
8. Verify CHRO protection: `php artisan identity:audit` must report no `no_effective_chro` / `protected_role_missing` error.
9. Run `php artisan identity:reconcile-access` (dry run) and review the counts.
10. Run `php artisan identity:reconcile-access --execute`, then set `IDENTITY_SCHEDULED_ENFORCEMENT=true`.
11. Run `php artisan lifecycle:audit` (Phase 8.3; read-only).
12. Run `php artisan identity:audit` again. It covers the AI pending-action, hierarchy and ownership checks.
13. Run `php artisan up`, then smoke-test:
    - sign-in with MFA;
    - Access Review;
    - suspend and restore a test login;
    - convert a test joining.

## 19. Rollback

- Do not rely on `migrate:rollback` in production. Every migration has a working `down()`, but a rollback discards identity history.
- **Prefer switches and forward fixes:**
  - `IDENTITY_MFA_ENFORCE=false` stops requiring MFA;
  - `IDENTITY_SCHEDULED_ENFORCEMENT=false` stops scheduled separation enforcement (the gate still refuses separated logins);
  - a mistaken suspension or revocation is undone with **Restore** (audited);
  - a mistaken separation is undone with **Cancel separation**.
- For a catastrophic migration failure, restore the backup taken in step 1.

## 20. Known limitations

- **Session rows** are deleted immediately only with the database session driver. With other drivers the epoch makes every old session invalid on its next request.
- **Email-change verification** needs the person to open the link while signed in. For someone who cannot sign in, restore access (or reset their password) first.
- **The invitation link** expires with the password broker (60 minutes). After that, "Forgot password?" works.
- **Handoff** counts open requisitions, interviews and direct reports and assigns the task, but moving them is done through the existing forms (by design: current responsibility changes explicitly).
- **Employment episodes** are not a table: a rehire is the pair of joining and separation records. The Outcome Loop picks the separation by date within the employment.
- **Only the requester can approve** their AI action; there is no delegated approval.
- **The MFA enrolment redirect** applies to page loads. A Livewire update on a page opened before enrolment became required continues until the next page load (access itself is always re-checked).
- **The common-password list** is small and offline. Enable `identity.password.check_breached` where outbound network access is allowed.
- **A failed sign-in for an unknown address** is logged but not audited (no subject to attach it to).

## 21. Backlog

See `docs/backlog.md` (P84-BACKLOG-001…011).
