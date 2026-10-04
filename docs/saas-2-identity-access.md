# SaaS-2 — Identity, Membership & Access

**For:** Engineering, and Security reviewers.

**Branch:** `feature/saas-2-identity-access`, branched from `0e8d865` (SaaS-1A). Not pushed, merged or deployed.

**Read with:**
- `saas-2-decision-register.md`: every decision and why.
- `saas-2-security-review.md`: findings and tests.
- `saas-2-migration-plan.md`: the data move.

## 1. The model

```
GLOBAL IDENTITY (users)               one row per person, platform-wide
  name, email (unique, normalised), password, MFA, sessions, theme, disabled_at (platform lock)
        │
        ├── TENANT MEMBERSHIP (tenant_memberships)       one per person per tenant
        │     status: active | suspended | revoked  (AccessState) + who / when / why / source
        │     employee_id  → the person's employee record IN THAT TENANT (unique: one login per employee)
        │     revoked_roles, is_default, joined_at, last_selected_at
        │        │
        │        ├── TENANT ROLES (spatie teams = tenants: model_has_roles.tenant_id)
        │        │      └── permissions
        │        └── TENANT DATA (BelongsToTenant, fail-closed TenantScope — SaaS-1)
        │
        └── PLATFORM ROLES (platform_operators)     administrator | support | compliance
               never a membership, never a tenant role, opens no tenant
```

**Invitations** (`tenant_invitations`, owned by a tenant) are how a membership is created. "Invited" is a pending invitation, never a membership row.

## 2. What the identity holds, and what the membership holds

| Question | Answered by | Changed by |
|---|---|---|
| Who is this person, and how do they sign in? | `users` (global) | the person (profile, "Forgot password"); an administrator only for an identity that belongs to their tenant alone (D-S2-08) |
| May they act in tenant T? | T's membership `status` = Active, T usable, identity not disabled, no employment block in T | `StaffAccessService` (suspend / revoke / restore), within T only |
| Which employee are they in T? | T's membership `employee_id` | `IdentityProvisioningService::linkEmployee`, invitation acceptance |
| What may they do in T? | spatie roles with `tenant_id = T` | `RoleAssignmentService` |
| Which tenant do they land in? | the membership marked `is_default`, while accessible | the person (organisation chooser) |
| Is the whole identity locked? | `users.disabled_at` | platform staff (`identity:disable`) |
| Do they operate the platform? | `platform_operators` | platform staff (`platform:operator`) |

**Reading tenant facts in code.** `$user->employee_id`, `$user->employee`, `$user->access_status` and `$user->revoked_roles` give the current tenant's membership values (TenantContext). They are null outside a tenant or for a non-member, and writing them throws. Queries over staff go through the membership:
- `User::membersOfCurrentTenant(fn ($membership) => …)`
- `User::linkedToEmployee($id)`
- `User::whereEmailIs($email)`
- `Employee::user()` is a `HasOneThrough` the membership.
- `User::employee()` is a `HasOneThrough` the current tenant's membership.

## 3. Authorisation

Every decision fails closed.

1. **Panel sign-in** (`User::canAccessPanel`): the identity is not disabled and `accessibleTenants()` is not empty. A person with no tenant left is signed out, never shown a 403 page inside a session.
2. **Tenant of a request:**
   - It is the `{tenant}` in the URL.
   - On every request, Livewire updates included, Filament's `IdentifyTenant` and `SetTenantContextFromPanel` both call `canAccessTenant()`: an Active membership, a usable tenant, a role there, and permitted there.
   - An unknown tenant and a forbidden tenant both answer 404, so existence is never revealed.
3. **Permissions** (`User::hasPermissionTo`) hold only when `permits()` holds for the current tenant, and then only the current tenant's roles count (spatie teams). There is no permission outside a tenant.
4. **Records:**
   - Policies decide inside the tenant.
   - `Gate::before` refuses any ability on another tenant's record (SaaS-1).
   - `TenantScope` refuses tenant-less queries (SaaS-1).
