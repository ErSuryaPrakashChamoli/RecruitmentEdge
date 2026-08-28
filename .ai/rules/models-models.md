---
paths:
  - 'app/Services/StageTransitionService.php,app/Services/RequisitionApprovalService.php,app/Models/CandidateApplication.php,app/Models/RecruitmentRequisition.php'
---

# Models Models

## Stage/status changes only ever go through their service
CandidateApplication.current_stage/status and RecruitmentRequisition.status must never be written directly (`->update(['status' => ...])` etc.) — always go through StageTransitionService / RequisitionApprovalService respectively. Both are state machines (forward-only for stages; an explicit allow-list for requisition statuses) and both write an immutable history row (candidate_stage_histories / recruitment_requisition_approvals) in the same DB transaction as the status change. Rejection and dropout are a `status` layered on top of `current_stage`, not stages themselves — the stage is left as-is so "which stage did they drop at" stays queryable.
