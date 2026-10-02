---
paths:
  - 'app/Services/Identity/**'
---

# Identity

## Identity state changes only through the Phase 8.4 identity services
User.access_status / employee_id, Employee.status / reports_to_id and the separation lifecycle are guarded (LifecycleGuard); only StaffAccessService, EmployeeLifecycleService, IdentityProvisioningService and HierarchyIntegrityService open the guard (architecture test). Roles and role permissions change only in RoleAssignmentService (roles by immutable key via Role::byKey, never by name; CHRO protected). Access follows employment: Inactive → Suspended (roles dormant), effective separation (day after the last working day) → Revoked (roles removed; restore grants the base role only). The gate is fail-closed: User::hasPermissionTo/canAccessPanel ask StaffAccessService::permits(). Wrap identity operations that can hit last-CHRO protection in AuthorityGuard::protecting() so refused attempts are audited after rollback. Tests arranging identity state use lifecycleFixture().

## Last-CHRO protection runs serialised on the CHRO role row
Phase 8.9 (P89-SEC-004). AuthorityGuard::protecting() opens the transaction and locks the CHRO role row first (serialiseAuthorityChanges), so two removals cannot both see "another CHRO remains". assertEffectiveChroRemainsWithout counts effective CHROs with a locking read. Any new path that removes the chro role, suspends, revokes or separates a user must go through protecting(), never a bare check followed by a write.
