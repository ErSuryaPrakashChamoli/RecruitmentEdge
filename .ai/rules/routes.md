---
paths:
  - 'routes/**'
---

# Routes

## Every route lives under a tenant or resolves its tenant itself
SaaS-1: requests start with no tenant (ResetTenantContext). Panel pages are /admin/{tenant}; careers/portal/calendar-connect use the {tenant} path segment with ResolveTenantFromRoute (slug looked up in tenants, never Host; removed before controllers run). Other routes must resolve the tenant from a signed or platform-controlled source (signed tenant in files.private, the export's tenant, the provider message, the OAuth session) or be platform-level — and be added to the route allow-list in TenancyArchitectureTest with the reason.
