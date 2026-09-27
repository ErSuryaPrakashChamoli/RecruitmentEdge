# Phase 8.7 Discovery: Platform Reliability, Queue, Automation & Communications Integrity

**Status:** discovery only. No application code, configuration, migrations, tests, Docker or worker changes were made.

**Companion documents:**
- `docs/phase-8-7-security-review.md`
- `docs/phase-8-7-performance.md`
- `docs/phase-8-7-decision-record.md`

**Method:**
- Direct reads, `schedule:list`, `route:list`.
- Three independent read-only inventories: jobs and commands; events, listeners and communications; automation, AI, Outcome and metrics. Every High or Medium claim was spot-checked against source before inclusion.
- One run of the existing test suite (in-memory SQLite, no application state touched).

**Citations** are `file:line` against HEAD `dcff76e`.

---

## 1. Executive Summary

**Strengths:**
- **Post-commit dispatch is clean.** All 36 domain events implement `ShouldDispatchAfterCommit`, every external side effect (mail, SMS, WhatsApp, calendar, job boards, AI) runs after commit, and a rolled-back transaction never produces a side effect.
- **Candidate messages are rendered and stored when queued.** The job sends the stored text, holds a unique idempotency key, and a row-lock claim prevents double sending.
- **Automation executions** are version-bound, unique-keyed and claimed under a lock. They re-check rule state and owner authority when they run.
- **AI actions** execute at most once, expire, and are invalidated when authority changes.

The platform is **not yet "reliable, idempotent, authorized, traceable and recoverable"** end to end.

| # | Gap | Where |
|---|---|---|
| 1 | **Queue payloads carry sensitive data at rest.** Queued listeners serialize whole Eloquent models (events have no `SerializesModels`): offer CTC, salary components, the offer letter body, candidate email, mobile and salaries. Queued Filament notifications carry password-reset and email-change token URLs. All of this sits unencrypted in `jobs`, then in `failed_jobs`, which is **never pruned**. | SEC-87-01, 02 |
| 2 | **Send-time state is not re-checked.** A queued candidate message is sent even if the candidate opted out (including a STOP reply), was rejected, withdrew or was hired meanwhile. Listener messages render from the event's stale model: an "interview scheduled" message can go out after a cancellation. | §10, §24 |
| 3 | **Automation authorization at run time is rule-level only.** The owner's scope is checked, not the specific record. Scope is matched at trigger time only. The escalation path skips the owner re-check. | §25 |
| 4 | **The Risk Radar auto-resolves live risks it never looked at.** The hourly scan covers the first 200 open requisitions, then resolves *every* open risk not seen in that run. Above 200 open requisitions this rewrites the risk register every hour. (Stop condition 8, documented.) | §28, DQ-87-01 |
| 5 | **Traceability breaks at the worker boundary.** No system or automation actor is recorded in asynchronous audit rows (`user_id` NULL). Nothing started by the scheduler carries a `request_id`. There is no failed-job visibility anywhere in the product. | §22–24 |
| 6 | **Operations:** see the list below. | §20, §14 |
| 7 | **Metric governance is bypassed** by the recruiter daily metrics that feed performance, alerts, leaderboards and **incentive actuals**. Offers there are dated by `offer_date`, while governed `offer.acceptance_rate` uses the first Released date. Fixing this changes incentive inputs (stop conditions 11 and 14 → product decision). | §30 |
| 8 | **Test baseline:** 1,716 / 1,717 pass. `StageHistoryEventTest` "every writer records what the row is" is **flaky**: the relation's default `latest('created_at')` beats the test's `orderBy('id')`. It is a test-only defect from Phase 8.5; 1 of 3 isolated runs passed. | §2, §36 |

**Operational gaps (item 6):**
- Docker stops workers after the default 10 s grace period, while jobs may run for 120 or 300 s.
- The scheduler runs due events serially, and one hourly command is unguarded.
- `withoutOverlapping` locks outlive a crash by 24 h.
- `.env.example` leaves `DB_QUEUE_RETRY_AFTER` at 90, below the worker timeouts.
- The production runbook says cron `schedule:run`, while compose runs `schedule:work`. Running both doubles every unguarded schedule.

**No CRITICAL, actively exploitable production vulnerability was found.**
- The High finding (SEC-87-01) needs database or backup read access to exploit.
- The in-app queue has no UI exposing `jobs` or `failed_jobs`.

**Phase 8.6 is discovery-only** (§3). Phase 8.7 has **soft dependencies** on three 8.6 decisions, D8.6-021, 023 and 024 (§40), but none blocks this discovery.

## 2. Baseline Verification

| Item | Value |
|---|---|
| Branch | `feature/sep_25_hrm` |
| HEAD | `dcff76ec84e5bd5f4f1cb4fb4388c37b458f2288` (equal to the 8.6 discovery baseline) |
| Recent commits | `dcff76e` security containment; `05c922a` 8.5 freeze; `7d2dff4`, `21815d3`, `2eb8b1a`, `8b2c62a`, `c751248`, `61e3482`, `8972f32`, `1d17563` (8.5) |
| `git status` before | `?? docs/phase-8-6-decision-record.md`, `?? docs/phase-8-6-discovery.md` (8.6 discovery documents, uncommitted) |
| Migrations | 150 files (unchanged) |
| Routes | 237 |
| Tests | 1,717 tests, 19,342 assertions; **1,716 passed, 1 failed (flaky, §36)** in a parallel run (4 processes, 401 s) |
| Queue connection | `database` (config/queue.php:16); `after_commit=false` (:44); failed driver `database-uuids` (:124) |
| Cache / session | `database` / `database` |
| Workers | docker-compose.yml: `queue`, `queue-background`, `scheduler` (§6) |
| Hotfix | `hotfix/filament-delete-authorization` @ `2fab3fd`, not pushed (D8.6-030 pending) |

## 3. Phase 8.6 Status

**Verified: DISCOVERY ONLY.** The evidence:
- no 8.6 commits after `dcff76e`;
- the 150 migrations are unchanged;
- none of the proposed 8.6 tables exist (`offer_letters`, `recruitment_setting_changes`, `audit_logs.reason`, and others);
- the only 8.6 artefacts are the two untracked documents.

The prompt states 8.6 "has been approved for implementation"; the repository shows implementation **not started**.

**Stop condition 1:** 8.7 depends on 8.6 only softly (§40), so discovery proceeds. Implementation sequencing must account for it.

## 4. Queue Inventory

| Queue | Producers (proved from source) |
|---|---|
| `communications` | `SendCommunicationJob` (config/communications.php:20); `SendCandidateCommunications` listener (:31) |
| `automation` | `RunAutomationExecutionJob` (config/automation.php:15); `ProcessOwnershipHandoffJob` |
| `intelligence` | `GenerateRoleDnaSuggestionsJob`, `SummarizeHiringMemoryJob`, `SummarizeOutcomeInsightJob` (config/outcomes.php:42), `IndexAiDocumentJob`, `ReindexKnowledgeArticleJob`; listeners `CaptureHiringMemory`, `RecordHiringOutcomes` |
| `integrations` | `SyncInterviewCalendarJob`, `PublishJobDistributionJob` (hard-coded) |
| `default` | **Every in-app alert:** Filament `DatabaseNotification` is `ShouldQueue` (vendor filament/notifications DatabaseNotification.php:11). Also Filament `ResetPassword`, `VerifyEmailChange` and `NoticeOfEmailChangeRequest`, and `AiCopilotEmail` (dead code). |

- **5 queues.** No class in `app/Jobs` falls to `default`; only vendor notifications do.
- The queue names are environment-overridable (`*_QUEUE`), so a mis-set variable would route work to a queue no worker consumes. There is no guard; `QueueTopologyTest` checks compose only.

## 5. Queue Topology

