---
paths:
  - 'app/Filament/Resources/**,app/Policies/**'
---

# Policies

## Every scoped resource needs both a query scope AND a policy
Hierarchy/permission restriction on a Filament resource must be enforced twice: once in the Resource's getEloquentQuery() (keeps out-of-scope records out of lists — see EmployeeResource) and once in a Policy (blocks a direct find($id) via URL tampering — see EmployeePolicy). Policies for models outside App\Models (e.g. Spatie's Role) are NOT auto-discovered by Laravel — register them explicitly with Gate::policy() in AppServiceProvider::boot() (see RolePolicy).
