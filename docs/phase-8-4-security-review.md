# Phase 8.4: Security Review

This review covers the Access & Identity Lifecycle work on top of Phase 8.3 (`6109823`). It is a focused read-and-test review, not a claim of compliance with any law or standard.

**Where the evidence lives:**
- Tests are under `tests/Feature/Identity` and `tests/Unit/Identity` unless stated otherwise.
- "Mutation-checked" means the control was removed and the test then failed.
- Browser checks refer to the Phase 8.4 smoke (19/19).

## Threat model

| Actor | Goal |
|---|---|
| Former employee (separated, suspended, revoked) | Keep using the system: open sessions, remember-me, approving AI actions, automation they own, alerts with candidate data |
| Delegated administrator (`users.manage`, `roles.manage`, `hierarchy.reassign`) | Gain authority: grant themselves CHRO, give their role more permissions, move people under themselves, relink their own login, delete or rename CHRO |
| Authenticated user tampering with Livewire | Reach another user's conversation or pending AI action |
| Credential attacker | Guess or reset a staff password; take over an account through an email change |
| Operational failure | Lose the last CHRO; a departed owner's work goes unnoticed; stale hierarchy data breaks pages |

## Findings addressed

Discovery ids are from the Phase 8.4 discovery report.

**S1 — Leavers kept full access (Critical).**
- **Control:**
  - An explicit access state with a fail-closed gate (`canAccessPanel`, `hasPermissionTo`, `Gate::before`).
  - An effective separation revokes: roles removed, sessions ended, pending AI actions invalidated, handoff started.
  - Inactive employment suspends.
  - The gate refuses a separated login even before enforcement runs.
- **Verification:** `StaffAccessLifecycleTest`, `SeparationLifecycleTest` (mutation-checked); browser checks 2–6, 14–15.

**S2 — Cross-conversation AI approval (High).**
- **Control:**
  - `conversationId` is `#[Locked]`.
  - Approval looks tool calls up only inside the user's own conversation.
  - `ActionExecutor` accepts only the requester.
  - The authority fingerprint, expiry and access state are re-checked immediately before execution.