| Queue | Worker | Job types | Priority within the worker | Timeout | Retry | Failure handler |
|---|---|---|---|---|---|---|
| communications | `queue` | candidate messages | 1st (strict) | 120 s | job 5 / listener 3 | `SendCommunicationJob::failed` marks Failed + audit + `CommunicationFailed`; listener: none |
| automation | `queue` | executions, handoffs | 2nd | 120 s | 3 | execution → Failed; handoff: **none** |
| default | `queue` **and** `queue-background` | in-app alerts, auth mails | 3rd on both | 120 / 300 s | worker 3, 0 s backoff | none |
| intelligence | `queue-background` | AI, embeddings, Outcome / Memory capture | 1st | 300 s | 2–3 | status → Failed (AI); listeners: none |
| integrations | `queue-background` | calendar, job boards | 2nd | 300 s | 4–5 | log only |

**The previously documented architecture is confirmed** (docker-compose.yml:58, :71; docs/phase-7-production-readiness.md:60-80).

`retry_after` = 330 in compose (docker-compose.yml:31) but **90 by default** (config/queue.php:43, `.env.example` has no key), below both timeouts, so any non-compose deployment can double-deliver long jobs.

## 6. Worker Inventory

| Process | Command | Concurrency | Memory | Restart | Graceful stop |
|---|---|---|---|---|---|
| `queue` | `queue:work --queue=communications,automation,default --tries=3 --timeout=120 --max-time=3600` | 1 process | PHP default 128 MB (no `--memory`) | `unless-stopped`; `--max-time` recycles hourly | pcntl installed (Dockerfile:90). **No `stop_grace_period`: Docker SIGKILLs after 10 s.** The process runs under `su -c` (docker/entrypoint.sh:34), not PID 1. |
| `queue-background` | `queue:work --queue=intelligence,integrations,default --tries=3 --timeout=300 --max-time=3600` | 1 process | default | same | same |
| `scheduler` | `schedule:work` | 1 | — | `unless-stopped` | same |

- **Workers: 2 queue workers plus 1 scheduler.**
- No Horizon, supervisor or systemd definitions exist in the repository.
- **Production host topology is not provable from the repository** (stop condition 14, documented): the runbook (phase-7-production-readiness.md:150-155) describes cron `schedule:run` and bare `queue:work` commands.

## 7. Job Inventory

All ten classes in `app/Jobs` pass **ids only**. None sets `$timeout`, `$maxExceptions` or middleware.

| Job | Queue | Tries / backoff | Unique | Run-time re-check | Idempotency | failed() | Retry effective? |
|---|---|---|---|---|---|---|---|
| SendCommunicationJob | communications | 5 / 30, 120, 600, 1800 | yes, 3600 | status and provider only (Jobs/SendCommunicationJob.php:67-70) | unique key + locked claim | yes | yes |
| RunAutomationExecutionJob | automation | 3 / 60, 300 | yes, 3600 | rule, owner, record, anchor, limits, conditions (AutomationEngine.php:433-481) | unique key + locked claim | yes | **mostly no**: `run()` catches Throwable (:175-180) |
| ProcessOwnershipHandoffJob | automation | 3 / **none** | yes | — | unique `dedupe_key` | **none** | yes (0 s) |
| GenerateRoleDnaSuggestionsJob | intelligence | 2 / 60 | yes | profile exists; **not user authority** | uniqueId | yes | yes |
| SummarizeHiringMemoryJob | intelligence | 2 / 60 | yes | record exists | overwrite | yes | **no**: service catches Throwable (IntelligenceAiService.php:~214) |
| SummarizeOutcomeInsightJob | intelligence | 2 / 60 | yes | insight exists | overwrite | yes | **no** (:~264) |
| IndexAiDocumentJob | intelligence | 3 / 60, 300 | yes | document exists | delete-then-insert, no transaction | yes | **no** (service catches) |
| ReindexKnowledgeArticleJob | intelligence | 3 / 60, 300 | yes (**lock held while running, so a concurrent save is dropped**) | **not `is_published`** | replace | log only | yes |
| SyncInterviewCalendarJob | integrations | 5 / 60, 300, 900, 3600 | **no** | **not interview status** | mapping unique (interview, provider) | log only | yes (~81 min span) |
| PublishJobDistributionJob | integrations | 4 / 60, 300, 1800 | **no** | **not posting status** | **none** | log only | yes |

Queued listeners (§9) and vendor notifications (§4) add **7 more queued units**.

## 8. Event Inventory

- **36 events**, all `ShouldDispatchAfterCommit`, using `Dispatchable` only. **None uses `SerializesModels`.**
- Registration is auto-discovery plus `event:cache` (docker/entrypoint.sh:27), with no double registration.
- **Events with no listener:**
  - ApplicationMovedToRequisition
  - CandidateAddedToTalentPool / RemovedFromTalentPool
  - CandidateRescheduled
  - CandidatePortalProfileUpdated
  - EmployeeAccessRestored
  - **EmployeeSeparated**, **SeparationCancelled**, **OfferRevisionReleased**
  - UserProvisioned
  - InterviewSlotBooked, InterviewSlotCancelled

  The revision event has no candidate notification and no automation trigger. That is product scope, recorded in the backlog.

| Event | Dispatch site | Transaction |
|---|---|---|
| OfferAccepted / OfferReleased / OfferStatusChanged | OfferService.php:178 / :182 / :185 | inside a transaction, **no row lock** (moveTo reads `$from` from memory, :144) |
| OfferRevisionReleased | OfferService.php:308 | transaction, revision row locked |
| CandidateJoined | CandidateJoiningService.php:90 | transaction |
| EmployeeConvertedFromCandidate | EmployeeConversionService.php:122 | transaction + candidate lock |
| ApplicationMovedToRequisition | ApplicationAssignmentService.php:100 | transaction |
| InterviewScheduled | InterviewService.php:91 | after its own transaction |
| InterviewRescheduled / Cancelled / MarkedNoShow / Confirmed | InterviewService.php:117 / 148 / 190 / 214 | **no transaction, no lock** |
| CandidateStageChanged | StageTransitionService.php:349, :382 | transaction, often nested |
| CommunicationFailed | SendCommunicationJob.php:150; DeliveryStatusService.php:92 | deferred to commit |
| EmployeeSeparated / SeparationCancelled | EmployeeLifecycleService.php:353 / :295 | transaction + locks |
| Access Suspended / Revoked / Restored | StaffAccessService.php:229 | after commit |

## 9. Listener Inventory

| Listener | Mode | Queue / tries | Idempotency | Note |
|---|---|---|---|---|
| SendCandidateCommunications | queued | communications / 3, **0 s backoff**, no failed() | stable keys + unique index | renders from the **serialized event model** (Listeners/SendCandidateCommunications.php:45-72) |
| CaptureHiringMemory | queued | intelligence / 3, 0 s | `capture_key` unique | offer path uses the stale `$event->offer` (:64) |
| RecordHiringOutcomes | queued | intelligence / 3, 0 s | reloads by id; unique keys | safe |
| TriggerAutomationRules | sync, try/`report` | — | execution key | failure is reported, not retried (Listeners/TriggerAutomationRules.php:118-125) |
| SyncInterviewCalendar | sync | → job | none at dispatch | |
| StartOwnershipHandoff | sync | → job | dedupe key | |
| InvalidateAiActionsOnAuthorityChange | sync | — | idempotent | |
| NotifyRecruitersOfPortalDocument, NotifyReviewersOfReferral | sync | → `default` alert | dedupeKey (racy) | |
| RecordDuplicateDecisionsOnTimeline | sync | — | none | |
| SyncReferralsWithApplication | sync | — | service | |
| RecordStaffSignIn, RecordStaffAuthEvents | sync | — | n/a | |

The rules index still names the removed `CreateJoiningRecordForAcceptedOffer` (`.ai/rules/index.md`, listeners row). That is a documentation drift.

## 10. Scheduler Inventory

