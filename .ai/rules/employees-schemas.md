---
paths:
  - 'app/Models/Employee.php,app/Filament/Pages/Profile.php,app/Filament/Resources/Employees/Schemas/EmployeeForm.php'
---

# Employees Schemas

## Employee photo uploads use Employee::photoDisk()
SaaS-5 (S1-06): photos are private (local disk, signed PrivateFileController link, any member of the tenant). Until files:privatize-employee-photos has run in a tenant, older photos are still on the public disk. A FileUpload pointed at a disk where the stored file does not exist drops it from the form state (BaseFileUpload::hydrateFiles), and the next save nulls photo_path — so the field's ->disk() must be Employee::photoDisk($record?->photo_path), never a fixed disk.
