---
paths:
  - 'app/Filament/Resources/RecruitmentRequisitions/**,app/Filament/Resources/Candidates/**,app/Filament/Resources/CandidateApplications/**,app/Policies/RecruitmentRequisitionPolicy.php,app/Policies/CandidatePolicy.php'
---

# Policies Policies

## Requisition/Candidate hierarchy scoping is multi-column, not a single FK
Unlike Employee/CandidateApplication (a single recruiter_id column), a RecruitmentRequisition's hierarchy visibility depends on several columns at once (reporting_manager_id, hiring_manager_id, assistant_manager_id, manager_id, vp_hr_id, created_by, plus the recruiters pivot) — see RecruitmentRequisition::involvedEmployeeIds() and RecruitmentRequisitionResource::getEloquentQuery(). A Candidate isn't owned by a recruiter at all; visibility is derived from whether any of the candidate's `applications` has a recruiter_id in the viewer's hierarchy (CandidatePolicy / CandidateResource::getEloquentQuery(), using whereHas). Don't assume a simple whereIn on one FK covers hierarchy scoping for every model — check how that specific model relates to recruiters first.
