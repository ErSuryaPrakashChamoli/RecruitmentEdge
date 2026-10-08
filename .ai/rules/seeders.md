---
paths:
  - 'database/seeders/**'
---

# Seeders

## Production seeds only through ProductionBaselineSeeder (additive only)
Never run DatabaseSeeder on a live install: RolePermissionSeeder syncPermissions() resets admin role edits, AdminUserSeeder creates a default-password login. Production uses `db:seed --class=ProductionBaselineSeeder --force`, which must stay additive: create missing permissions/default roles (by key), only top up CHRO, never claim a keyless role with a default name, never create users/memberships/role assignments, seed email templates as Draft (RecruitmentReferenceDataSeeder activateEmailTemplates: false) so candidates are not auto-emailed, and repair employee_hierarchy only where it disagrees with reports_to_id (cycles: report, don't touch).