**14 scheduled commands** (routes/console.php:11-25; `schedule:list` verified). The `withoutOverlapping` mutex lives in the database cache with a **1440-minute expiry**. Nothing uses `onOneServer` or `runInBackground`, so events due in the same minute run **serially**.

| Command | Schedule | Overlap | Inline or queued | Isolation | Cost / risk |
|---|---|---|---|---|---|
| incentives:release-matured | daily 00:00 | none | inline, `->get()` | none | low; actor NULL |
| notifications:dispatch-alerts | **hourly** | **none** | inline, ~10 unbounded `->get()`, composite score per recruiter | **none**: one failing check stops the rest | medium–high (P85-BACKLOG-006) |
| offers:expire-lapsed | daily 00:15 | none | inline, `->get()` + `moveTo` without a lock | none | **races acceptance** |
| performance:snapshot | daily 00:30 | none | `lazyById` | freeze only when day == 1 | medium |
| interview-slots:expire | hourly | none | `chunkById` mass update | — | low; unaudited |
| jobs:sync-distributions | daily 01:00 | none | `->get()` → queued unpublish | none | low |
| communications:send-reminders | hourly | none | `chunkById` → queued sends | keys make overlap safe | low |
| recruitment:automation:dispatch | 15 min | yes | sweep, `limit(500)` per rule | none per rule | medium |
| recruitment:automation:process | 5 min | yes | stale sweep, due dispatch (200), **escalations inline** | per-escalation lock | low |
| recruitment:automation:cleanup | daily 02:00 | none | chunked; bulk prune **unaudited** | — | low |
| intelligence:refresh | hourly | yes | **all inline**: health, up to 200 × 200 signals, radar, stale AI | none per requisition | **high**; delays :00 peers |
| outcomes:evaluate | daily 03:00 | yes | batched, savepoints | good | medium |
| ai:expire-pending-actions | 5 min | yes | `chunkById` | — | low; delayed at :00 |
| identity:enforce-separations | hourly, gated | yes | `chunkById`, locks, per-item catch | good | low |

- **Manual commands (9):** `ai:reindex-knowledge`, `ai:redact-history` (dry-run only), `ai:test-provider`, `ai:evaluate` (**`--live` mutates data as another user and clears their rate limiter**), `recruitment:assign-default-pipelines`, `identity:reconcile-access`, `outcomes:backfill`, `lifecycle:audit`, `identity:audit`.
- **Missing schedules:** `queue:prune-failed`, `queue:prune-batches`, `model:prune`, and a performance-freeze catch-up (D8.6-017).
- **Duplicate-schedule risk:** if production runs cron `schedule:run` (runbook) **and** the compose `scheduler`, the 8 unguarded commands run twice. `withoutOverlapping` prevents only concurrent overlap, not a second run a minute later from a second scheduler.

## 11. Automation Engine

**Path:** domain event (after commit) → `TriggerAutomationRules` (sync) → `AutomationEngine::handleEvent` → `trigger()` (:62-100). That step reads cached triggers (:317), then active rules by priority (:323-331), then effective dates, **scope match at trigger time (:87)**, then timing, and finally `createExecution` (:381-431).

**Idempotency key:** `rule:version:trigger:morph:subject:discriminator`, where the discriminator is sha1 of event data, the subject's `updated_at` and the anchor.
- It is enforced by a **unique index** (migration 2026_09_25_185700:25).
- A collision is caught and returns null (:412-413).
- Unsafe runs (loop, depth, missing anchor) are stored as Skipped; loops are audited.

**Components:**

| Kind | Components |
|---|---|
| Services (7) | AutomationEngine, AutomationRuleService, AutomationScopeResolver, AutomationRuntime, EscalationService, AutomationActionRegistry, AutomationHealthService |
| Queue, events and commands (5) | the listener, `RunAutomationExecutionJob`, 3 commands |
| Models (5) | rule, version, execution, action-execution, escalation |
| Action handlers (9) | see §12 |

That makes 26 components in total. Separately, `OwnershipHandoffService` and `ProcessOwnershipHandoffJob` pause rules when their owner loses access (8.4).

**Limits** (config/automation.php):

| Limit | Value |
|---|---|
| Chain depth | 3 |
| Executions per rule per day | 500 |
| Messages per candidate per day | 3 |
| Dispatch limit | 500 |
| Process limit | 200 |
| Stale running | 60 min |
| Stale pending | 7 days |

The limit checks **count, then act without a lock** (:584-617), so concurrent runs can exceed them.

**Conflicts:** nothing coordinates two rules acting on one record. Only domain-service guards and the message cap apply. Loop detection is in-process only (AutomationRuntime.php:33-53); across queued hops only the depth cap stops A→B→A.

## 12. Automation Execution

**Claim:** `run()` opens a transaction, takes `lockForUpdate` on the execution, requires Pending, then sets Running (AutomationEngine.php:159-169). The job is also `ShouldBeUnique`.

**`perform()` runs outside a transaction.** Exceptions mark the execution Failed. A retry re-runs only Failed action rows; Completed and Skipped rows are kept via unique `(execution, position)` (migration 185701:29).

**Worker crash:**
- The redelivered job finds the execution Running and exits as a no-op, so `failed()` does not fire.
- The run stays Running until the 60-minute sweep marks it Failed (:240-243).
- A person must then retry it. It is never reclaimed automatically.

**Version:** the version snapshotted when the execution was created (:436). **Editing a rule does not re-version or cancel its pending or delayed executions**, which run the **old** actions and conditions. Only archive cancels pending runs (AutomationRuleService.php:162-167). Pause is honoured at run time.

**Actions** (AutomationActionRegistry.php:26-36). All handlers run with a **null actor**; authorization is the rule owner's, at rule level.

| Action | Class | Redo idempotency | Stale record |
|---|---|---|---|
| send_communication | COMMUNICATION / EXTERNAL | key `automation:{exec}:{pos}:{channel}` + unique index | candidate status not checked |
| notify | COMMUNICATION (internal) | dedupe against the notifications table (racy, P83-BACKLOG-010) | recipient must be active |
| escalate | COMMUNICATION | unique `(execution, step)` | recipient active |
| create_action | LOW-RISK WRITE | `createOnce` with a unique `dedupe_key` | none |
| create_followup | LOW-RISK WRITE | **none: duplicates on redo** | skips when no recruiter |
| add_timeline_event | LOW-RISK WRITE | **none** | skips when no candidate |
| add_audit_event | LOW-RISK WRITE | none | none |
| move_stage | MATERIAL WRITE (4 early stages only) | skips when already there | service rejects inactive or backward moves |
| hold_application | MATERIAL WRITE | a redo throws, so the action is marked failed although the hold happened | service rejects inactive |

There are **no FINANCIAL or SECURITY/ACCESS automation actions.**

**Answers to the required questions:**

| Question | Answer |
|---|---|
| Same automation twice? | No: unique key + locked claim. Two rules can each act on the same record. |
| After the rule is disabled? | Pause and archive: no (checked at run). **Edit: pending runs execute the old version.** |
| Old or new version when queued? | Old (the snapshot at trigger time). |
| After the author leaves? | No for the main path: the owner re-check pauses the rule (8.4). **Escalations: yes** (EscalationService.php:86-110 checks only `rule->isActive()`). |
| Under permissions the author lost? | Main path: `automation.activate` + scope permission re-checked. Per-action permissions (`candidates.move`, messaging) are **not** re-checked, nor is visibility of the specific record. |
| Record state changed before execution? | Conditions are evaluated on fresh state. Scope is **not** re-matched. Communication and notify actions do not check candidate status. |
| Two automations conflict? | Yes: no coordination. |
| Duplicate communications? | Not from one execution (key). Different rules can each message within the daily cap of 3, and the cap is not locked. |

## 13. Communication Architecture

`CommunicationService::send` (CommunicationService.php:48-171) runs these steps:
1. channel and template-active check;
2. idempotency lookup, backed by a unique index;
3. validate and **render at queue time**;
4. preference, consent and provider checks;
5. in one transaction: the row (Queued or Blocked), a candidate-visible timeline entry and an audit row;
6. `SendCommunicationJob::dispatch()->afterCommit()` (:167).

