---
paths:
  - 'app/Services/Platform/Commercial/**'
---

# Commercial

## Commercial control plane: platform-only, tenant row is the lock
Plans, assignments, overrides, lifecycle and provisioning change only through these services, behind PlatformOperatorGate (platform administrator, or null = console). No Filament/Http/Livewire/job code may reference this namespace (CommercialArchitectureTest). Every change locks the tenant row FOR UPDATE and calls CommercialChange::record() in the same transaction (bumps entitlement_version = cache key, audits in the tenant stream). Lock order: CHRO role → tenant → user → membership; invitation → tenant → user → membership. Published plan versions are immutable — add the next version in PlanCatalog. Nothing here deletes tenant data.

## CommercialSubscriptionService is the only billing → SaaS-3 bridge
Billing state enters SaaS-3 only through CommercialSubscriptionService::sync() (inside the billing transaction, tenant locked): plan assignment for trialing/active/past_due/cancelling, lifecycle (Active, PastDue, Suspended with status_reason billing_unpaid / subscription_ended) and tenants.access_ends_at via TenantLifecycleService::setAccessEnd(). It never lifts a suspension billing did not cause (LIFTABLE_REASONS), never acts on closed/provisioning tenants, and audits billing_commercial_state_not_applied instead of forcing a refused change. Every lifecycle transition except into PastDue clears access_ends_at.
