---
paths:
  - 'app/Services/Platform/**'
---

# Platform

## Platform services: authorise first, queue tenant-less, read across tenants only in reviewed classes
SaaS-5: every platform service calls PlatformAuthorization::authorize(operator, PlatformCapability) before anything else; pages only mirror it. Platform jobs (PurgeTenantJob, GenerateComplianceExport) and PlatformEventMail are queued inside TenantContext::runWithoutTenant(fn () => Bus::dispatch(new Job(...))) — Job::dispatch() would carry whatever tenant is current and a closed tenant's queue guard would refuse it (PlatformArchitectureTest). Cross-tenant reads live only in PlatformDirectory, SupportWorkspace and SupportAccessService (TenancyArchitectureTest allow-list). Lifecycle state is still written only by SaaS-3's TenantLifecycleService, under AuditLog::asPlatformOperator attribution.