**The job:**
- claims the row under a lock (Queued → Sending);
- a row already in Sending is marked Failed with "delivery state unknown" and **not resent** (at most once);
- a retryable failure returns the row to Queued and throws; a permanent one marks it Failed, audits it and fires `CommunicationFailed`.

| Channel | Implementation | Status | Notes |
|---|---|---|---|
| Email (candidate) | LaravelMailEmailProvider → `Mail::send` inside the job | REAL (`.env.example` `MAIL_MAILER=log`) | Log or array transport still marks the message **Sent** (the audit carries `external_delivery=false`). No SMTP timeout (config/mail.php:48). `opened_at` / `clicked_at` are never written. No bounce webhook. |
| SMS | TwilioSmsProvider | REAL | 15 s timeout; 429 and 5xx retry; signed status webhook; STOP → opt-out |
| WhatsApp | WhatsAppCloudProvider | REAL | Requires `provider_template` and explicit consent. **The stored body is not what was sent** (Meta sends the approved template). Language is always `en`. |
| In-app (staff) | `NotificationDispatchService::alert` → Filament database notification | REAL | Queued on `default`; racy dedupe; reroutes unreachable recipients (8.4) |
| Candidate portal | `CandidatePortalService::messagesFor` | PARTIAL | Shows Sent / Delivered / Read. The timeline entry is written at queue time, so a message that later fails still shows. |
| Push / broadcast | — | ABSENT | |
| Portal link, staff invite, email change | Synchronous `Mail::send` in the request | REAL, outside the pipeline | No communications row, no retry, no resend path for the invite (IdentityProvisioningService.php:135-141) |
| Legacy `app/Services/AI/Communication/*` | bound in AiServiceProvider.php:73-75, never resolved | STUB / dead | |

Five channels are present (email, SMS, WhatsApp, in-app, portal); push is absent.

**Controls:**
- **Rate limiting:** automation messages only (3 per candidate per day). No provider throttle.
- **Fallback:** no fallback channel.
- **Sweepers:** none for rows stuck in Queued (lost job) or Sending.
- **Manual and AI sends** use random UUID keys, so a double submit sends twice.

## 14. Template Architecture

- **Versioning:** subject and body only; a `provider_template` or status change does not version.
- **Lifecycle:** active or archived; no effective dates.
- **Variables:** whitelisted in `TemplateRenderer::VARIABLES` (:17-35), with **no compensation variables**. Unknown or malformed placeholders raise an exception.
- **Missing values:** manual sends throw; automatic sends are stored **Blocked** with the reason.
- **Preview:** exists (template UI).
- **Never supplied:** `{{links.scheduling}}` is never populated by any caller, so any template using it is always Blocked.
- **Subject overflow:** a rendered subject can exceed `string(255)`, which raises a QueryException instead of Blocked.

**Critical question: a message queued today, template changed tomorrow.**
- **The old wording is sent.** It was rendered and stored at queue time (CommunicationService.php:87-88, 119-120); the job sends the stored text (SendCommunicationJob.php:75-84).
- **Archived while pending:** still sent. The active check runs at queue time only (:62).
- **WhatsApp:** the stored `provider_template` and parameters are sent.
- **Historical reproducibility of sent content:** yes. The row holds the subject, body and integer `template_version` (8.6 D8.6-023 proposes a foreign key to the version row).

## 15. Provider Architecture

| Area | Providers | Behaviour |
|---|---|---|
| Messaging | `ProviderRegistry` (mail, Twilio, WhatsApp Cloud) | Secrets in environment only |
| Calendar | Google, Microsoft, Zoom | 15 s timeouts, encrypted tokens |
| Job boards | `JobBoardRegistry` (LinkedIn, Naukri, Indeed) | **Current connectors make no HTTP calls** (no client under Distribution/Connectors), so they are effectively simulated |
| AI | `AiGateway` | `AiEgressGuard` on every method (Gateway/AiGateway.php:57, 80, 104); sanitized `Log::error` on failure; usage log |

## 16. Retry / Backoff

| Component | Attempts | Backoff | Maximum span | Gaps |
|---|---|---|---|---|
| SendCommunicationJob | 5 | 30, 120, 600, 1800 | ~42 min | no consent re-check across the span |
| RunAutomationExecutionJob | 3 | 60, 300 | ~6 min | Throwable caught, so the retry is mostly unused; the manual retry UI is the real path |
| ProcessOwnershipHandoffJob | 3 | **0** | immediate | no `failed()`: **exhausted tries are lost silently** |
| Queued listeners (3) | 3 | **0** | immediate | **retry storm on outage**; no `failed()` |
| `default` notifications | 3 | 0 | immediate | no `failed()` |
| AI summaries, indexing | 2–3 | 60 (+300) | — | **ineffective**: services catch Throwable (P7-BACKLOG-005, P82-BACKLOG-007) |
| Role DNA suggestions | 2 | 60 | — | effective (P84-BACKLOG-011) |
| SyncInterviewCalendarJob | 5 | 60, 300, 900, 3600 | ~81 min | out-of-order execution after cancel |
| PublishJobDistributionJob | 4 | 60, 300, 1800 | ~36 min | stale operation; duplicate publish |

**What is retried or not:**
- **Not retried:** validation errors (Blocked), authorization (automation pauses the rule), missing records (automation skips or fails), expired AI actions (retired).
- **Retried:** permanent provider errors, only when classified retryable (429 / 5xx).
- **Infinite retry:** none.
- **Zero-backoff retry:** the listeners and `default`.

## 17. Idempotency

| Area | Duplicate-safe? | Evidence / gap |
|---|---|---|
| Candidate message (event, automation, reminder) | **yes** | unique `idempotency_key` + locked claim; crash leads to Failed, not a resend |
| Candidate message (manual, AI) | **no** | random UUID key |
| Automation execution | yes | unique key + lock + `ShouldBeUnique` |
| Automation actions on redo | partial | followup, timeline and audit duplicate; hold marks failed |
| AI action | yes (sequential) | conditional Pending → Approved (ActionExecutor.php:268-274); crash mid-execution leaves Approved forever (:93-97), with no transaction yet the message says "data not affected" |
| Offer transition | **no lock** | concurrent accept and expire can both commit (OfferService.php:144) |
| Interview reschedule / cancel / no-show | **no transaction or lock** | double submit fires events twice; staff alerts without a dedupe key (:194) |
| Reschedule message | **key collision** | A→B→A reuses `interview.rescheduled:{id}:{ts}`, so the move back is not announced |
| Outcome | yes | unique `(dedupe_key, version)` + lock |
| Hiring Memory | idempotent, not atomic | a partial write leaves the record without evidence or audit (HiringMemoryService.php:271-296) |
| Role DNA | yes | unique keys + lock |
| Health / signal snapshots | **no** | no unique current row; cron and web race (HiringHealthService.php:69-87) |
| Hiring risks | mostly | unique `open_key`; the race is uncaught and aborts the scan |
| Calendar | partial | mapping unique; concurrent creates can both call the provider |
| Job distribution | **no** | success followed by a failed `record()` republishes on retry |
| In-app alerts | racy | dedupe on a queued-written table (P83-BACKLOG-010) |
| Incentive calculation | yes (8.5 recalculation guard) | — |

## 18. After-Commit Integrity

- **Proven:** all 36 events are `ShouldDispatchAfterCommit`. `SendCommunicationJob` and `PublishJobDistributionJob` dispatch `->afterCommit()`. The staff invite uses `DB::afterCommit`.
- **Rollback:** the database queue shares the default connection, so a job inserted inside a transaction rolls back with it.
- **No external side effect happens before commit.**

