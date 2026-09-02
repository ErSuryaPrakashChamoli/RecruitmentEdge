---
paths:
  - 'resources/css/filament/admin/theme.css,resources/views/filament/components/theme-*.blade.php,app/Providers/Filament/AdminPanelProvider.php'
---

# Components Providers Filament

## Runtime theme switching overrides --primary-* via [data-app-theme], not panel ->colors()
Filament renders --primary-{shade} as inline :root CSS custom properties per request (filament::assets/AssetManager::renderStyles(), sourced from AdminPanelProvider's ->colors()) — every text-primary-*/bg-primary-* utility and component reads var(--primary-*), and Tailwind's --color-primary-* is just var(--primary-*) aliased (see vendor/filament/support/resources/css/index.css), so overriding --primary-* alone is sufficient; no need to touch --color-primary-*.

Three brand presets (navy/emerald/royal) live as :root[data-app-theme="x"] blocks in theme.css — higher specificity than Filament's plain :root, so they win regardless of source order, no Livewire round-trip needed. Shade values were generated with Filament\Support\Colors\Color::generatePalette() per seed hex (php artisan tinker) so every theme keeps identical L/C steps, only hue differs — don't hand-pick oklch values.

Switching is pure client-side: resources/views/filament/components/theme-picker.blade.php (Alpine, hooked at PanelsRenderHook::TOPBAR_END) sets the data-app-theme attribute + localStorage['re-app-theme']; theme-anti-fouc.blade.php (hooked at HEAD_START) reads it back before first paint to avoid a flash. Deliberately scoped to primary/brand color only — gray/success/warning/danger/info stay fixed across every theme so status semantics (green=success, red=danger) never change meaning. To add a 4th theme: generate its palette via tinker, add a :root[data-app-theme="x"] block + an ambient .fi-body gradient variant, add an entry to theme-picker.blade.php's $options array.

## Sidebar text/icon color must target the actual label/icon element, not the parent link
Filament sets `color` explicitly on .fi-sidebar-item-label (gray-700) and on .fi-sidebar-item-btn > .fi-icon (gray-400) — not by inheriting from .fi-sidebar-item-btn. An override on .fi-sidebar-item-btn alone (color: white) has zero visible effect on the label text or icon, since an element's own explicit `color` rule always wins over an ancestor's, inheritance never even applies. Shipped once as a real bug: the dark-gradient sidebar rendered with "ghost" near-invisible nav item text/icons because only .fi-sidebar-item-btn was overridden.

Any future sidebar (or similar Filament chrome) recolor must target every actual text/icon-bearing leaf explicitly: .fi-sidebar-item-label, .fi-sidebar-item-btn > .fi-icon, and their .fi-active/.fi-sidebar-item-has-active-child-items variants — verify by grepping the *compiled* theme CSS for the exact class (e.g. `.fi-sidebar-item-label{`) to see Filament's own competing color rule and confirm your override lands after it (or use !important, as done here, since Filament's own rules are numerous and easy to miss one of).
