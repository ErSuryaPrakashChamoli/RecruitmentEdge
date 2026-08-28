---
paths:
  - 'app/Filament/Pages/Dashboard.php,app/Filament/Widgets/**,app/Providers/Filament/AdminPanelProvider.php'
---

# Filament

## Main Dashboard must override getWidgets(), never rely on the base class
Filament\Pages\Dashboard::getWidgets() returns Filament::getWidgets() — every widget under app/Filament/Widgets that discoverWidgets() picks up, panel-wide, including page-specific ones (e.g. IncentiveDashboardStats, meant only for the Incentive Dashboard page). If you add a new page-specific widget, it will silently also appear on the main Dashboard unless App\Filament\Pages\Dashboard::getWidgets() (registered via AdminPanelProvider's ->pages([Dashboard::class]) with the App\Filament\Pages import, not Filament\Pages\Dashboard) keeps its explicit curated list. Add every new main-dashboard widget there by name; leave page-specific widgets out and wire them via that page's own getHeaderWidgets() instead.
