---
paths:
  - app/Services/RecruitmentAnalyticsService.php
  - app/Services/InterviewService.php
  - app/Services/RecruitmentSlaService.php
  - app/Services/OfferService.php
  - app/Services/StageTransitionService.php
  - app/Services/RecruitmentActivityService.php
  - 'app/Services/**'
---

# App Services

## positionHealth() risk must not use fulfilment_percent alone
A freshly-opened requisition has 0% fulfilment on day one by definition, so treating low fulfilment_percent as a standalone "at risk" trigger flags every new position immediately — that's an arbitrary false positive, not a leading indicator. Risk in positionHealth() is driven only by ageing overdue (via vacancyAgeing) and pipeline size relative to what's left to fill (position_risk_min_pipeline_ratio) or zero pipeline (critical). fulfilment_percent is still returned for display, just never used to compute `risk`.

Turn-up ratio (line-ups -> turn-ups) is computed from `interviews.status`/`scheduled_at` (turnUpAnalysis/turnUpTrend), not from TargetMetric — it's a ratio, not something a recruiter has a numeric daily quota for, so no TargetMetric case was added for it. TargetMetric::Shortlisted was added instead (wired into RecruiterDailyMetricsService::actualFor() via the existing stageReachedCount() pattern) since Shortlisted is a real target-able metric.

## Interview events drive calendar sync and candidate comms
InterviewService dispatches InterviewScheduled/Rescheduled/Cancelled (ShouldDispatchAfterCommit). Listeners SyncInterviewCalendar and SendCandidateCommunications react. Do not call calendar/video providers directly from services, tools or Filament actions. Provider credentials live in config/services.php (env) and OAuth tokens in calendar_connections (encrypted) — never on Interview records.

## Carbon 3 diffIn* is signed — compute durations as earlier->diffInX(later)
With Carbon 3, now()->diffInDays($past) is NEGATIVE. openBreaches() used that form and never detected a setting-based SLA breach until Phase 6 fixed it ($reachedAt->diffInDays(now())). Always call diffIn* on the earlier date with the later date as argument, and cover the breach path with a test.

## Offers: one eligibility rule, released terms only via revisions
OfferService::offerBlocker()/eligibleApplications() is the only offer-eligibility rule (active, Selected or later, no other open offer) — reuse it in any new offer entry point. From Released onward Offer::TERMS are immutable; a change is requestRevision() (reason) then releaseRevision() by an offers.release holder; the original release is kept as revision 1 (offer_revisions). Offer audit redacts compensation (auditRedactedAttributes). Accepting creates the joining inside OfferService's transaction (CandidateJoiningService::createForAcceptedOffer) — there is no CreateJoiningRecordForAcceptedOffer listener any more; OfferAccepted is ShouldDispatchAfterCommit like every App\Events class.

## User-initiated stage moves use advance(); closures cascade
UI/Copilot/automation canonical moves must call StageTransitionService::advance() (routes through the configured pipeline's rules); transitionTo() is only for domain services recording facts (interview scheduled, offer released, joining). reject()/dropout() run ApplicationClosureCascade in the same transaction: open interviews cancelled (InterviewCancelled with a cause — no candidate message), open offers withdrawn, pending joining Cancelled (rejection) or Dropout. Cascade services must never move the application again (no recursion). Changing requisition/candidate/recruiter goes through ApplicationAssignmentService.

## Recruiter activity is created only through RecruitmentActivityService
Phase 8.5 (SEC-4): activities feed call targets, scores and incentive slabs. RecruitmentActivityService::log/update/delete is the only writer (arch test): actor needs activities.log; recruiter must be self or in the actor's hierarchy; activity_datetime not in the future and at most activity_backdate_days (setting, default 7) back in the business timezone; created_by is always the actor's employee; edits/deletes are refused once the recruiter has an Approved/Payable/Paid incentive calculation covering that day (correct via incentive adjustment); every write is audited (activity_logged/corrected/deleted). Filament create/edit/delete/bulk-delete call the service.

## Lock with RowLock, application first
Phase 8.9 (P89-DQ-001..006/009, PERF-021): a transition decides on the latest row via App\Services\Lifecycle\RowLock::fresh($model) inside a transaction. Never lockForUpdate() then refresh(): at MySQL REPEATABLE READ, refresh() returns the transaction's stale snapshot. To serialise children on a parent, use RowLock::key(Parent::class, $id). Lock order is always the candidate application first, then its interviews, offers, joining or incentive rows. Re-run the guard checks after the lock. For a status flip that must not overwrite a concurrent one, use a conditional update (whereKey()->where('status', expected)->update()) and act on the affected-row count; see AutomationEngine::cancel()/finish() and PerformanceEngine::snapshotFor().

## Tenant context in services: run(), raw queries, bulk inserts, cache keys, dispatch
SaaS-1: the current tenant is TenantContext (Laravel Context). Work in another tenant via TenantContext::current()->run($tenant, fn) — never return a PendingDispatch from that callback (it queues after run() restored the previous tenant): use Bus::dispatch() or a block callback (arch test). Raw DB::table() on a tenant table must name tenant_id; bulk insert()/upsert() skip model events, so set tenant_id in the rows. Cache entries/locks of tenant data use TenantCache::key(); files use TenantStorage::path(). "View all" (null visibility) means all of the current tenant.

## Cache locks stay on the business connection; take a decision lock before the transaction
SaaS-7 (race-tested): the database cache keeps its DATA on `mysql_cache` but its LOCKS on the business connection (`DB_CACHE_LOCK_CONNECTION=mysql`), so a lock (unique jobs, applicant lock, daily-message cap) commits or rolls back with the work it guards; ops:preflight blocks anything else (`cache_locks`). A lock that guards a decision made inside a transaction (e.g. duplicate-applicant matching) must be taken BEFORE opening the transaction (see CareerApplicationService::oneAtATime, re-entrant) — otherwise the transaction's snapshot predates the previous holder's commit and duplicates slip through.
