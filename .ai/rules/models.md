---
paths:
  - 'app/Services/HierarchyService.php,app/Observers/EmployeeObserver.php,app/Models/Employee.php'
---

# Models

## Employee hierarchy uses a closure table, not recursive walks
`employees.reports_to_id` is the source of truth for the CHRO->VP HR->Manager->Assistant Manager->Recruiter chain. `employee_hierarchy` (ancestor_id, descendant_id, depth) is a closure table kept in sync by EmployeeObserver on create/update — never write to it directly, and never compute "who reports to whom, transitively" by walking reports_to_id in PHP. Always resolve visibility through HierarchyService::visibleEmployeeIdsFor()/descendantIdsOf()/canView(). A null return from visibleEmployeeIdsFor means the user has `hierarchy.view-all` (CHRO) and should see everything unfiltered — don't loop all employee IDs in that case.
