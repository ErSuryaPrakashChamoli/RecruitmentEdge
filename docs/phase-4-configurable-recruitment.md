# Phase 4 — Configurable Recruitment Foundation

Developer reference for the Phase 4 modules: configurable hiring stages, pipeline templates,
talent pools, employee referrals, the candidate portal, candidate self-scheduling, the unified
candidate timeline and deterministic duplicate detection.

Short rules for agents and teammates also live in `.ai/rules/` (see `index.md`). This document
explains how the pieces fit together.

---

## 1. Design principles

- **Extend, don't duplicate.** Every module runs through the existing engines. Stage changes go
  through `StageTransitionService`, interview scheduling through `InterviewService`, SLA through
  `RecruitmentSlaService`, audit through `AuditLog`/`Auditable`, visibility through
  `HierarchyService`, alerts through `NotificationDispatchService`, bonuses through
  `RecruiterIncentiveCalculator`, and AI access through the existing tool registry.
- **Canonical milestones.** The `CandidateStage` enum stays as the analytics backbone. A
  configurable stage maps onto one milestone, so funnels, SLA legs, targets and incentives keep
  working for custom stages.
- **Snapshots, not live references.** A requisition keeps its own copy of the pipeline it was
  created with.
- **Services own business rules.** Filament pages and portal controllers stay thin. Domain rule
  violations throw `DomainException`, which the UI turns into a notification.

## 2. Database tables

| Table | Purpose |
| --- | --- |
| `recruitment_stages` | Stage library: name, code, type, **milestone**, SLA, flags, requirements, candidate label |
| `recruitment_stage_transitions` | Explicit allowed moves between library stages (`requires_remarks`) |
| `recruitment_pipeline_templates` | Reusable pipelines (`version`, `is_default`, `cloned_from_id`) |
| `recruitment_pipeline_template_stages` | Ordered stages of a template, with SLA and skippable overrides |
| `requisition_pipeline_stages` | **Immutable** per-requisition snapshot (settings, `transitions` JSON, `superseded_at`) |
| `talent_pools`, `talent_pool_memberships` | Pools and candidate membership, one row per pair, with added/removed metadata |
| `employee_referrals` | Referrals linked to an existing candidate, requisition, application and incentive calculation |
| `candidate_portal_accounts` | Candidate logins (separate from `users`), with a ULID `public_id` |
| `interview_availability_slots` | Interviewer availability, stored in UTC with a display `timezone` |
| `interview_scheduling_invitations` | "Pick a slot" invitations, reached by a signed link or the portal |
| `interview_slot_bookings` | Bookings; reschedules chain through `rescheduled_from_id` |
| `candidate_timeline_events` | Append-only timeline events that have no other authoritative table |

Columns added to existing tables (all nullable or defaulted, so existing rows are unaffected):

- `recruitment_requisitions`: `pipeline_template_id`, `pipeline_template_version`, `pipeline_applied_at`, `pipeline_applied_by`
- `candidate_applications`: `pipeline_stage_id`
- `candidate_stage_histories`: `previous_pipeline_stage_id`, `new_pipeline_stage_id`, `is_override`
- `candidates`: indexed `mobile_normalized`, `alternate_mobile_normalized`, `email_normalized`, `name_normalized` (backfilled by their migration)
- `audit_logs`: nullable polymorphic `actor` (non-staff actors, such as portal candidates)

Permissions are added by `2026_09_25_135221_grant_phase_four_permissions`. It only adds grants, so
roles customised in Administration → Roles keep what they have.

## 3. Models, services and events

