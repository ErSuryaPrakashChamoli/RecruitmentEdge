---
paths:
  - 'app/Providers/AppServiceProvider.php,app/Filament/Resources/**,resources/css/filament/admin/theme.css'
---

# Css Filament Admin

## Table rows: hover expands the row to show its actions + row click opens the view page (project-wide)
AppServiceProvider::configureTables() sets Table::configureUsing() defaults for every table: recordActionsPosition(BeforeColumns) and a recordUrl that opens the record's resource view page (edit as fallback). theme.css collapses that first column; hovering a row tints it and grows it downwards, showing its actions on one left-aligned line inside the row (hover-capable devices only). Keep the strip inside the row, not an overlay: an overlay covering the next row was rejected. Filament's .fi-ta-actions max-width:100% + justify-end must stay reset there, or the buttons spill left off the table. Consequences: don't wrap row actions in an ActionGroup (users want every eligible button visible), and don't set recordActionsPosition/recordUrl per table unless deliberately overriding. Every resource with an edit page must also have a 'view' page (TableRowInteractionsTest enforces it). CSS changes need npm run build.
