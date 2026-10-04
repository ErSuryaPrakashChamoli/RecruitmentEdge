---
paths:
  - 'app/Services/Entitlements/**'
---

# Entitlements

## Entitlements: registry keys, fail closed, consume() for limits
Gate features and limits only through EntitlementService with App\Enums\Entitlement — never a plan code, plan model or key string (CommercialArchitectureTest). Effective = tenant usable? → override in force → pinned plan version → denied; a missing key is never unlimited, "unlimited" is the explicit is_unlimited flag. Adding to a limit goes through consume() (tenant row FOR UPDATE, fresh plan read, locking count); canAdd() is for screens only. Model backstops (assertCanAdd) are skipped inside consume() and are not atomic. Permission and entitlement are separate gates — check both.
