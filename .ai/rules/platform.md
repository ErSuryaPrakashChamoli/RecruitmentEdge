---
paths:
  - 'app/Services/Platform/**'
  - app/Services/Platform/PlatformDirectory.php
---

# Platform

## Platform services: authorise first, queue tenant-less, read across tenants only in reviewed classes
SaaS-5: every platform service calls PlatformAuthorization::authorize(operator, PlatformCapability) before anything else; pages only mirror it. Platform jobs (PurgeTenantJob, GenerateComplianceExport) and PlatformEventMail are queued inside TenantContext::runWithoutTenant(fn () => Bus::dispatch(new Job(...))) — Job::dispatch() would carry whatever tenant is current and a closed tenant's queue guard would refuse it (PlatformArchitectureTest). Cross-tenant reads live only in PlatformDirectory, SupportWorkspace and SupportAccessService (TenancyArchitectureTest allow-list). Lifecycle state is still written only by SaaS-3's TenantLifecycleService, under AuditLog::asPlatformOperator attribution.

## PLATFORM_ACTIONS must list every commercial/billing audit action
The platform audit view (and tenant Audit tab) only shows actions in PlatformDirectory::PLATFORM_ACTIONS. When a SaaS-3/SaaS-4 service starts writing a new audit action that a platform operator, the console or billing processes cause (e.g. plan_changed, subscription_{status}, payment_recorded_manually), add it there or it is recorded but invisible. Tenant-side billing details and high-volume provider traffic (webhooks, payment_attempted) stay out. Billing state in the tenant list follows billing's verdict (live subscription past_due/unpaid), not invoice due_at — manual invoices are due at issue.