5. **No cross-tenant reuse:**
   - Spatie's loaded `roles` and `permissions`, and the loaded `employee`, are discarded when the tenant context changes on the same object.
   - Access memos are invalidated by every identity change and by every tenant or membership write.
6. **Global credentials** of an identity shared with another tenant are read-only to every tenant (D-S2-08).

## 4. Membership lifecycle

| Event | What changes | Where it is audited |
|---|---|---|
| Invitation accepted (new member) | membership created Active with the invited roles and employee link | the tenant: `invitation_accepted`, `membership_created`, `roles_changed` |
| Invitation accepted (revoked member) | membership re-activated with the invited roles only | the tenant: `membership_activated` |
| Suspend (administrator, or inactive employment) | this membership → Suspended; the tenant's roles kept dormant | the tenant: `access_suspended` |
| Revoke (administrator, or effective separation) | this membership → Revoked; the tenant's roles removed (`revoked_roles`) | the tenant: `access_revoked`, `roles_removed` |
| Restore | → Active; from Revoked, the base role only | the tenant: `access_restored`, `roles_assigned` |
| Last usable membership closes | every session of the identity ends | every tenant the person still belongs to: `sessions_revoked` |

**Other tenants' memberships are never touched.** Employment facts (separation, inactive) are read inside the tenant that holds the employee record. Each tenant's `identity:enforce-separations` (per tenant, SaaS-1 scheduler) revokes only its own membership.

## 5. Tenant switching and the default tenant

- Filament's tenant switcher lists `getTenants()`, which is `accessibleTenants()`: no suspended, revoked or unusable tenant, and never a tenant the person does not belong to.
- **Default tenant** (`User::getDefaultTenant`). It is a convenience, never an authorisation:
  1. the membership marked as the default, while it is accessible;
  2. otherwise the only accessible tenant;
  3. otherwise none.
- `StaffFilamentManager` removes Filament's fallback to "the first tenant". With no default, `/admin` (`StaffRedirectToTenantController`) opens the **organisation chooser** at `/admin/organisations`. The chooser is tenant-less and MFA-checked. It lists only accessible tenants and lets the person set their default. A default that became inaccessible is never silently replaced; the chooser says so.
- **Session:**
  - The session remembers the last tenant entered (`identity.tenant_id`), only to audit a switch once and to give the tenant-less profile page a tenant.
  - The remembered tenant is re-checked against the membership before every use. Nothing is authorised by it.
- **Audit:**
  - `tenant_selected` or `tenant_switched` is written in the tenant entered, once per change.
  - The tenant the person came from goes to the platform log only.

## 6. Invitations