**Exceptions:**
- **Model events not deferred until commit:** the `AiDocument` `created` event (AiDocument.php:54) and the `AiKnowledgeArticle` `saved` event (:37) dispatch indexing jobs.
  - If the enclosing transaction rolls back, the job row rolls back too (shared connection), so the risk is only if the queue connection is ever split.
- **Synchronous mail after commit:**
  - The staff invite, portal link and email-change mails are sent synchronously once the transaction has committed.
  - An SMTP failure then surfaces as an error to a user whose data is already saved.
  - None of these mails has a retry.

**The named events:**

| Event | Result |
|---|---|
| CandidateJoined, EmployeeConvertedFromCandidate, OfferAccepted, OfferRevisionReleased | post-commit ✔ |
| InterviewCancelled, InterviewMarkedNoShow | post-commit ✔ but no transaction or lock around the transition (§17) |
| ApplicationMovedToRequisition | post-commit ✔, no listener |

## 19. Failure Recovery

| Case | Expected | Actual | Risk | Recovery |
|---|---|---|---|---|
| DB unavailable | workers back off and resume | the worker loop errors and restarts (`unless-stopped`); the scheduler misses runs; there is no catch-up | missed daily jobs (freeze, expiry) | manual re-run |
| Redis unavailable | n/a | Redis not used (database queue and cache) | — | — |
| Mail provider down | retry, then Failed | retry ×5 (≤42 min), then Failed + `CommunicationFailed` | message lost after 42 min; no resend UI | automation on `CommunicationFailed`, else manual |
| SMS / WhatsApp down | same | same; 429 and 5xx retry | same | same |
| Calendar down | retry | ×5 over ~81 min; log only | orphan or duplicate external events | none |
| AI provider down | retry, honest failure | summaries fail immediately (no retry); Role DNA retries once | P7-BACKLOG-005 | re-request |
| Worker crash / PHP dies | at-least-once, then reconcile | message: row stuck Sending, then Failed on redelivery (safe, lost). Automation: Running, then no-op, then Failed after 60 min. AI action: stuck Approved. | lost work, no auto-reclaim | manual retry |
| Container restart / deploy | graceful drain | **10 s grace, then SIGKILL**; job redelivered after `retry_after` 330 s | as above | — |
| Queue unavailable | dispatch fails loudly | dispatch inside a request throws after commit, so the domain change persists but the side effect is lost (no outbox) | lost message, automation or calendar | none |
| Permanently failed job | visible, recoverable | `failed_jobs` only; no UI, alert or pruning | silent | `queue:retry` by an operator |
| Record deleted or inactivated | skip | automation: record missing, then skip. Message: sent anyway. Calendar: no status check. | stale sends | — |
| Employee separated / owner loses access | pause | owner re-check pauses; handoff job; **escalations continue**; AI jobs do not re-check | escalation under revoked authority | — |

## 20. Deployment Lifecycle

**Documented sequence:** deploy → migrate → `optimize` → `queue:restart` (phase-7-production-readiness.md:160-170; 8.3–8.5 commit plans).

**Gaps:**
1. **No `stop_grace_period`.** A Docker restart kills in-flight jobs after 10 s; they are redelivered after 330 s (§19).
2. The `su -c` wrapper (docker/entrypoint.sh:34): util-linux `su` forwards SIGTERM to its child, but this is **not tested**. Using `exec` with a user switch (gosu or setpriv) would make the worker PID 1.
3. **Schema and code compatibility:**
   - Payloads serialize whole models for listeners, so a column rename or drop between dispatch and run can fail on unserialize.
   - Ids-only jobs are safe.
   - Migrations run in `app` while old workers may still be processing: there is no ordering that stops the workers first.
4. **Caches:** the entrypoint rebuilds config, route, view and event caches per container. Stale workers keep old config until `queue:restart` or `--max-time`.
5. **Scheduler duplication** if cron and `schedule:work` are both present (§10).
6. **The `withoutOverlapping` mutex** survives a killed scheduler for 24 h, blocking that command for up to a day.

## 21. Database Queue

**Migration** `0001_01_01_000002_create_jobs_table.php`:
- `jobs`: index on `queue` only.
- `failed_jobs`: unique `uuid`; index `(connection, queue, failed_at)`.
- `job_batches`: unused.

**Reservation:** Laravel's MySQL 8 pop uses `FOR UPDATE SKIP LOCKED` on the oldest available row per queue. With only a `queue` index, each pop scans that queue's rows ordered by id.

**Retention:** `failed_jobs` stores the full payload plus exception and trace, and is **never pruned**.

| Queued jobs | Behaviour (estimate from code; no load test run) |
|---|---|
| 10k | fine; pop ≈ ms |
| 50k | pop cost grows with reserved or delayed rows in the same queue; acceptable |
| 100k | delayed rows (backoffs up to 3600 s) and reserved rows are scanned on every pop; `default` alert bursts from `dispatch-alerts` inflate it |
| 500k | contention on `jobs` inserts and deletes plus scanning; a composite `(queue, reserved_at, available_at)` index or Redis is recommended (D8.7-027) |

Payload size is small for ids-only jobs and **several KB per listener job** (whole models plus loaded relations).

## 22. Observability

| Question | Available? |
|---|---|
| request_id | **Yes for request-dispatched jobs** (Laravel Context is carried in the payload; AssignRequestId.php:22 → AuditLog.php:59). **No for anything started by the scheduler or by `processDue`.** Not stored on execution or communication rows. |
| job id / attempt | only in `failed_jobs` and the exception; not persisted on business rows (`attempts` exists on communications) |
| automation execution id | yes (execution, action rows, idempotency key) |
| actor | request-time rows yes; **worker rows `user_id` NULL**, with no actor type |
| triggering entity | execution `subject_type/id`, trigger key |
| queue / worker | no |
| outcome / failure | execution and communication status plus error |
| retry history | communications `attempts`; automation retries audited; jobs no |
| failed-job visibility | **none in the product**. `AutomationHealthService.php:54` counts automation jobs only. |
| alerting | none for failed jobs, stuck rows or scheduler failures |

## 23. Correlation / Traceability

**Trace: recruiter schedules an interview.**

| Step | What happens | Trace kept? |
|---|---|---|
| 1 | `InterviewService::schedule` (transaction) writes the audit row with **request_id R** | ✔ |
| 2 | After commit: `InterviewScheduled` → `SyncInterviewCalendar` (sync), which dispatches `SyncInterviewCalendarJob` | Context R in the payload ✔ |
| 3 | `TriggerAutomationRules` (sync) creates the execution and dispatches `RunAutomationExecutionJob` | R ✔. If the execution is **delayed** (timing), `processDue` dispatches it later with **no R** ✘ |
| 4 | `SendCandidateCommunications` (queued) → `CommunicationService::sendAutomatic` | the row carries idempotency key `interview.scheduled:{id}`; the audit row has R but **user_id NULL** ✘ |
| 5 | `SendCommunicationJob` → provider → `communication_sent` audit | `provider_message_id` stored; R ✔ (same chain) |
| 6 | Webhook → `DeliveryStatusService` → delivered | new request id R2; linked by `provider_message_id` ✔; **lost if it arrives before the message id is saved** ✘ |

**Where the trace breaks:**
- delayed or scheduled dispatch (no request_id);
- worker actor (NULL);
- no link from the execution to the originating request;
- the webhook race.

## 24. Actor Attribution

| Scenario | Recorded actor |
|---|---|
| Recruiter or manager action triggering automation | trigger-time audit: the human. Automation writes in the worker: **NULL** (sync queue in tests: the human, which is inconsistent). |
| Scheduled command (SLA alerts, expiry, release) | NULL |
| AI-approved action | the approving user (inline in the request) ✔ |
| Queued job retry | NULL |
| System communication | `sent_by` NULL; audit NULL |
| Automation after the original user left | NULL (the rule is paused anyway, except for escalations) |
| Queued AI result | NULL; the requester only on the preceding `*_requested` row (P7-BACKLOG-007) |

