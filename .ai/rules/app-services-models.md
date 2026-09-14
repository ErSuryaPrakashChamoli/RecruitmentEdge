---
paths:
  - 'app/Services/TargetResolutionService.php,app/Models/RecruitmentDailyTarget.php'
---

# App Services Models

## Targets have exactly one scope; resolution is tier-first then prorated
A RecruitmentDailyTarget must set exactly one of employee_id / designation_id / department_id (model saving guard throws DomainException). Resolution picks the most specific tier that has any target for the metric (employee > designation with employee_id null > department with employee_id and designation_id null; a tier is skipped when the recruiter lacks that attribute — never let a null attribute match null-scoped rows). Within the tier, an exact Daily/Weekly/Monthly period match is used as-is, otherwise the shortest configured period is prorated across the range.
