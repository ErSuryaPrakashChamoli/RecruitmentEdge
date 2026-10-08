# Phase 8.7 Decision Record: Platform Reliability, Queue, Automation & Communications Integrity

**Status:** PROPOSED. Nothing here is approved or implemented.

**Baseline:** HEAD `dcff76e`. The evidence is in `phase-8-7-discovery.md` (§ references), `phase-8-7-security-review.md` (SEC-87-xx) and `phase-8-7-performance.md` (PF-87-xx).

The 30 decisions fall into two groups:

- **Product owner decisions (13):** 007, 008, 009, 010, 012, 015, 019, 022, 024, 025, 027, 028, 030. Each one changes behaviour that candidates, recruiters or payroll can see, or sets a policy.
- **Engineering defaults (17):** 001, 002, 003, 004, 005, 006, 011, 013, 014, 016, 017, 018, 020, 021, 023, 026, 029. Each one hardens the system without changing business behaviour. They take effect only when the phase is approved.

**Every decision below:**
- leaves Outcome, Role DNA, Hiring Memory, lifecycle and identity semantics unchanged;
- repairs no historical data;
- uses additive migrations only.

---

# PART A: PRODUCT OWNER DECISIONS

### D8.7-007: Communication delivery contract
- **Current behaviour:**
  - At most one attempt per row.
  - A crash while Sending marks the row Failed and does not resend.
  - Five retries over about 42 minutes, then Failed.
  - No resend UI and no fallback channel.
  - A log or array mailer still marks the row Sent.
- **Evidence:** §13; SendCommunicationJob.php:120-143; CommunicationService.php.
- **Risk:** Messages are lost silently when a worker crashes or an outage outlasts the retries. Staff believe mail went out when it went to the log.
- **Options:**
  - (a) keep at-most-once and add an operator resend;
  - (b) at-least-once, with a provider idempotency reference;
  - (c) (a) plus a status of *"Not delivered externally"* for log or array transports.
- **Recommendation:** (a) + (c). Keep at-most-once, because a duplicate message to a candidate is worse than a visible failure. Add a "Resend" action (permission `communications.send`, audited). Report non-external delivery honestly.
- **Implementation impact:** job, a new status or flag, a UI action.
- **Migration impact:** none, or a nullable flag.
- **Test impact:** crash then Failed; resend creates a new row with a new key; log-mailer status.
- **Rollback impact:** code only.

### D8.7-008: Communication template version at send time
- **Current behaviour:** content is rendered and stored at queue time. A template edited or archived later does not affect queued messages.
- **Evidence:** §14; CommunicationService.php:62, 87-88.
- **Risk:** A message queued before an urgent wording fix is still sent with the old wording. The upside is that what was queued is exactly what was sent.
- **Options:**
  - (a) keep queue-time rendering (current);
  - (b) re-render at send time;
  - (c) keep (a), and block the send if the template was archived after queueing.
- **Recommendation:** (c). Stored content stays authoritative and reproducible; archiving acts as a "stop sending" switch.
- **Implementation impact:** a template status check in the job.
- **Migration impact:** none. The D8.6-023 version foreign key is complementary.
- **Test impact:** edit after queue sends the old text; archive after queue blocks the send.
- **Rollback impact:** code only.

### D8.7-009: Automation execution version semantics
- **Current behaviour:** pending and delayed executions run the version snapshotted when they were triggered. An edit does not cancel them; only archive does.
- **Evidence:** §12; AutomationEngine.php:436; AutomationRuleService.php:57-81.
- **Risk:** An admin corrects a faulty rule, but its already-scheduled runs still execute the faulty version.
- **Options:**
  - (a) keep (version-at-trigger);
  - (b) cancel pending runs on edit, and re-trigger under the new version at the next sweep;
  - (c) on edit, the admin chooses whether pending runs keep the old version or are cancelled.
- **Recommendation:** (c), defaulting to cancel. The choice is audited with a reason (aligns with D8.6-021).
- **Implementation impact:** rule service and edit UI.
- **Migration impact:** none.
- **Test impact:** edit with pending runs, both choices.
- **Rollback impact:** code only.