**Current behaviour:** automation is not *intended* as anonymous. The rule owner is the authority, but no audit row names the owner or execution as actor. **Decision D8.7-015.**

## 25. Execution-Time Authorization

| Component | Re-checked at execution |
|---|---|
| Automation main path | owner non-null, `StaffAccessService::permits` (active user, employee access state), `automation.activate`, scope permission and hierarchy (AutomationRuleService.php:110-121; AutomationScopeResolver.php:71-96). **Not:** the specific record's visibility, per-action permissions, scope re-match. |
| Escalations | **rule active only** (EscalationService.php:86-110), with no effective dates and no owner re-check; inline in the command; candidate template bypasses the daily cap |
| AI actions | requester only; authority fingerprint (roles, employee, sees-all, ancestors); tool permission; feature flag; tool `handle` re-checks scope; invalidated on suspend, revoke or role change ✔ |
| AI jobs | **no user re-check**; the output is summaries only, through the egress guard |
| Communications | none needed for authority. **Consent and state not re-checked.** |
| Ownership handoff | system action by design |

## 26. Access / Identity Interaction

- **Phase 8.4 is intact.**
  - Suspended, revoked and role-changed users invalidate pending AI actions.
  - Access loss starts an ownership handoff that pauses the owner's rules and reassigns actions.
  - Alerts reroute away from unreachable recipients.
  - `identity:enforce-separations` is hourly and gated.
- **Gaps:**
  - Escalations ignore the owner's authority.
  - The handoff job has no `failed()`, so a failed handoff leaves rules running until the per-execution owner re-check pauses them.
  - `EmployeeSeparated` has no listener: separation acts through the access events at the last working day, as designed.
- **S12 in 8.4 ("stale permission map in workers")** is still latent: workers memoise per process and are recycled hourly.

## 27. AI Queue Interaction

- **Approval and execution run inline** in the Livewire request (Filament/Pages/AiCopilot.php:255-268), not queued.
- **Cannot run twice** (conditional update) or **after expiry** (checked at approval, swept every 5 min). Cannot run after an authority change (fingerprint and invalidation).
- **Out of scope:** blocked by the tool's own scope re-check.
- **Business services are not bypassed:** tools call the domain services.
- **Crash mid-execution** leaves the call Approved forever. It cannot re-run, but nothing marks it Failed, and the user-facing text claims "data not affected" (ActionExecutor.php:276-288).
- **AI jobs** all pass through `AiGateway` (egress guard, sanitized logs). Their retry settings are ineffective (§16), and none re-checks the requesting user.
- **Retries do not leak protected data:** the gateway redacts on every attempt.

## 28. Outcome Loop Interaction

- **Listeners:** `RecordHiringOutcomes` (queued, intelligence) reloads by id and is idempotent; unique `(dedupe_key, version)` plus a lock (OutcomeService.php:39-58).
- **`outcomes:evaluate`:** daily, guarded, batched, with savepoints and per-record failure listing.
  - `OutcomeLearningService::refresh` loads 90 days into memory (performance report).
- **Semantics:** unchanged; no finding requires an Outcome semantic change.

**Hiring Risk Radar (DQ-87-01, High):**

| | Behaviour | Evidence |
|---|---|---|
| Scan | `scan()` with no requisition takes `where(status=Open)->orderBy('id')->limit(200)` | HiringRiskRadar.php:73 |
| Auto-resolve | resolves **every** open risk with `last_seen_at` < start, with no requisition restriction | :402-418 |
| Joining and offer sources | capped at 500 rows | :298, :332 |

**Effect:** with more than 200 open requisitions, risks on requisitions beyond #200 are marked Resolved ("No longer detected") every hour. The resolution is audited, and the risk is re-opened as a *new* row only if later scanned.

- **Stop condition 8** (an asynchronous process rewrites historical business records) is **reached and documented, not fixed.**
- **Current exposure:** zero below 200 open requisitions. The production count is unknown.

## 29. Hiring Memory / Role DNA Interaction

- **CaptureHiringMemory** (queued, intelligence): unique `capture_key`.
  - It is **not atomic**: the record, evidence and audit are not in one transaction, and a retry returns early, so a partial failure is permanent.
  - The offer path uses the stale serialized offer.
- **Role DNA:** unique keys plus a lock.
  - `GenerateRoleDnaSuggestionsJob` retries once and audits failures.
  - Suggestions stay unconfirmed until a person confirms them (AI recommends, humans decide ✔).
- **Talent Signal and Hiring Health:** no unique current row, so cron and web refreshes can leave two "current" rows. Cron runs audit nothing.
- **Talent Rediscovery:** on demand, bounded by config (8.2); not asynchronous.
- **Semantics:** no Memory or Role DNA change needed.

## 30. Metric Governance Interaction

**Bypasses** (raw KPI computation outside `MetricService`):

| Bypass | Evidence |
|---|---|
| **`RecruiterDailyMetricsService`** counts interviews, offers, joinings and stages directly. **Definition drift:** offers are dated by `offer_date`, while governed `offer.acceptance_rate` uses the first Released status. | RecruiterDailyMetricsService.php:64-93, :105-155; drift at :81-86 vs OfferAcceptanceRate.php:34 |
| `DispatchRecruitmentAlerts` still calls `RecruitmentAnalyticsService::vacancyAgeing`, superseded by governed `requisition.ageing` | :90-115 |
| SLA open-breach sweeps (`openBreaches`, `openPipelineStageBreaches`, `breachFor`) are raw, with no governed open-breach metric | RecruitmentSlaService.php:108-233 |

Consumers of `RecruiterDailyMetricsService`:
- PerformanceEngine.php:33-129
- DispatchRecruitmentAlerts.php:508-586
- SnapshotRecruiterPerformance.php:63
- **RecruiterIncentiveCalculator.php:172**
- Leaderboard.php:178
- TodaysRecruitmentPulse.php:106
- GetRecruiterPerformanceTool.php:71

Consumers of the raw SLA breach queries: DispatchRecruitmentAlerts:121 and :148, AutomationFieldRegistry:137, RecruitmentAnalyticsService:963.

**Guard gap:** `MetricRegistry.php:9` cites a `MetricGovernanceArchitectureTest` that **does not exist**. `tests/Unit/Metrics/MetricArchitectureTest.php` does not scan commands, the performance engine, the incentive calculator, widgets or pages.

**Boundary:** aligning recruiter daily metrics with the registry changes performance and **incentive inputs**. That is stop conditions 11 and 14 → product decision D8.7-025. Phase 8.7 proposes only the guard and inventory, not a definition change.

## 31. Privacy / Egress

- **AI egress (8.1) holds on asynchronous paths.** Every AI job goes through `AiGateway` → `AiEgressGuard`, with no off mode.
- **The queued payload is the gap:**
  - Events hold models without `SerializesModels`, and `CallQueuedListener` doesn't use it either (vendor Events/CallQueuedListener.php:14). Full attributes and loaded relations are therefore stored in `jobs.payload`.
  - Payloads are unencrypted (no `ShouldBeEncrypted` anywhere).
  - `OfferReleased` → `SendCandidateCommunications` carries the Offer's CTC, fixed, variable, bonus and `offer_letter_body` (Offer.php:48, 55). By dispatch time the application and candidate are loaded: email, mobile, current and expected salary (Candidate.php:35-36).
  - The data is copied into `failed_jobs` on failure and **never pruned**.
  - This bypasses the `compensation.view` (8.5 SEC-2) control *at rest*.
- **Tokens:** Filament `ResetPassword`, `VerifyEmailChange` and `NoticeOfEmailChangeRequest` are queued on `default` with bearer-token URLs in the payload.
- **Logs:**
  - A retryable send throws with the provider error text, which can contain the phone number or email address (SendCommunicationJob.php:102).
  - `report($e)` of a QueryException in `sendAutomatic` (:195) logs SQL bindings, including the rendered body and recipient.
  - There is no log redaction processor.
  - With `MAIL_MAILER=log`, full candidate emails and portal, invitation and email-change links are written to `laravel.log`.
