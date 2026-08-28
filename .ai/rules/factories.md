---
paths:
  - 'database/seeders/**,database/factories/**'
---

# Factories

## DatabaseSeeder must not use WithoutModelEvents; keep factory uniqueness on DB-unique columns only
DatabaseSeeder deliberately omits the WithoutModelEvents trait because EmployeeObserver relies on Eloquent created/updated events to maintain the employee_hierarchy closure table — re-adding that trait silently breaks hierarchy scoping with no visible error. Also: only mark a factory field ->unique() if the DB column actually has a unique constraint (e.g. `code`, not `name`) — EmployeeFactory creates a fresh Department/Designation/Location per employee by default, so a unique() on a small/finite Faker pool (e.g. jobTitle(), a short randomElement list) exhausts fast across a test run.
