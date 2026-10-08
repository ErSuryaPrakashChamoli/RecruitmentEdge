---
paths:
  - 'app/Models/RecruitmentDailyTarget.php,app/Services/RecruitmentTargetService.php,app/Filament/Resources/RecruitmentDailyTargets/**,app/Policies/RecruitmentDailyTargetPolicy.php'
---

# Recruitment Daily Targets Policies

## Daily targets are hierarchy-scoped and written only through RecruitmentTargetService
Phase 8.9 (P89-SEC-001).

**Scope and policy.**
- RecruitmentDailyTarget::visibleTo($user) / isVisibleTo() scope every list.
- The policy also uses them.
- A target set at department or designation level, or with no employee, is visible only with hierarchy.view-all.

**Writes.** Create, edit, delete and bulk delete go through RecruitmentTargetService. It checks the Gate and the scope for every record.

**Bulk delete.** The Filament bulk delete uses authorizeIndividualRecords('delete')->using(service->deleteMany).

**Form.** The employee options list only visible employees.