| Step | Behaviour |
|---|---|
| Invite | `users.manage`; roles the inviter may grant (this tenant's only); an optional current employee record in the inviter's hierarchy. An active or suspended member of this tenant is not invited again. The same outcome whatever the platform knows about the address. A new invitation supersedes an earlier pending one for the same address. |
| Conversion / rehire | Invite with the base role and the new employee record (authorised by `employees.convert`). A rehired person who still has a login in this tenant is restored instead. |
| Token | 64 random characters in the link; SHA-256 stored; never logged or audited. The link (`/invitations/{token}`) moves the hash into the session and redirects to `/invitations`. |
| Accept: signed in as the invited address | membership created or re-activated in the invitation's tenant |
| Accept: new person | They choose a name and a password (staff policy). The identity is created once, with the email marked verified. Then they sign in. |
| Accept: address already has an identity | "Sign in with it to accept"; never a second identity |
| Accept: another identity is signed in | refused ("sent to a different address"), audited |
| Refused | Expired, revoked, used, unknown, or tenant suspended or cancelled: the same "can't be used" page. Also refused: roles deleted, inviter lost authority, or employee record no longer available. All are audited after the rollback. |
| Expire | `invitations:expire`, hourly, per tenant (`tenants:dispatch`) |
| Resend / revoke | Invitations screen; resend replaces the token |

Concurrency is proven on MySQL: two acceptances, acceptance vs revocation (both orders), acceptance vs tenant suspension, two tenants' invitations creating one identity, and two invitations into one tenant.

## 7. Authentication, password reset and MFA

- **Sign-in** establishes the global identity first (Filament login, per-account lockout, MFA challenge). A person who can enter no tenant gets the same failure as a wrong password.
- **"Forgot password"** (`StaffRequestPasswordReset`) shows the same message for every outcome. The link resets the one global password (queued, encrypted mail, Phase 8.7).
- **Email changes** (profile and administrator) never say an address is taken. A taken address gets no verification link.
- **MFA:**
  - It is required by a privileged role or permission in any tenant (SaaS-1), or by the policy of any tenant the person is an Active member of (`tenants.mfa_required`, Security Policy page, `users.access.manage`, audited).
  - The requirement belongs to the identity, so it applies on every page, the chooser, the profile and Livewire.
  - A person cannot turn MFA off while any tenant requires it.
  - An administrator can reset MFA only for an identity that belongs to their tenant alone.

## 8. Platform plane (foundation for SaaS-5)

- `platform_operators`:
  - administrator / support / compliance;
  - granted and revoked with `php artisan platform:operator grant|revoke|list`;
  - audited in the platform stream;
  - **opens no tenant** (nothing in the tenant plane consults it).
- `support_access_grants`:
  - a tenant's own grant to one active support operator;
  - `users.access.manage`, a reason, at most `identity.support.max_minutes`, revocable, audited in the tenant;
  - **nothing honours it yet**. The SaaS-5 support console will require an active grant, open its own audited, read-only-by-default session, and never sign in as the customer.
- `identity:disable <email> [--enable]` locks or unlocks a whole identity (platform decision). It closes every tenant and ends every session.

## 9. Data, jobs, cache and observability

- **Jobs:**
  - The invitation mail and membership effects run in the invitation's tenant; queued payloads carry it (SaaS-1 `TenantQueueGuard`).
  - `invitations:expire` is a `TenantTasks::QUEUED` task.
- **Cache:**
  - spatie's permission → roles map is still one map for all tenants (S1-10, SaaS-7).
  - Per-user role relations are tenant-checked (D-S2-16).
  - No new global cache key for tenant data.
- **Logs:**
  - `identity.*` and `platform.*` lines carry user, membership, invitation and tenant ids (the tenant through Laravel Context).
  - Never a password, OTP, token or session secret.

## 10. Code map

| Area | Files |
|---|---|
| Models | `User`, `TenantMembership`, `TenantInvitation`, `PlatformOperator`, `SupportAccessGrant`, `Tenant` (`mfa_required`) |
| Access | `Services/Identity/StaffAccessService`, `AuthorityGuard`, `EmploymentGate`, `RoleAssignmentService` |
| Onboarding | `Services/Identity/TenantInvitationService`, `IdentityProvisioningService`, `Http/Controllers/Identity/TenantInvitationController`, `Mail/TenantInvitationMail` |
| Switching | `Services/Identity/TenantSelectionService`, `Filament/Tenancy/StaffFilamentManager`, `StaffRedirectToTenantController`, `Filament/Pages/Auth/ChooseTenant`, `Http/Middleware/SetTenantContextFromPanel`, `EnforceStaffAccess` |
| Credentials, MFA | `Services/Identity/CredentialService`, `MfaService`, `TenantSecurityPolicyService`, `Filament/Pages/SecurityPolicy`, `Filament/Pages/Auth/StaffRequestPasswordReset` |
| Platform | `Services/Platform/PlatformAccess`, `SupportAccessService`, `PlatformIdentityService`, commands `platform:operator`, `identity:disable` |
| UI | Users → "Invite member"; Administration → Invitations; Security Policy; tenant menu → "All organisations" |
| Tests | `tests/Feature/IdentityAccess/*`, `tests/Concurrency/IdentityRaceTest.php` |

## 11. Deferred (later phases)

The full list, with owners, is in `saas-2-security-review.md` §5:
- SaaS-5: support console, platform panel and platform audit view.
- SSO / OIDC / SAML / SCIM / passkeys / service accounts.
- SaaS-7: per-team permission caches.