### D8.7-010: Automation authorization at execution time
- **Current behaviour:** the owner is re-checked at scope level. The record's scope is matched only at trigger time. Per-action permissions are not checked.
- **Evidence:** SEC-87-05; AutomationEngine.php:87, 433-481.
- **Risk:** A delayed run acts on a record that has left the owner's scope.
- **Options:**
  - (a) re-match scope and record visibility; skip with an audit if either fails;
  - (b) additionally require the owner to hold each action's permission (for example `candidates.move` for move_stage);
  - (c) keep as is.
- **Recommendation:** (a) + (b).
- **Product impact:** some scheduled automations will now be **skipped**. That is visible to rule owners on the execution list.
- **Implementation impact:** engine `perform`, the action registry declaring permissions.
- **Migration impact:** none.
- **Test impact:** scope change, lost permission, visibility loss.
- **Rollback impact:** code only.

### D8.7-012: Failed-job recovery and retention
- **Current behaviour:** `failed_jobs` stores the full payload and trace, is never pruned and has no UI.
- **Evidence:** §21; SEC-87-01, 02.
- **Risk:** Personal data retained indefinitely; failures invisible.
- **Options:** a retention window of (a) 7, (b) 30 or (c) 90 days; plus a read-only admin view with redacted payloads.
- **Recommendation:** 30 days, pruned daily. An admin "Queue health" view (permission `settings.manage`) that shows class, queue, time and a redacted exception, with a retry action. The legal retention decision stays with 8.8.
- **Implementation impact:** schedule entry, page.
- **Migration impact:** none.
- **Test impact:** prune schedule; redaction; authorization.
- **Rollback impact:** pruned rows cannot be recovered.

### D8.7-015: Actor attribution contract for asynchronous work
- **Current behaviour:** `user_id` is NULL for every worker or scheduler audit row, with no actor kind. Under the sync queue the triggering human is recorded instead, which is inconsistent.
- **Evidence:** §24; AuditLog.php:46.
- **Risk:** "Who did this?" cannot be answered for automation, cron or AI results.
- **Options:**
  - (a) a system actor for everything asynchronous;
  - (b) an explicit actor context: automation records the **rule owner as `on_behalf_of`** and the actor kind `automation`; the scheduler records `scheduler`; queued AI records the requester as `on_behalf_of`;
  - (c) keep as is.
- **Recommendation:** (b). Never attribute automation to the owner *as the actor*: the owner did not perform the action, the automation did on their authority.
- **Implementation impact:** an `AuditLog` actor context set by jobs, the engine and commands.
- **Migration impact:** additive `actor_kind`, `on_behalf_of_user_id` (coordinate with D8.6-024).
- **Test impact:** each asynchronous path records the correct kind and principal; the sync and queued paths agree.
- **Rollback impact:** the columns remain; code only.

### D8.7-019: Provider failure policy
- **Current behaviour:** retry on 429 or 5xx, then Failed. No fallback channel, no circuit breaker, no throttle.
- **Evidence:** §15-16.
- **Risk:** During an outage every message burns its retries, then fails; a Failed message is never retried again.
- **Options:**
  - (a) keep, and add alerting (D8.7-028);
  - (b) a circuit breaker that pauses the channel while the provider is down, holding messages as Queued;
  - (c) fall back to another channel (for example SMS when WhatsApp fails).
- **Recommendation:** (a) + (b). (c) is rejected, because consent differs per channel.
- **Implementation impact:** a provider health cache and job release.
- **Migration impact:** none.
- **Test impact:** outage holds messages, recovery drains them.
- **Rollback impact:** code only.

### D8.7-022: Automation conflict handling
- **Current behaviour:** no coordination. Two rules can act on the same record in the same window, limited only by the message cap of 3 per day, which is not locked.
- **Evidence:** §11.
- **Risk:** Contradictory or duplicate candidate messages; the cap can be exceeded under concurrency.
- **Options:**
  - (a) lock the cap check per candidate;
  - (b) a per-candidate lock during execution;
  - (c) rule priority groups, where the first match wins.
- **Recommendation:** (a) now, as an engineering fix. (c) goes to the backlog as a product feature.
- **Implementation impact:** a cache or database lock around the counters.
- **Migration impact:** none.
- **Test impact:** concurrent executions respect the cap.
- **Rollback impact:** code only.

