---
paths:
  - 'app/Filament/Platform/**'
---

# Filament Platform

## Platform panel pages: capability gates, locked ids, per-request grant checks
SaaS-5: every page/widget under app/Filament/Platform decides canAccess()/canView() by a PlatformCapability (or isOperator) — PlatformArchitectureTest; nothing outside the panel references App\Filament\Platform. Record ids held in Livewire state are #[Locked]. SupportWorkspace re-checks the grant in hydrate() (every Livewire update) and calls SupportAccessService::open() (audited) on load and scope switch. InteractsWithTable builds the table query on every request, even when no table is shown — never call a grant-scoped query for a scope the page is not showing.

## Platform commercial UI: adapter for writes, PlatformDirectory for reads, permits() in closures
Platform pages never call SaaS-3/SaaS-4 services directly: writes go through App\Services\Platform\TenantCommercialService (authorize capability → AuditLog::asPlatformOperator → the same service the CLI uses; no business rules, no model writes); cross-tenant reads only through PlatformDirectory. Billing records are resolved inside the tenant on screen (TenantContext::run), amounts for payments/refunds come from the stored record, never the form. Inside closures use $this->permits(capability) (memoised per request) — self::allows() per row/action queries platform_operators each time; static canAccess/canView keep self::allows(). Page methods that call perform() outside an action must catch Halt. Memoise tab data in #[Computed] — Filament evaluates each entry state several times per render.