| Area | Models | Service(s) | Events |
| --- | --- | --- | --- |
| Stages | `RecruitmentStage`, `RecruitmentStageTransition` | `StageConfigurationService` | — (audited via `Auditable`) |
| Templates | `RecruitmentPipelineTemplate(+Stage)`, `RequisitionPipelineStage` | `PipelineTemplateService` | — (`pipeline_applied` / `stages_updated` audit rows) |
| Transitions | `CandidateApplication`, `CandidateStageHistory` | `StageTransitionService` (`moveToStage`, `allowedNextStages`, `overridableStages`) | `CandidateStageChanged` |
| Duplicates | `CandidateDuplicateMatch` | `CandidateDuplicateDetector`, `CandidateIdentityNormalizer`, `DuplicateCandidateMatch` (DTO) | `DuplicateCandidateDetected`, `DuplicateOverrideApproved` |
| Timeline | `CandidateTimelineEvent` | `CandidateTimelineService` | — |
| Talent pools | `TalentPool`, `TalentPoolMembership` | `TalentPoolService` | `CandidateAddedToTalentPool`, `CandidateRemovedFromTalentPool` |
| Referrals | `EmployeeReferral` | `ReferralService`, `RecruiterIncentiveCalculator::calculateForReferralJoining()` | `ReferralSubmitted`, `ReferralStatusChanged` |
| Portal | `CandidatePortalAccount` | `CandidatePortalService` | `CandidatePortalProfileUpdated`, `CandidatePortalDocumentUploaded` |
| Scheduling | `InterviewAvailabilitySlot`, `InterviewSchedulingInvitation`, `InterviewSlotBooking` | `InterviewSchedulingService` (+ `InterviewService::cancel()`) | `InterviewSlotBooked`, `InterviewSlotCancelled`, `CandidateRescheduled` |

All new events implement `ShouldDispatchAfterCommit`. Listeners are auto-discovered, so don't
register them manually:

- `SyncReferralsWithApplication`
- `NotifyReviewersOfReferral`
- `NotifyRecruitersOfPortalDocument`
- `RecordDuplicateDecisionsOnTimeline`

## 4. Stage configuration and transitions

A stage has:

- a **type** (`StageType`)
- a **milestone** (`CandidateStage`)
- an optional SLA in hours
- behaviour flags: candidate or recruiter action, interview/offer/joining association, terminal,
  skippable, rejection or dropout allowed
- **requirements** (`StageRequirement`), checked before an application enters the stage
- candidate visibility and a candidate-facing label

The 18 canonical stages are seeded as **system stages**. They can be renamed, but their code and
milestone are fixed.

Transition rules are evaluated on the requisition snapshot:

1. From a terminal stage: no moves.
2. If the current stage lists explicit transitions, only those stages are allowed.
3. Otherwise the default forward rule applies: any later stage, up to and including the first
   non-skippable one.
4. A move must satisfy required remarks and the target stage's requirements.
5. **Override.** A user with `pipeline.override` can skip rules 1–4 by giving a reason. The history
   row gets `is_override = true` and an AuditLog `stage_override` row is written.
6. **Never backwards.** A move to an earlier milestone is always refused, even with an override.

**Pipeline board (Phase 4.1).** When the board is filtered to one requisition with a configured
pipeline, its columns are that requisition's own stages, one per `RequisitionPipelineStage`. Every
column accepts drops, and drops go through `moveToStage()`, so the pipeline's transition rules
apply. Across all requisitions, whose pipelines may differ, the board keeps the standard milestone
columns and a banner explains why. It never merges different stage lists.

The default forward rule treats a stage as skippable unless it is marked otherwise. All seeded
standard stages are skippable, so a template that uses them unchanged allows any forward move. Mark
the stages that must not be skipped as required, or configure explicit transitions.

`transitionTo(CandidateStage)` is still used by `InterviewService`, `OfferService`,
`CandidateJoiningService` and the Pipeline board's drag-and-drop. On a requisition with a pipeline,
the application's configured stage moves along with it automatically. Rejection, dropout and hold
remain statuses on top of the stage, as before.

## 5. Pipeline templates and versioning

Each template holds an ordered stage list with optional per-template SLA and skippable overrides.

**Validation**
- At least one stage, with no repeats.
- Newly added stages must be active.
- Milestones must never go backwards along the list.
- Terminal stages may only come last.

**Versioning**
- Any change to the stage list increments `version` and writes an audit row with the before and
  after stage codes.
- Only one template is the default at a time, and the default can't be deactivated.

