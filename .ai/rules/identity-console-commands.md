---
paths:
  - 'app/Models/User.php,app/Filament/**,app/Services/Identity/**,app/Console/Commands/**'
---

# Identity Console Commands

## Staff identities are global: list them with membersOfCurrentTenant()
SaaS-1: users have no tenant_id, so TenantScope never limits a User query. Any list, picker, filter or sweep of staff inside a tenant must use User::query()->membersOfCurrentTenant() (Access Review once listed every tenant's logins for a view-all reviewer — S1-F12). Lookups by employee_id are safe (employee ids belong to one tenant); role-based queries (User::role/permission) are already team-scoped. Changing a login's access state is allowed only for the tenant that employs it (AuthorityGuard::ownedByCurrentTenant).
