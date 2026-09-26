# Phase 8.3: Lifecycle Integrity — One Path per Hiring Fact

Engineering reference for Phase 8.3, built on the Phase 8.2 release (`7c4e945`). Security findings are in `docs/phase-8-3-security-review.md`; the commit sequence is in `docs/phase-8-3-commit-plan.md`.

## 1. Purpose

The Outcome Loop, Hiring Memory, Role DNA, Talent Signal, analytics, automation and the Copilot all learn from, or act on, recruitment lifecycle facts. The Phase 8.3 discovery found that the same fact could be created or changed through several uncontrolled paths:
- form saves;
- table actions;
- the Copilot;
- automation.

Some of those paths skipped rules, events or audit.

Phase 8.3 makes every material hiring fact change through **one authoritative service**. That service:
- authorises (permission and hierarchy);
- validates the rule;
- writes the change in a transaction, with its audit;
- announces the change after commit.

Every interface calls that service. UI, Copilot, automation and any future API cannot bypass it, because the model refuses the write.

## 2. Architecture

```
Filament · Copilot tools · Automation handlers · (future API)
                         │
                         ▼
Authoritative service  (authorise → validate → mutate → audit → persist → commit)
                         │  LifecycleGuard::allow() — the only scope in which a lifecycle attribute may change
                         ▼
Model (GuardsLifecycleAttributes) — refuses any other write with a LogicException
                         │
                         ▼ after commit (every App\Events class is ShouldDispatchAfterCommit)
Automation · Communication · Calendar · Outcome Loop · Hiring Memory · Notifications
```

| Hiring fact | Authoritative service | Guarded attributes |
|---|---|---|
| Application stage and status | `StageTransitionService` (`advance`, `moveToStage`, `transitionTo`, `reject`, `dropout`, `hold`, `reactivate`) | `current_stage`, `pipeline_stage_id`, `status`, reason ids |
| Application requisition, candidate, recruiter | `ApplicationAssignmentService` (`moveToRequisition`, `reassignRecruiter`) | `requisition_id`, `candidate_id`, `recruiter_id` |
| Interview | `InterviewService` (`schedule`, `reschedule`, `confirm`, `hold`, `cancel`, `markNoShow`, `complete`, `selectCandidate`) | `status`, `result`, `rejection_reason_id`, `scheduled_at`, `interviewer_id`, `round_number`, `candidate_application_id` |
| Interview feedback | `InterviewFeedbackService` (`submit`, `correct`, `lock`) | all content and version columns |
| Offer | `OfferService` (`create`, `moveTo`, `requestRevision`, `releaseRevision`, `cancelRevision`, `withdrawOpenOffers`) | `status`, `accepted_at`, `candidate_application_id`, `offer_code`, and **every term once released** |
| Joining | `CandidateJoiningService` (`createForAcceptedOffer`, `confirm`, `markJoined`, `markNoShow`, `markDropout`, `cancel`, `recordApplicationDropout`, milestones) | `status`, `actual_doj`, `confirmed_at`, `offer_id`, `candidate_application_id`, `dropout_reason_id` |
| Employee conversion | `EmployeeConversionService::convert` (`employees.convert` + hierarchy) | — (creates the employee) |
| Requisition status | `RequisitionApprovalService` | `status` |

Record creation is not guarded; factories and services create records normally. Two further rules:
- An architecture test allows only the services above to open the guard.
- Tests arrange preconditions with `lifecycleFixture()`.

**The one intentional low-level write:** `PipelineTemplateService` re-maps `pipeline_stage_id` with `saveQuietly()` when a pipeline template is re-applied. This is a structural re-mapping of configured stages, not a hiring fact.

## 3. Lifecycle rules

- **Stage moves.**
  - A user-, Copilot- or automation-initiated move to a canonical stage calls `StageTransitionService::advance()`.
  - On a requisition with a configured pipeline it goes through `moveToStage()`, so allowed transitions, non-skippable and terminal stages, and required remarks all apply.
  - Legacy applications with no pipeline keep the canonical forward-only rule.
  - `transitionTo()` stays reserved for domain services recording a fact that already happened.
- **Moving an application to another requisition.**
  - This is an explicit operation: it needs the `move` ability (`candidates.update` + `pipeline.transition` + hierarchy) and a reason.
  - The destination must be an Open requisition the actor can see, with no existing application for the candidate.
  - Nothing may be open on the application: no pending interview, offer or joining.
  - The canonical stage is kept and the configured stage is re-mapped.
  - The move is written to the stage history, audited (`application_moved`) and announced (`ApplicationMovedToRequisition`).
  - Ordinary edits can no longer change the requisition, candidate or recruiter.
