---
paths:
  - 'app/Services/Platform/Commercial/**'
---

# Commercial

## Commercial control plane: platform-only, tenant row is the lock
Plans, assignments, overrides, lifecycle and provisioning change only through these services, behind PlatformOperatorGate (platform administrator, or null = console). No Filament/Http/Livewire/job code may reference this namespace (CommercialArchitectureTest). Every change locks the tenant row FOR UPDATE and calls CommercialChange::record() in the same transaction (bumps entitlement_version = cache key, audits in the tenant stream). Lock order: CHRO role → tenant → user → membership; invitation → tenant → user → membership. Published plan versions are immutable — add the next version in PlanCatalog. Nothing here deletes tenant data.
