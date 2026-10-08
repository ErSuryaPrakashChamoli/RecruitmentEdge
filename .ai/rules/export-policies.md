---
paths:
  - 'app/Filament/**,app/Services/Export/**,app/Policies/ExportPolicy.php'
---

# Export Policies

## Exports are governed centrally (SEC-88-03/12/13/15)
AppServiceProvider::configureExports() applies to every ExportAction/ExportColumn: maxRows 10,000 (ExportGovernance::MAX_ROWS), preventFormulaInjection(), before/after hooks + Export::created that audit export_requested / export_refused; ExportPolicy limits downloads to the owner within 24 h of completion; the `filament.actions` middleware group adds EnforceStaffAccess, EnsureStaffMfa and AuditExportDownload. Do not set before()/after() on an individual ExportAction (it would replace the audit hooks). Report CSVs go through ReportExportService (formula-neutralised) and must audit report_exported. Anything showing pay (offer letter, incentive export/statements) needs compensation.view, except a user's own statement.