**Applying a template to a requisition** (on create, from the requisition's *Hiring Pipeline* tab,
or through the backfill):
- It copies every stage setting, plus the allowed transitions restricted to that template's own
  stages, into `requisition_pipeline_stages`.
- Re-applying supersedes the old snapshot and remaps each application: same code if the stage still
  exists, otherwise by milestone.

## 6. Talent pools

Visibility (`TalentPool::scopeVisibleTo` and `TalentPoolPolicy`):

| Setting | Who can see the pool |
| --- | --- |
| Private | The owner and everyone above the owner |
| Team | Also everyone below the owner |
| Organization | Anyone with `talent-pools.viewAny` |

Users with `hierarchy.view-all` see every pool.

Rights:
- Editing, archiving and removing members need `talent-pools.manage`, and the owner must be the
  user or someone below them.
- Anyone who can see a Team or Organization pool can add candidates to it.
- Member lists and pickers only ever include candidates the viewer can see.
- Moving candidates between pools is one transaction (add + remove).

## 7. Employee referrals

1. **Submit** (any employee with `referrals.submit`, including the new `employee` role). Duplicate
   detection runs first. On a strong match the employee sees masked details and refers the
   *existing* candidate. Creating a new candidate anyway needs `candidates.override-duplicate` and
   an audited justification.
2. **Review** (`referrals.review`; nobody reviews their own referral). Reviewers can start a review,
   accept, reject (reason required) or close. **Accept** creates or links the application for the
   position.
3. **Pipeline sync.** Referral status follows the application: In Process → Selected → Offer
   Released → Joined / Did Not Join / Rejected. It only moves forward, and never out of Closed.
4. **Bonus.** On Joined, `ReferralJoining` incentive rules are priced for the **referrer** by the
   existing calculator. Approval and payout then follow the normal incentive workflow.

**Referral bonuses in the incentive engine (Phase 4.1).** Each calculation now records who it is
for: `recruiter_incentive_calculations.beneficiary_type` is `recruiter` or `employee_referrer`,
plus `employee_referral_id`. The calculator sets it from the rule's trigger. Recruiter scorecards,
team incentive totals, dashboard stats and recruiter statements use `forRecruiters()`, so referral
bonuses never show up there. Period statements have a *Statement* option (recruiter incentive or
referral bonus), and the calculations list has a *Type* column and filter.

Eligibility (`ReferralService::referralIncentiveIneligibility()`), all conditions required:
- the referral is marked eligible;
- the referred candidate actually joined through the linked application;
- the referrer is **not** that application's recruiter (they are paid through recruiter
  incentives);
- the referrer is still an active employee.

Every evaluation writes an audit row (`referral_incentive_evaluated` or
`referral_incentive_ineligible`, with the reason). Reviewers change eligibility through the
**Bonus eligibility** action. A reason is required, it writes a `referral_incentive_override` audit
row, and it is locked once the bonus is calculated; corrections from then on use the incentive
engine's own adjustments and reversals. A referral that doesn't join is marked Forfeited and never
priced. No separate referral incentive calculator exists.

The referral list page acts as the referral dashboard. It shows KPIs (mine, pending, in process,
selected, joined, rejected, conversion) and the referral leaderboard.

## 8. Candidate portal

- **Routes:** `/portal/*` in `routes/portal.php`, loaded from `routes/web.php`. The portal uses its
  own `candidate` guard and Blade views (`resources/views/portal`,
  `resources/views/components/portal`); it is not part of Filament.
- **Access:** a recruiter with `portal.manage` invites the candidate from the Candidate 360 page.
  The candidate gets an emailed temporary signed link (48 hours) to set a password. The link
  carries a fingerprint of the current password, so it works once. "Forgot password" gives the same
  response whether or not the email has an account.