### D8.7-024: Candidate or employee state change while queued
- **Current behaviour:** a queued message is sent regardless of later opt-out, withdrawal, rejection, hire or cancellation. Listener messages use the stale model.
- **Evidence:** SEC-87-03, 04; §13.
- **Risk:** Consent violations and misinformation.
- **Options:** which changes cancel a queued message:
  - (1) opt-out or consent withdrawn: **always**;
  - (2) the application closes (rejected, withdrawn, dropped);
  - (3) the subject is no longer current (interview cancelled or moved, offer withdrawn);
  - (4) the candidate was hired (joined) for non-joining messages.
- **Recommendation:** block (1), (2) and (3) at send time, moving the row to Blocked with a reason. (4) applies only to recruitment-stage templates.
- **Implementation impact:** the job's claim; listeners re-read.
- **Migration impact:** none.
- **Test impact:** each state.
- **Rollback impact:** code only.

### D8.7-025: Metric registry enforcement for scheduled and asynchronous consumers
- **Current behaviour:**
  - `RecruiterDailyMetricsService` computes raw counts that feed performance, alerts, leaderboards and **incentive actuals**.
  - It dates offers by `offer_date`, while the governed metric uses the first Released date.
  - The cited architecture guard test does not exist.
- **Evidence:** §30.
- **Risk:** Two definitions of "offers" coexist, and incentive inputs diverge from the governed metric.
- **Options:**
  - (a) add the guard and inventory only; accept the bypasses with documentation;
  - (b) migrate the recruiter daily metrics to the registry (a **new metric version**; incentive and performance values change from an effective date, not retroactively);
  - (c) leave as is.
- **Recommendation:** (a) in 8.7. (b) only with explicit product and payroll approval and a versioned effective date (stop conditions 11 and 14).
- **Implementation impact:** (a) a test; (b) a service and the engine.
- **Migration impact:** none.
- **Test impact:** architecture test.
- **Rollback impact:** code only.

### D8.7-027: Scale thresholds and operational limits
- **Current behaviour:** fixed caps are 200 requisitions for refresh and radar, 500 joining or offer rows, 200 due executions per 5 minutes, and 500 per rule per day. Queue driver is database; one process per worker.
- **Evidence:** PF-87-01 … 10; DQ-87-01.
- **Risk:** Correctness failures above the caps (the radar), and throughput limits.
- **Options:**
  - (a) remove the correctness caps (chunk everything) and keep the throughput caps as configuration;
  - (b) (a) plus a supported-scale statement ("up to 100k candidates, 500 open requisitions on the database queue; beyond that, Redis plus additional workers");
  - (c) migrate to Redis now.
- **Recommendation:** (b).
- **Implementation impact:** commands and radar.
- **Migration impact:** an optional `jobs` index.
- **Test impact:** radar above the cap.
- **Rollback impact:** code only.

### D8.7-028: Alerting and escalation policy for platform failures
- **Current behaviour:** no alerts on failed jobs, stuck rows, scheduler failure or provider outage.
- **Evidence:** §22.
- **Options:**
  - (a) in-app alert to `settings.manage` holders;
  - (b) (a) plus email to an operations address;
  - (c) external monitoring only.
- **Recommendation:** (a), plus a health endpoint for external monitoring. Thresholds:
  - any `failed_jobs` in the last hour;
  - oldest job older than 15 minutes;
  - more than 0 rows stuck longer than 30 minutes;
  - a missed scheduler heartbeat.
- **Implementation impact:** a command and notification.
- **Migration impact:** none.
- **Test impact:** thresholds.
- **Rollback impact:** code only.

### D8.7-030: Phase 8.7 release criteria
- **Recommendation:** `phase-8-7-discovery.md` §46, including fixing the flaky test and deciding D8.6-030 (the SEC-1 hotfix deployment).
- **Needs approval of:** whether 8.6 must be implemented and frozen before 8.7 starts. **Recommended: yes**, or at least its `audit_logs` migration.

---

# PART B: ENGINEERING DEFAULTS

### D8.7-001: Canonical queue priority model
- **Current:** 5 queues across 2 workers; `default` is consumed by both workers and receives the auth mails.
- **Risk:** Auth mails sit behind message bursts; queue names can be overridden by environment variables with no guard.
- **Recommendation:**
  - Keep the 5 queues.
  - Give each class of work an explicit queue:
    - add `notifications` for in-app alerts and auth mails, consumed first by `queue`;
    - `default` is reserved and should stay empty.
  - Add a test asserting every queued class names a consumed queue.
