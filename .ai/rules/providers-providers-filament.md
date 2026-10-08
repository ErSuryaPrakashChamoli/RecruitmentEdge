---
paths:
  - 'app/Policies/**,app/Providers/AppServiceProvider.php,app/Providers/Filament/AdminPanelProvider.php'
---

# Providers Providers Filament

## Filament strict authorization in local/test; production fails closed
Phase 8.6 (D8.6-027): the admin panel is strictAuthorization() in local and testing — a missing policy or policy method throws. In production AppServiceProvider's Gate::before denies an ability whose model policy has no such method (policyLacksAbility). Every model a resource or relation manager shows needs a policy (tests/Feature/Security/PolicyCoverageTest); read-only history listed in relation managers uses Policies\Concerns\ReadOnlyRecord. Supersedes the earlier "missing method is an allow" note.
