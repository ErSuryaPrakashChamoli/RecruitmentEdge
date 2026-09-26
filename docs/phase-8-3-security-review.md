# Phase 8.3: Security Review

This review covers the lifecycle-integrity work on top of Phase 8.2 (`7c4e945`). It is a focused, read-and-test review, not a claim of compliance with any law or regulation. Test files are under `tests/Feature/Lifecycle` and `tests/Unit/Lifecycle` unless stated otherwise.

## Findings and controls

**L1. Lifecycle facts could be changed through any form save, table action, tool or automation handler.**
- **Risk:** High (integrity of every downstream system).
- **Control:**
  - `GuardsLifecycleAttributes` on the six hiring-fact models, with `LifecycleGuard` scopes opened only by the authoritative services.
  - An architecture test lists the only services allowed to open the guard.
- **Verification:** per-model tests showing that a direct write throws `LogicException`; `LifecycleArchitectureTest`.
- **Remaining:** `PipelineTemplateService`'s structural `saveQuietly` re-map of `pipeline_stage_id` is a documented exception.

**L2. Mass assignment and IDOR through edit forms (application requisition/candidate/recruiter, joining offer/application, interview application/interviewer/time).**
- **Risk:** High.
- **Control:**
  - Those fields are disabled on edit, so they are not dehydrated.
  - The model guard refuses the write if a request is forged.
  - Explicit operations check scope on both sides: the destination requisition must be visible, and the new recruiter must be in the actor's team.
- **Verification:** `ApplicationLifecycleIntegrityTest` (disabled fields, invisible and closed destinations refused, Manager A/B); `InterviewLifecycleIntegrityTest`.
- **Remaining:** none.

**L3. Pipeline rules bypassed by the canonical board, the Copilot move tool and the automation move action.**
- **Risk:** Medium.
- **Control:** `StageTransitionService::advance()` applies the configured pipeline's rules.
- **Verification:** `ApplicationLifecycleIntegrityTest` (mutation-checked on the Copilot tool).
- **Remaining:** none.

**L4. Released offer terms, including CTC, editable and unaudited.**
- **Risk:** High (financial).
- **Control:**
  - Terms are guarded from release onward.
  - Changes go through revisions: a reason is required, `offers.release` is needed in scope, and history is kept.
  - Offer is Auditable with compensation redacted.
- **Verification:** `OfferLifecycleIntegrityTest`; `LifecycleSecurityTest` (cross-hierarchy releaser refused); browser checks 6 and 7.
- **Remaining:** none.

**L5. Offers raised on unselected applications, or several open at once.**
- **Risk:** Medium.
- **Control:** a single eligibility rule, used in the service and in every entry point.
- **Verification:** `OfferLifecycleIntegrityTest`.
- **Remaining:** none.

**L6. Compensation leaking through the audit log to `audit.view` holders.**
- **Risk:** Medium.
- **Control:**
  - The `Auditable` trait redacts values for `auditRedactedAttributes()`, so offer compensation and the letter body are logged as `[redacted]`.
  - Revision audits carry changed term names only.
  - No new permission was needed: figures are visible only on the offer and its revisions, through the offer policy.
- **Verification:** `LifecycleSecurityTest` (no figure in any audit row, mutation-checked); browser check 15.
- **Remaining:** candidate PII in older audit diffs (discovery backlog).

**L7. Interview feedback attributed to arbitrary employees; no policy; editable after the decision.**
- **Risk:** Medium (evidence integrity: feedback feeds Talent Signal and hiring snapshots).
- **Control:**
  - `InterviewFeedbackService`: attribution fixed to the assigned interviewer; only the interviewer or a manager in scope may submit.
  - Feedback is locked at completion.
  - Corrections are versioned and audited without the feedback text.
  - `InterviewFeedbackPolicy`: no update or delete.
- **Verification:** `InterviewLifecycleIntegrityTest` (authorisation mutation-checked); `LifecycleSecurityTest`; browser checks 10 and 11.
- **Remaining:** none.

**L8. Requisition self-approval.**
- **Risk:** Medium.
- **Control:** refused in `RequisitionApprovalService` for every path, and each attempt is audited.
- **Verification:** `RequisitionLifecycleIntegrityTest` (mutation-checked); browser checks 1 and 2.
- **Remaining:** requisitions created by a user without an employee record have no requester to compare.

**L9. Employee conversion needing only joining access.**
- **Risk:** Medium (creates an employee record).
- **Control:** `employees.convert` plus hierarchy, enforced in the policy and the service; idempotent and audited.
- **Verification:** `JoiningLifecycleIntegrityTest` (a user with `users.manage` and joining access is refused; mutation-checked); browser checks 8 and 9.
- **Remaining:** none.

**L10. Reporting-hierarchy cycles (the hierarchy is the access boundary).**
- **Risk:** Medium.
- **Control:** `EmployeeObserver::updating` refuses a manager inside the employee's own reporting line; both UIs exclude such people.
- **Verification:** `JoiningLifecycleIntegrityTest` (mutation-checked).
- **Remaining:** none.

**L11. Approved AI actions executing twice (double click, two approvers).**
- **Risk:** Medium (a duplicate email, rejection or assignment).
- **Control:** an atomic claim in `ActionExecutor` before execution. The Phase 8.1 sanitiser and egress path are unchanged.
- **Verification:** `SideEffectIdempotencyTest` (mutation-checked); browser check 16.
- **Remaining:** none.

**L12. Replay of queue jobs.**
- **Risk:** Low.
- **Control:**
  - The automation engine claims executions under a lock, and its unique lock now expires.
  - Communications keep their idempotency keys.
  - Outcome recording is idempotent.
- **Verification:** `AutomationEngineTest` (duplicate delivery); `SideEffectIdempotencyTest`.
- **Remaining:** none.

**L13. Side effects observable before commit (`OfferAccepted`).**
- **Risk:** Medium.
- **Control:** every event dispatches after commit (architecture test), and the joining record is created in-transaction.
- **Verification:** `OfferLifecycleIntegrityTest` (rolled-back acceptance is never heard; mutation-checked).
- **Remaining:** none.

**L14. Lifecycle audit visibility.**
- **Risk:** Low.
- **Control:**
  - `lifecycle:audit` is a CLI command and read-only.
  - Its output carries record codes and ids only: no names, contacts or pay.
- **Verification:** `LifecycleAuditTest` (no write statement); browser check 14.
- **Remaining:** none.

## Privacy (Phase 8.1 boundary)

- **Copilot tools** that move, reject or assign now call the same services, still returning codes only. The 49-tool contract test and the whole privacy suite pass, including block-mode egress in the test environment.
- **New events** carry ids only, and no compensation.
- **Cascade cancellations** are not messaged to the candidate on their own; closure communication is policy-driven.
- **Feedback text** never enters the audit log.

## Preserved controls

Phase 8.1 privacy and Phase 8.2 Outcome Loop semantics are unchanged:
- the joining anchor;
- Unknown versus Not Observed;
- status observation;
- separation;
- corrections;
- learning.

Browser smokes pass: 8.2 20/20, 8.1 12/12, 7 20/20 and 6 24/24.

## Secrets

- No key appears in any file, test or commit.
- The smokes used throwaway databases, an unreachable fake provider and a fake key; the databases were dropped afterwards.
- `.env` is untouched.
