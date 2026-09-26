---
paths:
  - 'app/Models/*.php'
---

# App Models

## Use Candidate::visibleTo / RecruitmentRequisition::visibleTo for scoping
Candidate and requisition visibility each have one definition: the Candidate::visibleTo (#[Scope]) and RecruitmentRequisition::scopeVisibleTo(User) query scopes. CandidateResource/RecruitmentRequisitionResource getEloquentQuery() call them, and EDGE Intelligence uses them. Do not copy the whereIn(manager_id...)/whereHas(applications recruiter_id) logic again (CostPerHireService and EmployeeReferral still hold older copies).
