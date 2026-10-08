---
paths:
  - 'app/Services/TargetResolutionService.php,app/Models/RecruitmentDailyTarget.php'
  - 'app/Services/CandidateTimelineService.php,app/Models/CandidateTimelineEvent.php'
---

# App Services Models

## Targets have exactly one scope; resolution is tier-first then prorated
A RecruitmentDailyTarget must set exactly one of employee_id / designation_id / department_id (model saving guard throws DomainException). Resolution picks the most specific tier that has any target for the metric (employee > designation with employee_id null > department with employee_id and designation_id null; a tier is skipped when the recruiter lacks that attribute — never let a null attribute match null-scoped rows). Within the tier, an exact Daily/Weekly/Monthly period match is used as-is, otherwise the shortest configured period is prorated across the range.

## Timeline merges source tables; candidate_timeline_events only holds events with no other home
CandidateTimelineService reads stage history, interviews and feedback, offer history, recruitment_daily_activities and follow-ups directly from their own tables at read time. Never copy those into candidate_timeline_events: that would create a second source of truth, and daily activities feed performance and incentives. Write through record() only for events with no other table (notes, portal, referral, talent pool, duplicate decisions, self-scheduling, documents, and Phase 5 provider messages). Rows are append-only. forPortal() returns only visibility=candidate events plus candidate-facing stage labels, never remarks, actors or feedback. Mark an event Candidate-visible only if it contains nothing internal.