- **Verification:** `AiIdentitySecurityTest` (lock, requester, expiry and fingerprint mutation-checked); browser check 7 (server refused the locked property; the other user's action stayed pending).

**S3 — `users.manage` self-escalation, account takeover, relinking (High).**
- **Control:**
  - `RoleAssignmentService`: no changes to your own roles; only roles whose permissions you hold; protected roles only by their holders.
  - `IdentityProvisioningService`: no self-relink; links only to a current employee in scope with no other login.
  - `UserPolicy`: never edit your own login; never delete a login.
  - Administrator password resets obey the policy and are audited; administrator email changes need the person's verification.
- **Verification:** `AuthorityGuardrailsTest` (including "holding every CHRO permission still cannot grant CHRO", mutation-checked), `CredentialLifecycleTest`; browser check 8.

**S4 — `roles.manage` equal to CHRO; rename-then-delete (High).**
- **Control:**
  - Nobody edits or deletes a role they hold.
  - Permissions you don't hold cannot be added.
  - CHRO is protected by an immutable key: undeletable, key immutable, permissions not editable, protection survives a rename, and a recreated name has no authority.
  - Role deletion is audited.
- **Verification:** `AuthorityGuardrailsTest`.

**S5 — `hierarchy.reassign` not scoped (High).**
- **Control:** the employee and the new manager must both be in scope; no moving yourself or a protected-role holder; locked cycle check.
- **Verification:** `HierarchyIntegrityTest` (mutation-checked); browser checks 10–11.

**S6 — Automation outlived its author (High).**
- **Control:** owner authority (access, `automation.activate`, scope) is re-checked before every run; otherwise the rule pauses. The handoff pauses rules on loss of access.
- **Verification:** `OwnershipHandoffTest` (mutation-checked).

**S7 — Weak credential controls (High).**
- **Control:**
  - Password policy with a common-password rule.
  - Staff reset: single-use, expiring, throttled, never sent to non-active logins.
  - Per-account lockout.
  - Email-change verification.
  - MFA required for privileged users.
- **Verification:** `CredentialLifecycleTest`, `MfaTest`; browser check 1 (TOTP sign-in, enrolment redirect).

**S8 — Identity changes unaudited (High).**
- **Control:** audit rows for sign-in and sign-out, failures, lockout, password and email changes, roles, role deletion, access, sessions, MFA, hierarchy, provisioning, separation, handoff and protection events, each with a request correlation id.
- **Verification:** assertions across the identity suite; `CredentialLifecycleTest` (request id).

**S9 — Restore / force-delete of employees not scoped.** Restore is hierarchy-scoped; force delete is never allowed. Verified by `HierarchyIntegrityTest`.

**S10 — Last CHRO deletable; CHRO found by name.** Last-CHRO protection covers suspend, revoke, separation, deactivation and role removal; roles are found by key; users are never deleted. Verified by `StaffAccessLifecycleTest`, `SeparationLifecycleTest`, and browser check 9.

**S11 — Pending AI actions never expired; no requester recorded.** Covered by `requested_by`, `expires_at` (30 minutes), invalidation on access or role change, and the sweep. Verified by `AiIdentitySecurityTest`.

**S12 — Stale permission map in workers (latent).** No job authorises today. Access decisions are memoised per request and invalidated by identity changes and the date. The runbook keeps `queue:restart` after deploys.

**O1 — Deleted recruiter broke pages.** Fixed by a null-safe `canView` and historical relations that resolve soft-deleted employees. Verified by `HierarchyIntegrityTest`.

**P2 — Alerts went to departed staff.** Fixed by central routing to reachable recipients only. Verified by `OwnershipHandoffTest`.

## Authorization boundaries

- **The service layer is the boundary.** Identity attributes are guarded (`LifecycleGuard`, with the architecture test's allow-list). Filament, tools, jobs and automation call services, and every service re-checks permission, hierarchy and the person-specific rules.
- **Architecture tests:**
  - identity state is written only by the identity services;
  - no Filament relationship syncs roles or permissions;
  - role assignment happens only in `RoleAssignmentService`;
  - `App\Models\Role` only, no lookups by name;
  - identity events are after-commit and ids-only;
  - AI approval and automation re-check authority.

## Session and token security

- The session epoch invalidates every session whatever the driver. Database session rows are deleted and the remember token is cycled.
- `EnforceStaffAccess` is persistent and runs before `Authenticate`.
- **No API tokens exist.** A test fails if Sanctum, Passport, `HasApiTokens` or `personal_access_tokens` appear.

## AI security

- The Phase 8.1 boundary is unchanged: the 49-tool privacy contract and block-mode egress suite pass unchanged.
- AI approval is never stronger than current authority: requester only, 30-minute window, and a fingerprint of roles, employee, view-all and own position.
- A target that left the requester's scope is refused by the tool's re-scoping. The atomic claim is preserved.

## MFA

- Authenticator app with recovery codes, required by one central policy. Secrets are encrypted and hidden.
- Removal by the person is refused when their role requires MFA. Administrator reset is audited and signs the person out.
- Wrong codes count toward the lockout and are audited.

## Audit controls

- Values that are never written to the audit log: passwords, tokens, MFA secrets, compensation (Phase 8.3 redaction unchanged) and separation notes (hidden).
- Refused protected-authority changes are recorded after rollback.

## Privacy

- New events carry ids only.
- The Access Review is hierarchy-scoped, shows a permission count rather than the full permission list, and has no global bypass.
- `identity:audit` output carries record codes only.
- Alerts no longer reach departed staff.
- The candidate portal login is retired on conversion; candidate history is kept.

## Remaining risks

- A non-database session driver ends old sessions on their next request, not immediately (the epoch).
- Email-change verification needs the person signed in.
- The MFA enrolment redirect applies at page load.
- The common-password list is offline and small; the breached check is optional.
- Invitation links expire with the broker (60 minutes).
- Failed sign-ins for unknown addresses are logged, not audited.

These are listed as P84-BACKLOG items.

## Secrets

- No key appears in any file, test or commit.
- Smokes and benchmarks used throwaway databases (`hrms_p8x_smoke`, `hrms_p84_perf`), an unreachable fake provider (or none) and a fake key.
- Test MFA secrets are published example values, used only in throwaway data.
- `.env` is untouched.