- **Security:**
  - Every lookup is scoped to the signed-in candidate's own records; anything else returns 404.
  - Records are addressed only by public references (application code, ULIDs).
  - Rate limits: `portal-auth`, `portal-links`, `portal-actions`, plus a lock-out after 5 failed
    sign-ins per email and IP.
  - Profile updates are whitelisted (`CandidatePortalService::EDITABLE_PROFILE_FIELDS`).
  - Uploads are limited to PDF, Word, JPG and PNG up to 5 MB, and to an allowed set of document
    types. Files are stored privately under `candidate-documents/{candidate}`.
  - Candidates never see remarks, rejection reasons, feedback, scores or AI output. Stage labels
    come from `candidate_label`, and stages hidden from candidates read as "In review".
  - Portal actions are written to the timeline (candidate-visible) and to `AuditLog`, with the
    portal account recorded as the actor.
- **Features in this phase:** applications and status, interviews (confirm, request a reschedule),
  profile and communication preferences, document upload, and self-scheduling. Offers, joining and
  onboarding are left for later phases.

## 9. Self-scheduling

1. Staff with `interview-slots.manage` publish availability (Candidate Experience → Interview
   Slots). Slots are entered in a local timezone, stored in UTC, can be generated as a consecutive
   series, and can't overlap the same interviewer's other slots.
2. The application page's "Invite to self-schedule" creates an invitation and shows a temporary
   signed link. The invitation also appears in the candidate's portal.
3. **Booking** locks the slot row and re-checks status, expiry, the minimum lead time
   (`self_scheduling_min_lead_hours`) and capacity. It then creates the interview through
   `InterviewService::schedule()`. An application can hold only one active booking.
4. **Rescheduling** frees the old seat, books the new slot and reschedules the same interview,
   including a change of interviewer. **Cancelling** frees the seat and cancels the interview.
5. The hourly `interview-slots:expire` command marks lapsed slots as Expired.

## 10. Duplicate detection

`CandidateDuplicateDetector::detect()` returns `DuplicateCandidateMatch` results (match type,
confidence, matching fields, reason). Signals, strongest first:

| Signal | Confidence |
| --- | --- |
| Exact mobile | 100 |
| Exact email | 100 |
| Normalised mobile (+91, trunk 0, separators) | 95 |
| Normalised email (case, `+tag`, Gmail dots) | 90 |
| Alternate-mobile cross match | 85 |
| Same normalised name + last 7 mobile digits or same mailbox name | 70 |

A match of 85 or more is **strong** and blocks creating a new candidate without an audited
override. A name on its own never matches. Nothing is merged automatically. PAN or other national
IDs aren't stored, so they aren't used. AI duplicate intelligence (Phase 7) should add signals on
top of these, not replace them.

## 11. Permissions

| Permission | chro | vp_hr | manager | assistant_manager | recruiter | employee (new) |
| --- | :-: | :-: | :-: | :-: | :-: | :-: |
| pipeline.configure | ✓ | ✓ | | | | |
| pipeline.override | ✓ | ✓ | ✓ | | | |
| talent-pools.viewAny | ✓ | ✓ | ✓ | ✓ | ✓ | |
| talent-pools.manage | ✓ | ✓ | ✓ | ✓ | | |
| referrals.submit | ✓ | ✓ | ✓ | ✓ | ✓ | ✓ |
| referrals.review | ✓ | ✓ | ✓ | ✓ | ✓ | |
| candidates.override-duplicate | ✓ | ✓ | ✓ | | | |
| portal.manage | ✓ | ✓ | ✓ | ✓ | ✓ | |
| interview-slots.manage | ✓ | ✓ | ✓ | ✓ | ✓ | |

Every scoped resource has both a query scope and a policy: talent pools, referrals, slots and
portal accounts. Stage and template configuration is organisation-wide reference data.

## 12. SLA, audit and AI integration points

- **SLA:** stage and template `sla_hours` feed `RecruitmentSlaService::openPipelineStageBreaches()`.
  The existing hourly `notifications:dispatch-alerts` command sends a deduplicated "Stage SLA
  breached" alert. There is still only one SLA engine.
