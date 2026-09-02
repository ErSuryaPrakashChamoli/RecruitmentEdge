---
paths:
  - 'resources/views/components/**,resources/css/filament/admin/theme.css'
---

# Admin

## Shared Blade components need their own @source line
The panel theme's @source directives (resources/css/filament/admin/theme.css) originally covered only app/Filament/**/* and resources/views/filament/**/*. Shared reusable Blade components under resources/views/components/** (e.g. x-recruitment.kpi-card, x-recruitment.empty-state) are NOT under either path, so Tailwind silently skipped scanning them — any class used only inside a shared component (not also literally present in a scanned file) would compile to nothing, no error.

Added `@source '../../../../resources/views/components/**/*';` to theme.css to cover this. If you add another top-level shared-view directory outside app/Filament or resources/views/filament, add its own @source line too — don't assume a new directory is covered just because sibling directories are.

Verification tip: since Tailwind escapes special characters in compiled selectors, grep the built CSS for the escaped form (e.g. `dark\:bg-amber-500\/10`, not `dark:bg-amber-500/10`) when checking a class actually compiled.