- **Impact:**
  - Implementation: configuration and Filament notification routing.
  - Migration: none.
  - Tests: topology test.
  - Rollback: drain `notifications` first.

### D8.7-002: Worker isolation boundaries
- **Current:** one process per worker; strict priority means communications can starve automation.
- **Recommendation:**
  - `queue`: communications, notifications.
  - `queue-automation`: automation.
  - `queue-background`: intelligence, integrations.
  - Or, if a container can't be added, `--sleep` tuning plus 2 processes.
- **Impact:**
  - Implementation: compose and runbook.
  - Tests: topology test.
  - Rollback: compose only.

### D8.7-003: Standard retry policy
- **Recommendation:**

  | Class of work | Tries |
  |---|---|
  | Critical external (messages) | 5 |
  | Integrations | 5 |
  | Internal writes (automation, handoff, listeners) | 3 |
  | AI | 2 |
  | Notifications | 3 |

  Every job and listener declares `tries` and `failed()`. Validation, authorization and missing-record errors never retry: they fail immediately via `$this->fail()`.
- **Impact:** jobs and listeners; tests per class.

### D8.7-004: Standard backoff policy
- **Recommendation:** exponential backoff, never zero:

  | Class of work | Backoff (seconds) |
  |---|---|
  | Listeners | 10, 60 |
  | Notifications | 10, 60 |
  | Handoff | 30, 120 |
  | Messages | unchanged |
  | Calendar | unchanged |

  The AI services **rethrow** retryable provider errors so that `tries` takes effect (fixes P7-BACKLOG-005 and P82-BACKLOG-007).
- **Impact:** code; tests for backoff presence.

### D8.7-005: Job idempotency contract
- **Rule:** every job with an external or material effect must have either a unique business key enforced by the database, or a locked state claim.
- **Recommendation:**
  - Make the calendar and distribution jobs unique per subject and re-check state.
  - Lock offer and interview transitions (`lockForUpdate` plus a fresh status).
  - Deterministic keys for manual and AI sends.
  - Reschedule key includes a sequence number.
  - Deduplicate followup and timeline actions.
  - Unique current snapshot rows. Lock-only is preferred; a constraint would need a de-duplication data change, which requires separate approval.
  - Atomic Hiring Memory capture.
- **Impact:** multiple services; concurrency tests.

### D8.7-006: Event after-commit contract
- **Current:** all events are after-commit ✔.
- **Recommendation:**
  - Keep the rule and make it an architecture test: every event implements `ShouldDispatchAfterCommit`.
  - Model-event dispatches (AiDocument, AiKnowledgeArticle) use `->afterCommit()`.
  - Queued listeners take ids, or the events use `SerializesModels`.
- **Impact:** code and an architecture test.

### D8.7-011: Scheduler overlap policy
- **Recommendation:**
  - Every recurring command gets `withoutOverlapping(<runtime-sized minutes>)` and `onOneServer()`.
  - Heavy commands use `runInBackground()` or queue per-item jobs.
  - Per-item try/catch in the daily commands.
  - A single scheduler is documented; the runbook forbids running cron alongside `schedule:work`.
- **Impact:** routes/console.php, commands; a schedule test.

### D8.7-013: Dead-letter and terminal failure policy
- **Recommendation:**
  - Every business-bearing job's `failed()` sets a terminal status on its business row with a reason, and audits it. This covers the handoff, listeners, calendar and distribution jobs.
  - Sweepers mark rows stuck in Sending or Queued (communications) and Approved (AI calls) as Failed after a threshold.
  - Stuck Running automation executions are re-queued once, if no action row started; otherwise they are marked Failed.
- **Impact:** code; tests per sweeper.

### D8.7-014: Correlation id / trace contract
- **Recommendation:**
  - The scheduler and `processDue` set Context `request_id = sched:<uuid>` per command run.
  - `automation_executions` and `candidate_communications` store `origin_request_id`.
  - The audit UI filters by it (with D8.6-024).
  - This supersedes P86-BACKLOG-003.
