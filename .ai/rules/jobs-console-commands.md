---
paths:
  - 'app/Jobs/**,app/Console/Commands/**,routes/console.php'
---

# Jobs Console Commands

## Queued and scheduled work runs per tenant
SaaS-1: a queued payload carries its tenant automatically (Context + top-level tenant_id); TenantQueueGuard refuses a job whose payload and context disagree or whose tenant may not have work done; a job without a tenant is platform work and cannot touch tenant data. A scheduled task on tenant data must be listed in App\Services\Tenancy\TenantTasks and scheduled as `tenants:dispatch <task>` (one queued job per active tenant) or `tenants:run <task> --all` (long tasks, background process). Never sweep tenant tables across tenants in a command.
