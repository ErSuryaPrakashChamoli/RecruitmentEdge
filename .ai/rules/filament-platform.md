---
paths:
  - 'app/Filament/Platform/**'
---

# Filament Platform

## Platform panel pages: capability gates, locked ids, per-request grant checks
SaaS-5: every page/widget under app/Filament/Platform decides canAccess()/canView() by a PlatformCapability (or isOperator) — PlatformArchitectureTest; nothing outside the panel references App\Filament\Platform. Record ids held in Livewire state are #[Locked]. SupportWorkspace re-checks the grant in hydrate() (every Livewire update) and calls SupportAccessService::open() (audited) on load and scope switch. InteractsWithTable builds the table query on every request, even when no table is shown — never call a grant-scoped query for a scope the page is not showing.