- **Templates:** no compensation variables. Manual and AI free text is unrestricted (by design; the AI side is gated by the egress guard and a confirmation).

## 32. Security Findings

See `phase-8-7-security-review.md`. **14 findings: 0 Critical, 1 High, 6 Medium, 5 Low, 2 Informational.** None requires emergency containment.

## 33. Data Quality

| ID | Finding |
|---|---|
| DQ-87-01 | Risk Radar false auto-resolution beyond 200 requisitions (High) |
| DQ-87-02 | Duplicate "current" Hiring Health / Talent Signal snapshots |
| DQ-87-03 | Partial Hiring Memory records (no evidence or audit) |
| DQ-87-04 | Communications stuck in Queued or Sending (no sweeper) |
| DQ-87-05 | Candidate-visible timeline entry for messages that later fail or are blocked |
| DQ-87-06 | Log-transport email marked Sent |
| DQ-87-07 | Stored WhatsApp body differs from what was delivered |
| DQ-87-08 | Delivery status lost when a webhook precedes the message id |
| DQ-87-09 | Executions stuck Running for up to 60 min then Failed; AI calls stuck Approved |
| DQ-87-10 | Worker audit rows without an actor; scheduler rows without a request_id |
| DQ-87-11 | Duplicate follow-ups and timeline events on automation redo; a hold marked failed after succeeding |
| DQ-87-12 | Offer accept and expire race leaves conflicting history |
| DQ-87-13 | Interview transitions without locks: duplicate events and staff alerts |
| DQ-87-14 | Reschedule A→B→A key collision suppresses the notice |
| DQ-87-15 | Orphan external calendar events or Zoom meetings after cancellation |
| DQ-87-16 | Subject overflow gives a QueryException; `links.scheduling` never populated (always Blocked) |
| DQ-87-17 | Recruiter daily metrics disagree with governed offer dating |
| DQ-87-18 | Knowledge reindex dropped while one is running (unique lock) |

**18 findings.**

## 34. Performance

See `phase-8-7-performance.md`. **10 findings.** The biggest:
1. `intelligence:refresh` runs inline, hourly and heavily, and blocks other schedules due at :00.
2. `dispatch-alerts` is unbounded and unguarded.
3. The queue tables are unpruned and poorly indexed.
4. Zero-backoff listener retries.
5. Serial scheduler.

## 35. Scale Analysis

| Candidates | Estimate | Main pressure |
|---|---|---|
| 10k | healthy | none |
| 50k | alerts sweep slows | `dispatch-alerts` unbounded sets; notifications JSON dedupe scan |
| 100k | alert sweep ~10 s / 585 MB (P85-BACKLOG-006); refresh capped at 200 requisitions (G1 correctness risk); `jobs` table with delayed rows | serial scheduler at :00 |
| 500k | alerts sweep exceeds memory; `jobs` and `failed_jobs` growth; a single `queue` worker cannot drain alert and message bursts in minutes | needs chunked sweeps, more workers, indexed or Redis queue |

Details, including automation throughput (200 due executions per 5 min, about 2,400 an hour, from `processDue`), are in the performance report.

## 36. Test Coverage

| Workflow | Present | Missing |
|---|---|---|
| Automation duplicate / idempotency | AutomationEngineTest:75, :86; AutomationTimeBasedTest:51; SideEffectIdempotencyTest:60 | unique-index collision; real concurrency |
| Stale running / cleanup | AutomationEngineTest:270 | 60-min boundary; **`recruitment:automation:cleanup` untested** |
| Retry / partial | AutomationEngineTest:126, :150 | `RunAutomationExecutionJob::failed()`; refusing to retry a non-failed run |
| Rule deactivation after queue | AutomationEngineTest:257 (delayed) | pause or edit with a job already queued, then `handle()` |
| Owner deactivation | OwnershipHandoffTest:44, :54, :66 | suspended between queue and run; **escalation owner** |
| Stale target | AutomationEngineTest:240 | withdrawn, hired, rejected or deleted target |
| Communications | CommunicationServiceTest (8 cases); EventDrivenCommunicationTest:56, :115 | opt-out after queueing; `handle()` twice; stale listener model; reschedule A→B→A |
| AI actions | SideEffectIdempotencyTest:39, :52; ActionExecutorTest; AiIdentitySecurityTest:74-178 | concurrency; target changed before approval; crash mid-execution |
| AI jobs | AiJobsAndLoggingPrivacyTest:22, :36; IntelligenceAiTest | `failed()` handlers; summary failure path |
| Queue topology | QueueTopologyTest (compose) | `.env` `retry_after`; env queue names |
| Scheduler | **none** | schedule definitions, overlap, serial delay |
| Concurrency | **none** (`Bus::fake` unused) | locks, races (offer, interview, snapshots) |
| request_id in jobs | HTTP only (CredentialLifecycleTest:186) | propagation into jobs and scheduler |
| Payload privacy | none | serialized listener payloads |
| Provider outage / deployment | partial (retryable classification) | worker kill and redelivery |
| Browser | 8.x smokes | none asynchronous |

**Flaky:** tests/Feature/Metrics/StageHistoryEventTest.php:33. The relation's `latest('created_at')` (CandidateApplication.php:164) precedes the test's `orderBy('id')`; the fix is `reorder('id')`. It is a test-only defect, not fixed during discovery.

## 37. Existing Backlog

Sixty-one IDs were reviewed across `docs/backlog.md` and the 8.6 discovery. None was closed.

| ID | Status | Relation to 8.7 |
|---|---|---|
| P7-BACKLOG-003 SLA health `take(200)` | still valid | related (scale, §35) |
| P7-BACKLOG-005 Memory AI job never retries | still valid; confirmed, and extended to outcome insight and indexing | **8.7** |
| P7-BACKLOG-007 queued AI actor NULL | still valid; generalised to all workers | **8.7** (D8.7-015) |
| P82-BACKLOG-006 first evaluate after backfill slow | valid | related |
| P82-BACKLOG-007 insight AI no retry | valid | **8.7** |
| P83-BACKLOG-009 AI provider retries, 429, budgets | valid | related (D8.7-019) |
| P83-BACKLOG-010 notification dedupe race | valid; confirmed | **8.7** |
| P84-BACKLOG-011 Role DNA unreachable provider | valid, expected behaviour | related |
| P85-BACKLOG-005 automation `joining.risk` conditions | valid | related |
| P85-BACKLOG-006 SLA breach sweep memory | valid; confirmed | **8.7** (performance) |
| P86-BACKLOG-003 scheduler and job request_id | proposed (8.6 draft); **superseded by D8.7-014** | **8.7** |
| P86-BACKLOG-004 audit retention | valid; blocked (legal) | boundary (`failed_jobs` retention) |
| P83-BACKLOG-003 conflicting metric definitions | valid | related (§30, D8.7-025) |

All other P7, P81–P86 items (skills, fairness, AI privacy, outcome data, identity, metrics, FK hardening, org history) are **unrelated** to 8.7 and remain valid. P7-006 and P84-010 were already closed.

**New untracked gaps** (G2, G3, G4, G6, G10, G11, G13 in the evidence notes) are proposed as P87 items (§38) or backlog (decision record).

## 38. Proposed Phase 8.7 Scope

Subject to the decisions:

1. **Queue payload privacy (A, N).**
   - Queued listeners take ids, or the events use `SerializesModels`.
   - Sensitive jobs implement `ShouldBeEncrypted`.
   - Prune `failed_jobs` and batches on a schedule.
   - Redact provider error text and SQL bindings in logs.
2. **Execution-time checks (M).**
   - `SendCommunicationJob` re-checks consent, opt-out and candidate or application state per the D8.7-024 policy.
   - Listener sends re-read the record.
   - Calendar and distribution jobs re-check current status and become unique per subject.
   - Knowledge reindex re-checks published.
   - Automation re-matches scope and checks record visibility.
   - Escalations re-check owner authority and effective dates.