- **Recruiter reassignment:** needs `candidates.reassign` on the application, and the new recruiter must be in the actor's team. It is audited (`application_reassigned`). The Copilot assign tool uses it.
- **Interviews.**
  - Cancel needs a reason. Both cancel and no-show go through the service, so calendar sync, candidate messaging and automation follow.
  - A finished interview cannot become a no-show.
  - The edit form cannot change application, interviewer or time; use Reschedule.
- **Feedback.**
  - Feedback is always attributed to the interview's assigned interviewer.
  - It may be submitted by that interviewer, or by a hiring/HR user managing the interview (`interviews.manage` in scope). The submitter is recorded.
  - Completing the interview (the round's decision) locks it.
  - A correction adds a new version with a reason; the original stays. Audit rows carry ids, recommendation and score, never the feedback text.
  - No "draft" state was introduced; the product had none.
- **Requisitions:** the requester (`created_by`) can never approve their own requisition. The service enforces this for every path, audits each attempt (`requisition_self_approval_blocked`), and the button is hidden.
- **Employee conversion:**
  - requires `employees.convert` in hierarchy scope;
  - `joining.confirm` or `users.manage` alone is not enough;
  - locks the candidate row (idempotent, and `employees.candidate_id` is unique);
  - is audited (`employee_converted`).
- **Hierarchy:** an employee can never report to themselves or to anyone in their own reporting line (`EmployeeObserver::updating`).

## 4. Rejection and dropout cascades

When an application becomes **Rejected** or **Dropout**, `ApplicationClosureCascade` runs in the same transaction:

| Open record | Result | Reason recorded |
|---|---|---|
| Interview (not completed, cancelled or no-show) | Cancelled via `InterviewService::cancel` | "Cancelled due to application rejection / dropout"; `InterviewCancelled.cause` = `application_rejected` / `application_dropout` |
| Offer (draft, initiated, released) | Withdrawn via `OfferService::moveTo` | "Withdrawn due to application rejection / dropout" (status history) |
| Accepted offer | Left accepted | Acceptance is a final fact |
| Joining (expected, confirmed) | Rejection → `Cancelled` (new minimal state); dropout → `Dropout` with the same reason | Joining remarks / reason |

Properties of the cascade:
- **Nothing is deleted.**
- **Idempotent:** only open items are touched.
- **No recursion:** none of the called services moves the application again.
- **Audited:** a `lifecycle_cascade` summary row.
- **Atomic:** a failing cascade rolls back the closure.

**Candidate communication.** Communication is not hard-coded into the closure. Interviews cancelled by a cascade are not messaged on their own: `SendCandidateCommunications` skips a `cause`. What the candidate hears about a rejection or dropout is decided by communication policy, meaning automation rules on `candidate.stage_changed`.

## 5. Offers

- **Eligibility:** `OfferService::offerBlocker()` / `eligibleApplications()` is the single rule. The application must be active and Selected (or further along the offer stages), with no other open offer. The service, the create-page picker and the Raise Offer action all use it.
- **Approval:** unchanged. `Initiated → Released` requires `offers.release`, and that is the approval gate. No separate approval engine was added.
- **Immutability:** from Released onward the offer's terms are guarded, along with its status, application and code:
  - compensation;
  - designation and location;
  - dates;
  - letter.
- **Revisions:** stored in `offer_revisions`, which has typed term columns.
  1. `requestRevision(reason)` — the offer must be Released. The first time, the released terms are recorded as revision 1; the proposed terms wait as a pending revision.
  2. `releaseRevision()` — needs `offers.release` in scope. The proposed terms become the offer's terms, the earlier release becomes Superseded, a history row is written, and `OfferRevisionReleased` is announced.
  3. `cancelRevision(reason)` — withdraws a pending revision.
  - An accepted offer is final and cannot be revised.
- **Audit:** Offer is now Auditable. Compensation and the letter body are logged as `[redacted]`, and revision audits name the changed terms without values. Figures remain visible only on the offer and its revisions, to people who may manage the offer.
- **Acceptance:**
  - the joining record is created inside the acceptance transaction (`CandidateJoiningService::createForAcceptedOffer`);
  - any other open offer on the application is withdrawn;
  - `OfferAccepted` is dispatched after commit (it used to fire before commit, through a listener that has been removed).

## 6. Joining anchor

The joining record is the completed-hire anchor for every calculation that claims an actual hire:

| Metric | Definition (Phase 8.3) | Where |
|---|---|---|
| **Filled openings** | Applications on the requisition whose joining record is marked **Joined**. An application at the Joined stage without one does not count. | `RecruitmentRequisition::filledOpeningsCount` and `withFilledOpeningsCount`: the requisitions list/edit page, position health, requisition metrics, the remaining openings in Copilot requisition tools |
| **Hiring Memory time to hire** | Days from the configured start point (Recruitment Settings) to the joining's actual date, the same definition as the Outcome Loop. The start point is recorded. Unknown without a joining, or when the start date is after the joining date. | `HiringMemoryService::captureHire` |
| Hiring Memory requisition-outcome hires | Applications with a Joined joining record | `captureRequisitionOutcome` |

Other time-to-hire and join-rate definitions are unchanged; see backlog P83-BACKLOG-003. Talent Rediscovery's "already hired" exclusion stays stage-based on purpose: it is a conservative exclusion, not a hire claim.

## 7. Events

- **Every** `App\Events` class dispatches after commit (architecture test).
- New events carry ids only:
  - `ApplicationMovedToRequisition`
  - `InterviewMarkedNoShow` (also the `interview.no_show` automation trigger)
  - `OfferRevisionReleased`
- `InterviewCancelled` gains an optional `cause`.

## 8. Idempotency (effectively-once business effects)

| Operation | Mechanism |
|---|---|
| Reject / dropout | A terminal application cannot be closed again; the cascade touches only open items |
| Interview cancel / no-show | Terminal interviews are refused |
| Offer accept / reject | Allowed-transition table; history row per change |
| Joining confirm / join | `guardActive`; one joining per application (unique) |
| Employee conversion | Candidate row lock plus the unique `employees.candidate_id` |
| Approved AI actions | `ActionExecutor` claims `Pending → Approved/Rejected` in one conditional update before running; a second approval is told "already decided" |
| Automation | The engine claims a Pending execution under a row lock; the job's unique lock now expires (`uniqueFor` 3600), so a lost job cannot block retries |
| Communications | Unchanged: unique `idempotency_key` |
| Outcome capture | Unchanged: `OutcomeService::record` is idempotent |

## 9. Queue topology

The shipped `docker-compose.yml` used to run one worker on `default` only. Two workers now consume every application queue:

| Worker | Queues | Timeout | Carries |
|---|---|---|---|
| `queue` | `communications`, `automation`, `default` | 120 s | Candidate messages, automation, in-app notifications |
| `queue-background` | `intelligence`, `integrations`, `default` | 300 s | AI and embeddings (knowledge indexing moved here from `default`), calendar and job-board APIs, Outcome Loop / Hiring Memory capture |

- `DB_QUEUE_RETRY_AFTER=330` is above the longest timeout, so a slow job is never handed to a second worker.
- The production runbook (`docs/phase-7-production-readiness.md` §4) matches.
- `QueueTopologyTest` fails if any job or queued listener targets an unconsumed queue.

## 10. `php artisan lifecycle:audit`

**Read-only.** Only bounded SELECTs; a test asserts that no write statement runs. It reports and never repairs.
- **Options:** `--from`, `--to` (application change date), `--requisition`, `--application`, `--limit` (default 50 per check).
- **Exit code:** non-zero when an ERROR is found.

| Level | Checks |
|---|---|
| ERROR (inconsistent under any schema) | Joining without an application; employee converted from a candidate with no Joined joining; released offer terms differ from the released revision; reporting cycle |
| WARNING (prevented from now on; older data may legitimately contain it — "may predate Phase 8.3") | Open interview, offer or joining on a closed application; more than one open offer; accepted offer without a joining; offer out before the application reached Selected; Joined stage without a Joined joining; feedback not by the assigned interviewer |
| INFO | Explicit moves recorded; closure cascades performed |

**Limitation:** requisition changes made by ordinary edits before Phase 8.3 were never recorded and cannot be detected; they are not fabricated. The dev database audits clean (0 errors, 0 warnings).

## 11. Outcome evaluation hardening

- Each chunk (200 rows) is one transaction, with a savepoint per record.
- Due status observations and separation re-checks eager-load `employee.separation`.
- A failing record is rolled back alone, logged with its id, left unrecorded (so the next pass retries it) and counted. `outcomes:evaluate` lists the failures and exits non-zero.
- The Outcome Loop was not redesigned; `record()` keeps its lock-and-insert idempotency.

Measured on MySQL with 3,000 hires (with employees) and 300 separations, on a fresh throwaway database:

| Pass | Before | After |
|---|---|---|
| First | 72.9 s, 50,854 queries | 49.7 s, 35,178 queries |
| Repeat | 1.7 s, 1,360 queries | 1.4 s, 762 queries |

**`observed_at` was not indexed.** No Phase 8.3 use case queries it. `EXPLAIN` of the dashboard's range filter shows a full scan of `hiring_outcomes_type_current` (≈13k rows, milliseconds); this is recorded as P83-BACKLOG-004.

## 12. Permissions

| Permission | Roles | Note |
|---|---|---|
| `employees.convert` (new) | VP HR, CHRO | Additive grant migration `grant_phase_eight_three_permissions` |

Every other check reuses existing permissions (`pipeline.transition`, `candidates.update`, `candidates.reassign`, `interviews.manage`, `offers.manage`, `offers.release`, `requisitions.approve`), always with the hierarchy.

## 13. Traceability

| Discovery finding | Decision | Implementation | Test | Browser | Status |
|---|---|---|---|---|---|
| Multiple lifecycle mutation paths | One path per fact | `LifecycleGuard` + model guards; services | `*LifecycleIntegrityTest`, `LifecycleArchitectureTest` | 1–17 | IMPLEMENTED |
| Application edit changes requisition/candidate/recruiter | D3 explicit move | `ApplicationAssignmentService`; form locks | `ApplicationLifecycleIntegrityTest` | 5 | IMPLEMENTED |
| Board/Copilot/automation bypass pipeline | One rule | `advance()` | `ApplicationLifecycleIntegrityTest` | — | IMPLEMENTED |
| Offer CTC editable after release | D2 immutable + revisions | Offer guard, `offer_revisions` | `OfferLifecycleIntegrityTest` | 6, 7 | IMPLEMENTED |
| Offers from any stage | Central rule | `offerBlocker` | `OfferLifecycleIntegrityTest` | — | IMPLEMENTED |
| Offers unaudited | Audit with redaction | Offer Auditable | `OfferLifecycleIntegrityTest`, `LifecycleSecurityTest` | 15 | IMPLEMENTED |
| Feedback policy / attribution | D5 | `InterviewFeedbackService`, policy, lock, versions | `InterviewLifecycleIntegrityTest` | 10, 11 | IMPLEMENTED |
| Interview cancel/no-show direct writes | Via service | `markNoShow`, table actions | `InterviewLifecycleIntegrityTest` | 17 | IMPLEMENTED |
| Rejection/dropout leave items open | D1 cascade | `ApplicationClosureCascade` | `ApplicationClosureCascadeTest` | 3, 4 | IMPLEMENTED |
| Filled openings by stage | D7 joining anchor | `filledOpeningsCount` | `RequisitionLifecycleActionsTest`, `JoiningAnchorTest` | 13 | IMPLEMENTED |
| Hiring Memory time to hire | D7 | `captureHire` | `JoiningAnchorTest`, `HiringMemoryTest` | 12 | IMPLEMENTED |
| `OfferAccepted` before commit | After commit | Event + in-transaction joining | `OfferLifecycleIntegrityTest`, architecture test | — | IMPLEMENTED |
| Compose worker serves only default | D8 | Two workers | `QueueTopologyTest` | 17 | IMPLEMENTED |
| AI approval runs twice | Atomic claim | `ActionExecutor::claim` | `SideEffectIdempotencyTest` | 16 | IMPLEMENTED |
| Automation job `uniqueFor` | Expiring lock | `uniqueFor` 3600 | `SideEffectIdempotencyTest`, `AutomationEngineTest` | — | IMPLEMENTED |
| Conversion permission | D4 `employees.convert` | Policy + service | `JoiningLifecycleIntegrityTest` | 8, 9 | IMPLEMENTED |
| Self-approval | D6 | Service guard | `RequisitionLifecycleIntegrityTest` | 1, 2 | IMPLEMENTED |
| Hierarchy cycles | Authorisation invariant | `EmployeeObserver::updating` | `JoiningLifecycleIntegrityTest` | — | IMPLEMENTED |
| Compensation in audit | Redact | `auditRedactedAttributes` | `LifecycleSecurityTest` | 15 | IMPLEMENTED |
| Outcome evaluation 31 s / abort on one record | Batching + isolation | `OutcomeEvaluator::each` | `OutcomeEvaluationResilienceTest` | — | IMPLEMENTED |
| `observed_at` index | Only with evidence | — | — | — | BACKLOG (P83-BACKLOG-004) |
| Conflicting metric definitions | Out of scope beyond the joining anchor | — | — | — | BACKLOG (P83-BACKLOG-003) |
| Separation does not revoke access | Next phase | — | — | — | BACKLOG (P83-BACKLOG-001) |
| Multi-tenancy, API, imports, retention, MFA | Non-goals | — | — | — | NON-GOAL / future phase |

## 14. Known limitations

- **Existing data is reported, not repaired.** `lifecycle:audit` shows what older data contains. Repairing anything needs a separate, approved step.
- **Offer revisions** apply to Released offers only. An accepted offer is final: to change it, withdraw it and issue a new one.
- **Feedback** has no draft state, and is locked by interview completion (the round decision), not by the overall hiring decision.
- **The two stage models remain** (canonical enum and configured stages). Unifying them is future work.
- **Separation** does not revoke system access (P83-BACKLOG-001).
- **Analytics definitions** other than filled openings and Hiring Memory time to hire are unchanged (P83-BACKLOG-003).
