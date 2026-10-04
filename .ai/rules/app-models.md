---
paths:
  - 'app/Models/*.php'
  - app/Models/CandidateStageHistory.php
  - 'app/Models/**'
  - app/Models/User.php
---

# App Models

## Use Candidate::visibleTo / RecruitmentRequisition::visibleTo for scoping
Candidate and requisition visibility each have one definition: the Candidate::visibleTo (#[Scope]) and RecruitmentRequisition::scopeVisibleTo(User) query scopes. CandidateResource/RecruitmentRequisitionResource getEloquentQuery() call them, and EDGE Intelligence uses them. Do not copy the whereIn(manager_id...)/whereHas(applications recruiter_id) logic again (CostPerHireService and EmployeeReferral still hold older copies).

## Hiring facts change only through their lifecycle service (LifecycleGuard)
Phase 8.3: CandidateApplication, Interview, InterviewFeedback, Offer, CandidateJoining and RecruitmentRequisition use GuardsLifecycleAttributes — updating a lifecycle attribute outside LifecycleGuard::allow() throws LogicException. Only StageTransitionService, ApplicationAssignmentService, InterviewService, InterviewFeedbackService, OfferService, CandidateJoiningService and RequisitionApprovalService may open the guard (architecture test). Add new lifecycle writes to those services, never to Filament actions/forms, tools or automation handlers. Tests that need a precondition state use the lifecycleFixture() helper; the behaviour under test must go through the service. Creating records is not guarded.

## Stage history rows carry an event; "reached a stage" = milestoneEntries()
Phase 8.5 (DF-9): candidate_stage_histories.event (StageHistoryEvent) says what a row is — stage_entered, rejected, dropped, held, reactivated, moved_requisition, stage_corrected. Status changes and requisition moves write same-stage rows; they are never a stage reached. Metrics, targets, incentive actuals, SLA and stall checks must use ->milestoneEntries() (milestone changed) or ->pipelineStageEntries() (configured-stage moves), or CandidateStageHistory::constrainToMilestoneEntries() for raw queries. Legacy rows (event null, pre-8.5) are classified at read time by previous_stage != new_stage and are never rewritten. Every writer (StageTransitionService, ApplicationAssignmentService) must set event.

## Tenant-owned models: BelongsToTenant, fail-closed scope, explicit bypass only
SaaS-1: every table in TenantSchema::TENANT_TABLES has tenant_id NOT NULL and its model uses App\Models\Concerns\BelongsToTenant (arch test). The scope throws MissingTenantContext with no tenant — never widen it. Crossing tenants is only ->withoutTenancy() in the files allow-listed in tests/Feature/Tenancy/TenancyArchitectureTest.php. A new table must be classified in TenantSchema; tenant pivots use ->using(TenantPivot::class). App\Models\Role deliberately has NO global scope (spatie's one permission cache must see every role) — query roles with Role::forCurrentTenant()/byKey().

## SaaS-2: tenant-dependent relations are dropped when the tenant changes
User::relationLoaded()/setRelation() discard loaded roles, permissions and employee when the TenantContext differs from the one they were loaded in (spatie team relations would otherwise answer for the wrong tenant on the same object). Access memos (StaffAccessService) are invalidated by every identity change and every Tenant/TenantMembership save; raw query-builder writes in tests need ->fresh() or StaffAccessService::invalidateDecisions(). Default tenant: never Filament's "first tenant" fallback (StaffFilamentManager); no default ⇒ /admin/organisations chooser.
