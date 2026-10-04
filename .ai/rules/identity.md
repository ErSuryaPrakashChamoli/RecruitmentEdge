---
paths:
  - 'app/Services/Identity/**'
---

# Identity

## Identity state changes only through the Phase 8.4 identity services
TenantMembership.status / employee_id (SaaS-2; formerly User.access_status / employee_id), Employee.status / reports_to_id and the separation lifecycle are guarded (LifecycleGuard); only StaffAccessService, EmployeeLifecycleService, IdentityProvisioningService and HierarchyIntegrityService open the guard (architecture test). Roles and role permissions change only in RoleAssignmentService (roles by immutable key via Role::byKey, never by name; CHRO protected). Access follows employment: Inactive → Suspended (roles dormant), effective separation (day after the last working day) → Revoked (roles removed; restore grants the base role only). The gate is fail-closed: User::hasPermissionTo/canAccessPanel ask StaffAccessService::permits(). Wrap identity operations that can hit last-CHRO protection in AuthorityGuard::protecting() so refused attempts are audited after rollback. Tests arranging identity state use lifecycleFixture().

## Last-CHRO protection runs serialised on the CHRO role row
Phase 8.9 (P89-SEC-004). AuthorityGuard::protecting() opens the transaction and locks the CHRO role row first (serialiseAuthorityChanges), so two removals cannot both see "another CHRO remains". assertEffectiveChroRemainsWithout counts effective CHROs with a locking read. Any new path that removes the chro role, suspends, revokes or separates a user must go through protecting(), never a bare check followed by a write.

## SaaS-2: access state and employee link live on the tenant membership
users holds only the global identity (name, email, password, MFA, sessions, disabled_at). Access state (active/suspended/revoked + status_* + revoked_roles) and employee_id are per tenant on tenant_memberships (guarded: StaffAccessService / IdentityProvisioningService / TenantInvitationService). $user->employee_id / employee / access_status read the CURRENT tenant's membership (null outside a tenant) and cannot be written. Query staff via User::membersOfCurrentTenant(fn ($m) => ...), linkedToEmployee(), whereEmailIs() — never users.employee_id/access_status (dropped; SQLite silently matches nothing). A tenant changes only its own membership; sessions end only when no tenant is left.

## SaaS-2: memberships only via invitations; credentials only for exclusive identities
A person joins a tenant only by accepting a TenantInvitation (admin invite, or conversion/rehire with the base role) — never create a login or membership directly (arch test). Outcomes must be identical whether or not the email already has an identity (S1-01): no "already taken" answers anywhere (invite, profile/admin email change, password reset). A tenant manages global credentials (password, email, MFA reset, sign-out-everywhere, name) only when AuthorityGuard::managesCredentialsOf() — the identity belongs to that tenant alone. Invitation tokens: SHA-256 only, never logged/audited.