- **Impact:**
  - Migration: additive nullable columns.
  - Tests: propagation into jobs and commands.

### D8.7-016: Execution-time authorization contract
- **Rule:** a material asynchronous action re-checks, at execution:
  - that the principal is still permitted (`StaffAccessService::permits`);
  - the required permission;
  - hierarchy visibility of the target;
  - the target's current state.
- **Applies to:** automation (see D8.7-010), escalations (SEC-87-06), and AI jobs (skip with audit, SEC-87-08).
- **Exemption:** system maintenance commands, which are declared as such.
- **Impact:** tests for each path.

### D8.7-017: Queue payload privacy contract
- **Rule:** payloads contain ids and non-sensitive scalars only. No compensation, contact details, bodies or tokens.
- **Recommendation:**
  - Implement ids-only listener payloads (SEC-87-01).
  - Apply `ShouldBeEncrypted` to message, offer and auth notification paths (SEC-87-02).
  - Add a test that serializes every queued class with a factory-built subject and asserts forbidden keys are absent.
- **Impact:** code and tests.

### D8.7-018: AI asynchronous privacy contract
- **Current:** the egress guard applies to every asynchronous AI call ✔.
- **Recommendation:**
  - Keep it, and add a test asserting every AI job resolves `AiGateway`, never a raw provider.
  - AI job logs carry sanitized messages only; provider errors are never written to `ai_status_message` verbatim.
- **Impact:** tests.

### D8.7-020: Deployment and worker drain policy
- **Recommendation:**
  - Set `stop_grace_period: 330s` on the worker services.
  - `exec` via a user-switch tool (`setpriv` or `gosu`), not `su -c`.
  - Add `DB_QUEUE_RETRY_AFTER=330` to `.env.example`, and a boot or health check that `retry_after` exceeds the worker timeout.
  - Deployment order: `queue:restart` → wait for drain → migrate → start the new workers.
  - When payload shapes change, drain the affected queues first.
- **Impact:** Docker, runbook; a topology test for `.env.example`.

### D8.7-021: Queue observability
- **Recommendation:**
  - A read-only admin "Queue health" page (see D8.7-012) showing depth and oldest-job age per queue, `failed_jobs` (redacted), stuck communications, executions and AI calls, and a scheduler heartbeat (last run per command, cached).
  - A `/health/queue` JSON endpoint behind authentication.
- **Impact:** page and endpoint.

### D8.7-023: Stale ownership handling
- **Current:** the 8.4 handoff pauses rules and reassigns actions ✔; escalations ignore it; the handoff has no `failed()`.
- **Recommendation:**
  - Add `failed()` to the handoff job, with an alert (D8.7-028).
  - Escalations check the owner.
  - `processDue` pauses any rule whose owner is not permitted (a belt-and-braces sweep).
- **Impact:** code and tests.

### D8.7-026: Outcome / Hiring Memory asynchronous guarantees
- **Recommendation:**
  - Outcome recording stays as is (safe).
  - Hiring Memory capture moves into one transaction, so a retry completes a partial write.
  - The offer path re-reads the offer.
  - Health and signal refresh take a lock per requisition.
  - Risk Radar resolution is limited to the requisitions scanned in the run, and the scan covers all open requisitions in chunks (DQ-87-01).
- **No semantic change.**
- **Impact:** services; tests including radar above 200 requisitions.

### D8.7-029: Operational runbook requirements
- **Recommendation:** `docs/runbooks/queue-operations.md` covering discovery §45 items 1–8. Documentation only.

---

## Summary

| Group | Count | IDs |
|---|---|---|
| Product owner decisions | 13 | 007, 008, 009, 010, 012, 015, 019, 022, 024, 025, 027, 028, 030 |
| Engineering defaults | 17 | 001–006, 011, 013, 014, 016, 017, 018, 020, 021, 023, 026, 029 |

**Stop conditions documented, not solved:**

| # | Condition | Where |
|---|---|---|
| 1 | 8.6 not implemented; soft dependency | §3, §40 |
| 8 | Risk Radar rewrites risk status | DQ-87-01 |
| 11, 14 | Metric definitions and incentive inputs | D8.7-025 |
| 12 | Product choices | Part A |
| 14 | Production host topology not provable from the repository | §6, §20 |