- **Audit:**
  - Automatic, via `Auditable`: stage, template, pool, membership, referral, slot and booking
    changes.
  - Explicit rows: `transitions_updated`, `stages_updated`, `pipeline_applied`, `stage_override`,
    `duplicate_override`, `portal_invited`, `portal_deactivated`, `portal_password_set`,
    `portal_uploaded`, `communication_preferences_updated`.
- **AI:**
  - `get_requisition_pipeline` now includes the configured pipeline, using the stage context from
    `toStageContext()`.
  - `get_candidate_timeline` returns the unified timeline.
  - `find_duplicate_candidates` returns confidence and reasons.
  - New read tool: `list_talent_pools`.
  - All are hierarchy-scoped like the existing tools.

## 13. Backward compatibility and data migration

- All schema changes only add columns or tables. Existing stage, status and history values are
  never rewritten.
- `php artisan recruitment:assign-default-pipelines` (idempotent) seeds the stage library and the
  default template if missing. It snapshots the default pipeline onto every requisition without one
  and points each application at the stage matching its current milestone. Historical stage rows
  keep a null pipeline-stage reference, meaning "before configurable pipelines". **Run it once
  after deploying**; it was run on the development database (6 requisitions).
- `RecruitmentReferenceDataSeeder` also seeds example custom stages (Technical Assessment, Aptitude
  Test, Group Discussion) and templates (High Volume, Technology, Campus, Leadership). It is
  idempotent.
- Requisitions without a snapshot and applications without `pipeline_stage_id` keep the old
  canonical behaviour everywhere.

## 14. Testing

Phase 4 feature tests:

| Area | Test files |
| --- | --- |
| Stages and templates | `StageBuilderTest`, `PipelineTemplateServiceTest`, `ConfiguredPipelineTransitionTest`, `PipelineConfigurationUiTest`, `AssignDefaultPipelinesCommandTest` |
| Duplicates and timeline | `DuplicateCandidateDetectorTest`, `CandidateTimelineTest` |
| Pools, referrals, scheduling | `TalentPoolTest`, `EmployeeReferralTest`, `InterviewSchedulingServiceTest`, `InterviewSlotsUiTest` |
| Portal and AI | `CandidatePortalTest`, `Ai/PhaseFourAiIntegrationTest` |
| Extended existing files | `RecruitmentSlaServiceTest`, `RolePermissionSeederTest` |

Pest helper functions are global, so give each one a file-specific name. The six acceptance
workflows were also run end to end against a throwaway MySQL database.

## 15. Phase 4.1 browser verification

A real-browser smoke test (Playwright + Chromium, run from outside the project against
`php artisan serve` on a throwaway MySQL database) covered 29 checks:
- stage builder;
- templates;
- a requisition's pipeline tab;
- the pipeline board, across all requisitions and scoped to one, including a stage move;
- talent pools;
- referrals;
- the duplicate warning with masked details;
- all 8 themes on Phase 4 pages;
- dark mode in the admin panel and the portal;
- recruiter permission restrictions (403 and hidden navigation);
- portal login, application view, interview confirmation and isolation;
- signed-link self-scheduling, including a tampered link.

No project test dependency was added.

## 16. Extension points for Phase 5 (Communication & Distribution)

- **Calendars:** bind a real `CalendarProviderInterface` (Google or Microsoft) in
  `AiServiceProvider`. `InterviewSchedulingService` already calls it after each booking and logs
  failures without undoing the booking. The `Held` slot status and `external_calendar_event_id` are
  reserved for two-step holds and sync.
- **Messaging:** send email, WhatsApp and SMS through the existing provider contracts. Record each
  sent message with `CandidateTimelineService::record()` (types Email, WhatsApp, Sms; source
  `Integration`), and check `CandidatePortalAccount::wantsChannel()` for opt-outs before sending.
- **Notifications:** subscribe to the Phase 4 events (after-commit) for confirmations, reminders
  and referral updates. Deliver through `NotificationDispatchService` until the Phase 6
  Notification Center exists.
- **Distribution:** candidates arriving from job boards should be created through duplicate
  detection (`strongMatches()` plus `recordOverride()`) and may be added to talent pools with a
  `TalentPoolMemberSource`.
