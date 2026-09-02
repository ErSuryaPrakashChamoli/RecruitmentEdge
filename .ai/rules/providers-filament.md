---
paths:
  - 'app/Filament/Widgets/**,resources/views/filament/**,app/Providers/Filament/AdminPanelProvider.php'
---

# Providers Filament

## Custom Tailwind classes require the admin panel's viteTheme
Filament's default compiled stylesheet only contains its own `fi-*` component classes — arbitrary Tailwind utility classes (grid-cols-3, bg-gray-50, rounded-lg, etc.) written in app/Filament/** or resources/views/filament/** blade views are NOT compiled or included unless a custom panel theme is registered. AdminPanelProvider now has `->viteTheme('resources/css/filament/admin/theme.css')` (added via `php artisan make:filament-theme admin --pm=npm --no-interaction`), and that theme.css's `@source` directives cover `app/Filament/**/*` and `resources/views/filament/**/*`.

Consequence: after adding/changing Tailwind classes anywhere under those paths, you MUST run `npm run build` (or have `npm run dev` watching) or the classes silently have zero effect — no error, just unstyled markup. This bit an entire dashboard's worth of custom widgets before the theme was wired up.

Also: a `heading="..."`/`label="..."` string passed to a Filament Blade component (e.g. `<x-filament::section heading="...">`) is echoed through `{{ }}` internally, so writing an HTML entity like `&amp;` or `&rarr;` inside that attribute double-escapes and renders literally. Use the real character (`&`, `→`) directly in those attributes instead. Entities are fine in the section's *body* HTML (outside `{{ }}`), just not inside a component's string attribute.
