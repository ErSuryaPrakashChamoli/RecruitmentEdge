---
paths:
  - database/factories/TenantFactory.php
---

# Database Factories

## Tenant fixtures pin a plan explicitly
TenantFactory pins the internal `legacy` plan (everything, unlimited) after creating, so existing tests keep their behaviour. Commercial tests use onPlan('starter'|'growth'|'enterprise'), withoutPlan() or trial($daysLeft); tests/Concurrency use Race::pinPlan(). An Active membership in a planless or full tenant is refused by the seat backstop — create fixture members before suspending or downgrading the tenant.
