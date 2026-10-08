---
paths:
  - 'app/Services/Tenancy/**'
---

# Tenancy

## Permission map generation rotates now, after commit and after rollback
TenantPermissionRegistrar caches one map per tenant (key …tenant.<id>.<generation>). Any invalidation must rotate the token immediately AND via DB::afterCommit AND DB::afterRollBack (mutation-tested): a map rebuilt inside the changing transaction — or by another worker meanwhile — must never be read after the transaction ends. Role changes invalidate the ROLE's tenant (spatie's current-tenant listener is disabled on App\Models\Role); permissions-table changes rotate every tenant (AppServiceProvider hook). With no tenant the map holds no roles.