3. **Idempotency hardening (C).**
   - Lock offer and interview transitions (`lockForUpdate` plus a fresh status).
   - Deterministic keys for manual and AI sends (per submit token).
   - Automation followup and timeline dedupe.
   - Unique current snapshot rows.
   - Atomic Hiring Memory capture.
   - Reschedule key including a sequence.
4. **Retry governance (D).**
   - A standard policy per class.
   - Backoff and `failed()` for listeners, the handoff job and notifications.
   - Make the AI retry settings effective (rethrow retryable errors).
5. **Recovery (I).**
   - Sweepers for stuck Sending or Queued communications, Running executions (re-queue policy) and Approved AI calls.
   - Resend and retry affordances for failed messages.
6. **Scheduler (E).**
   - Overlap guards on every recurring command.
   - `onOneServer` plus documentation of a single scheduler.
   - `runInBackground` or queued work for heavy commands.
   - Per-item fault isolation.
   - Mutex expiry sized to runtime.
   - A performance-freeze catch-up (with D8.6-017).
7. **Risk Radar correctness.** Auto-resolve only for requisitions scanned in that run, and cover all open requisitions (chunked). This is a correctness fix, no semantic change.
8. **Deployment (J).**
   - `stop_grace_period` ≥ the longest worker timeout.
   - `exec` a user-switch tool, not `su -c`.
   - `.env.example` `DB_QUEUE_RETRY_AFTER`.
   - A documented drain order.
   - A queue-name guard.
9. **Observability (K, L).**
   - A request or correlation id on scheduler and `processDue` dispatches.
   - The originating request id stored on execution and communication rows.
   - A system or automation actor contract in the audit.
   - A read-only failed-jobs and stuck-work health view for admins, with redacted payloads.
10. **Metric-governance guard (Q).** Create the missing architecture test and inventory the bypasses. Changing definitions is a product decision.
11. **Tests (T).** Scheduler, concurrency, payload privacy, send-time re-check, worker redelivery, and fixing the flaky test.
12. **Runbooks (U).** Queue operations, failed-job recovery, provider outage, deployment drain.

## 39. Explicit Non-Goals

- New features, channels (push), AI capabilities, metrics or dashboards.
- Tenancy, SSO, SCIM, public API.
- A queue driver migration (Redis or Horizon) unless D8.7-027 approves it.
- Outcome, Role DNA or Hiring Memory semantics.
- Incentive formulas.
- Changing metric definitions without D8.7-025.
- Historical data repair (for example, re-opening falsely resolved risks: report only).
- Legal retention beyond `failed_jobs` operational pruning.
- A candidate portal redesign.
- The `OfferRevisionReleased` candidate notification (product feature → backlog).

## 40. Dependencies

| Dependency | On |
|---|---|
| D8.7-008 (template version at send) | D8.6-023: version foreign key on messages; `provider_template` versioning |
| D8.7-015 (actor contract) | D8.6-024: audit reason and restored or force-deleted actions (same migration surface on `audit_logs`) |
| D8.7-009 / 022 (automation versions and conflicts) | D8.6-021: priority and owner in the version; change reasons |
| D8.7-012 (failed-job retention) | P86-BACKLOG-004 / 8.8 legal retention |
| D8.7-025 | 8.5 MetricDefinition; P83-BACKLOG-003 |
| Deployment | the production host topology (cron or container), which must be confirmed by operations |
| SEC-1 hotfix | D8.6-030 (deploy) |

## 41. Open Decisions

**30:** D8.7-001 … D8.7-030 in `phase-8-7-decision-record.md`, split into **13 product-owner decisions** and **17 engineering defaults**.

## 42. Proposed Implementation Sequence

1. **Safety first:** payload privacy (listener ids, encryption, pruning, log redaction), plus the Risk Radar auto-resolve fix.
2. **Execution-time re-checks:** communications, listeners, calendar, distribution, automation scope and escalation.
3. **Idempotency:** locks and keys.
4. **Retry and failure handlers,** sweepers and resend.
5. **Scheduler hardening** and deployment and worker lifecycle.
6. **Correlation id** and actor contract (after 8.6's `audit_logs` changes, or combined with them).
7. **Admin health view** (read-only).
8. **Metric guard test.**
9. **Tests**, security review, performance measurement, runbooks, freeze.

## 43. Migration Considerations

All additive and nullable:

| Table | Change |
|---|---|
| `audit_logs` | `+ actor_kind` (system / automation / scheduler) and `+ on_behalf_of_user_id`; or reuse the morph columns, per D8.7-015 |
| `automation_executions` | `+ origin_request_id` |
| `candidate_communications` | `+ origin_request_id` |
| `jobs` | `+ index (queue, reserved_at, available_at)`, if D8.7-027 keeps the database driver |
| `hiring_health_snapshots` / `talent_signal_snapshots` | a unique "current" constraint. Needs a de-duplication step first: **a data change, requiring approval (stop condition 13 check)**. Alternative: a lock-only fix with no constraint. |

**No destructive migration is proposed.**

## 44. Rollback Considerations

- **Code-only items** (re-checks, locks, retry, scheduler, sweepers) roll back by redeploying.
- **Encrypted payloads** (`ShouldBeEncrypted`): the framework decrypts them regardless of the class version, so a rollback is safe while `APP_KEY` is unchanged. Drain the queues before any rollback that removes or renames a job class.
- **Listener payload shape change:** drain the `communications` and `intelligence` queues before deploying, because old payloads contain models that the new code must still accept. Keep the handlers backward-compatible for one release.
- **Additive columns** stay on rollback.
- **Pruning** is irreversible for pruned rows, so the retention window is a product decision (D8.7-012).

## 45. Operational Runbook Requirements

1. **Queue topology:** which worker consumes which queue; scaling; the `retry_after` rule.
2. **Deployment:** stop workers → migrate → deploy → `queue:restart` → start; the grace period; verify with `schedule:list` and the worker queues.
3. **Failed jobs:** inspect (redacted), retry, forget; the pruning window.
4. **Stuck work:** communications in Sending or Queued, executions in Running, AI calls in Approved; the automation cleanup.
5. **Provider outage:** mail, SMS, WhatsApp, calendar, AI: expected behaviour, when to pause automation, how to resend.
6. **Scheduler:** single scheduler rule; clearing a stale `withoutOverlapping` mutex; manually running missed daily jobs (freeze, expiry).
7. **Access changes:** handoff failure recovery.
8. **Monitoring:** queue depth, oldest job age, `failed_jobs` count, stuck counts, scheduler heartbeat.

## 46. Release Criteria

- All in-scope items implemented with tests: concurrency, redelivery, scheduler, privacy.
- Mutation checks on the re-checks and locks.
- **No change** to Outcome, Role DNA, Memory, lifecycle or identity semantics, or to metric definitions (unless D8.7-025 is approved and versioned).
- **No sensitive model data** in a newly queued payload, proven by a test that inspects serialized payloads.
- The full suite green, serial and parallel, **including the flaky test fixed**.
- Browser smokes 6 → 8.7.
- Performance measured for the alert sweep, refresh and queue depth.
- Runbooks written; backlog updated; clean tree.
- SEC-1 hotfix deployment decided (D8.6-030).

## 47. Final Recommendation

**Proceed to Phase 8.7 implementation after approving the decision record.**

**Priorities:**
1. **Priority 1:** queue payload privacy (SEC-87-01, 02) and the Risk Radar auto-resolve defect (DQ-87-01).
2. **Priority 2:** send-time consent and state re-checks (product decision D8.7-024) and automation or escalation authority re-checks.

The remainder is hardening, observability and operations.

**Sequencing with 8.6:** 8.6 is approved but not implemented. Implement 8.6 first, or interleave the shared `audit_logs` migration, so the audit model changes land once.
