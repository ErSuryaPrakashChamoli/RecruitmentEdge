---
paths:
  - resources/css/filament/admin/theme.css
---

# Filament Admin

## @theme blocks are silently dropped in the admin viteTheme entry — use plain :root instead
Adding a custom `@theme { --color-x: ...; }` block to resources/css/filament/admin/theme.css (a secondary viteTheme entry, not the app's main Tailwind entry in resources/css/app.css) gets silently dropped during build — verified by grepping the compiled output, no error is raised. Use a plain `:root { --color-x: ...; }` block instead for custom CSS custom properties in this file; it compiles correctly and is usable via var(--color-x) in the same file's other rules or in Blade components. This only affects auto-generating new Tailwind utility classes from the token (e.g. bg-x) — for that, keep using literal palette classes (text-emerald-600 dark:text-emerald-400 etc.) matched via PHP match() arms, the existing convention in kpi-card.blade.php/kpi-stat.blade.php.
