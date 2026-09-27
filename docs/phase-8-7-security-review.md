# Phase 8.7 Security Review: Asynchronous Paths

**Part A** records the implementation (baseline `fa4e858`, frozen at the Phase 8.7 freeze commit). **Part B** is the discovery review exactly as committed in `88fbcf6` (baseline `dcff76e`).

# PART A: IMPLEMENTATION

**Result: 0 Critical · 0 High · 0 Medium open. All 14 discovery findings are closed. Two further findings made during implementation are fixed. No new High or Critical issue was found.** The Phase 8.6 production hotfix (D8.6-030) is still a separate, pending release action.

## A.1 Discovery findings → status

| ID | Severity | Status | Fix | Regression test |
|---|---|---|---|---|
| SEC-87-01 | High | **Closed** | events use `SerializesModels` (ids only); queued listeners, notifications and mails encrypted; failed jobs pruned after 30 days; stored exception text redacted | `QueuePayloadPrivacyTest` (reads real `jobs` payloads; arch test on all events and queued classes) |
| SEC-87-02 | Medium | **Closed** | encrypted `ResetPassword` / `NoticeOfEmailChangeRequest` on `notifications` | `QueuePayloadPrivacyTest` "password-reset and email-change notices…" |
| SEC-87-03 | Medium | **Closed** | `SendTimeGuard` re-checks consent at claim; suppressed as Blocked, audited, not a failure | `CommunicationDeliveryIntegrityTest` "opts out after…" (+ browser 9) |
| SEC-87-04 | Medium | **Closed** | listeners re-read (ids) and skip stale announcements; `SendTimeGuard` compares queue-time state (application, interview, offer, joined, template) | `CommunicationDeliveryIntegrityTest` (application, interview, offer, joined, archived template) |
| SEC-87-05 | Medium | **Closed** | run-time scope and visibility re-check; per-action owner permission | `AutomationExecutionAuthorityTest` (scope, visibility, lost permission) |
| SEC-87-06 | Medium | **Closed** | escalations re-check effective dates, owner authority, scope, visibility, send permission and the message cap; act as automation on the owner's behalf | `AutomationExecutionAuthorityTest` (three escalation tests) |
| SEC-87-07 | Medium | **Closed** | log tap on every channel, failed-job store and `failed()` columns redacted | `SensitiveDataRedactionTest` (6 tests) |
| SEC-87-08 | Low | **Closed** | AI jobs reload the requester; skip with `ai_request_skipped` when access is gone | `CorrelationAndActorTest` "requester lost access" |
| SEC-87-09 | Low | **Closed** | reindex unique until processing; unpublished articles skipped; model dispatches after commit | existing knowledge tests + `QueueContractTest` |
| SEC-87-10 | Low | **Closed** | calendar job derives cancel from current state; unique per interview; no overlap | `TransitionIdempotencyTest` "calendar job decides…" |
| SEC-87-11 | Low | **Closed** | deterministic keys for manual and AI sends | `CommunicationDeliveryIntegrityTest` "double-submitted…" |
| SEC-87-12 | Low | **Closed** | portal password link queued and encrypted (same response path either way) | `QueuePayloadPrivacyTest` "portal password link…", `CandidatePortalTest` |
| SEC-87-13 | Info | **Closed** | `actor_kind`, `on_behalf_of_user_id`, correlation ids | `CorrelationAndActorTest` |
| SEC-87-14 | Info | **Closed (documented)** | `AiCopilotEmail` encrypted on `notifications`; the unused providers are documented and listed for removal (P87-BACKLOG-007) | `QueuePayloadPrivacyTest` arch test |

## A.2 Findings made during implementation

| ID | Severity | Finding | Status |
|---|---|---|---|
| SEC-87-I-01 | Low | `RunAutomationExecutionJob::failed()` and `SendCommunicationJob::failed()` copied raw exception text (which can hold SQL values such as an email) into staff-visible columns | **Fixed** (`e1c9144`); `SensitiveDataRedactionTest` |
| REL-87-I-02 | (reliability) | the `notifications` queue added earlier in this phase had no consumer in `docker-compose.yml` — in-app alerts and auth mails would never be delivered. Branch only, never deployed | **Fixed** (`e974ebf`); `QueueTopologyTest` now scans notifications and mailables |

## A.3 New surfaces introduced by Phase 8.7

| Surface | Control | Evidence |
|---|---|---|
| Queue health page | `settings.manage`; shows class names, counts and the redacted first line of an error — never payloads | `QueueHealthTest`, browser 1–3, 13–15 |
| Failed-job Retry | `settings.manage`, reason required, audited (`failed_job_retried`); retried jobs re-check their own state | `QueueHealthTest`, browser 4 |
| `GET /health/queue` | signed-in `settings.manage`, or `QUEUE_HEALTH_TOKEN` bearer compared with `hash_equals`; throttled 60/min; counts only; 401 anonymous, 403 others | `QueueHealthTest`, browser 5, 6, 15 |
| Resend | policy ability `resend` (`communications.send` + candidate visibility); consent and state re-checked; reason audited | `CommunicationDeliveryIntegrityTest`, browser 7 |
| Platform alerts | only to holders of `settings.manage` with current access; no personal data | `QueueHealthTest` |
| Actor attribution | automation never recorded as its owner (`user_id` null, owner as `on_behalf_of`) | `CorrelationAndActorTest` |

## A.4 Controls re-verified

- Phase 8.6 controls intact: strict authorization in tests, fail-closed gate, master-data guards, audit reasons — the whole suite (1,873 tests) runs under them.
- AI egress guard on every asynchronous call; jobs never use a provider directly (`QueueContractTest`).
- All events dispatch after commit (`QueueContractTest`).

## A.5 Stop-condition check (implementation)

| # | Condition | Result |
|---|---|---|
| 4 | New Critical/High vulnerability | **No** |
| 5 | Queue executes a security-sensitive action without authorization | **No**: automation, escalations and AI jobs re-check authority at execution |
| 6 | Communication path leaks sensitive info | **No**: payloads encrypted or ids-only; logs and stored errors redacted |
| 7 | Irreversible duplicate business effects | **No**: transitions locked, jobs unique/state-aware, deterministic keys |
| 8 | Historical record rewritten asynchronously | **No**: Risk Radar resolves only after a full scan (DQ-87-01 fixed) |

---

# PART B: DISCOVERY REVIEW (as committed in `88fbcf6`)

## Phase 8.7 Security Review: Asynchronous Paths (Discovery)

**Baseline:** HEAD `dcff76e` · **Scope:** jobs, listeners, scheduler, automation, communications, AI asynchronous work, queue storage, logs · **Status:** findings only; nothing fixed.

**Summary: 14 findings. 0 Critical · 1 High · 6 Medium · 5 Low · 2 Informational.**

- No finding is an actively exploitable production vulnerability that needs emergency containment (stop condition 4 is not reached).
- SEC-87-01 is the closest: it needs read access to the database or backups.
- Separately, SEC-1 from 8.6 is still awaiting the hotfix deployment (D8.6-030).

---

### SEC-87-01: Sensitive model data serialized into queue payloads and retained indefinitely (HIGH)

| | |
|---|---|
| Component | Queued listeners: `SendCandidateCommunications`, `CaptureHiringMemory`, `RecordHiringOutcomes`. All 36 events. `failed_jobs`. |
| Evidence | Events use `Dispatchable` only, with no `SerializesModels` (e.g. app/Events/OfferStatusChanged.php:16-26). `CallQueuedListener` uses only `InteractsWithQueue, Queueable` (vendor Events/CallQueuedListener.php:14). Offer compensation fields: Offer.php:48, :55. Candidate salary fields: Candidate.php:35-36. Nothing implements `ShouldBeEncrypted`. `failed_jobs` is never pruned (routes/console.php). |
| Impact | Every offer or stage event writes the whole Offer, Application, Interview and Employee models, plus relations already loaded, as plain PHP-serialized data into `jobs.payload`. That includes CTC, fixed, variable and bonus amounts, the offer letter body, candidate email, mobile, and current and expected salary. On failure the data is copied into `failed_jobs.payload` and kept forever. It bypasses `compensation.view` (8.5 SEC-2) at rest and increases exposure through backups and DB replicas. |
| Affected roles | Anyone with DB, backup or log-shipping access. No in-app role (there is no UI for `jobs` or `failed_jobs`). |
| Affected records | Offers, candidates, applications, interviews, employees |
| Current control | Database access control only |
| Missing control | Ids-only payloads, or `SerializesModels`; encryption; `failed_jobs` retention |
| Recommendation | Listeners receive ids, or events use `SerializesModels` (which stores only the id). `ShouldBeEncrypted` on communication and offer paths. Schedule `queue:prune-failed` (window: D8.7-012). A test that serializes each queued payload and asserts no compensation or contact fields. |
| Implementation required | Yes |
| Blocks 8.7 | **Yes** (priority 1) |

### SEC-87-02: Bearer-token URLs in queued auth notifications (MEDIUM)

| | |
|---|---|
| Component | Filament `ResetPassword`, `VerifyEmailChange`, `NoticeOfEmailChangeRequest` (all `ShouldQueue`, on `default`) |
| Evidence | vendor filament Auth/Notifications/ResetPassword.php:9; AdminPanelProvider.php:48 (`->passwordReset()`); CredentialService.php:93-97 |
| Impact | A plaintext reset token or signed URL sits in `jobs` until processed and permanently in `failed_jobs` if the job fails. Anyone who can read either table within the token lifetime can take over a staff account. |
| Affected roles | DB or backup readers. The target is any staff account. |
| Current control | Token time-to-live; DB access control |
| Missing control | Payload encryption; pruning; send synchronously |
| Recommendation | Send these synchronously (as the staff invite already is), or wrap them with encryption. Prune `failed_jobs`. |
| Implementation required | Yes · **Blocks 8.7:** yes |

### SEC-87-03: Candidate consent and opt-out not re-checked at send time (MEDIUM)

| | |
|---|---|
| Component | `SendCommunicationJob` |
| Evidence | The job checks status and provider only (Jobs/SendCommunicationJob.php:60-70). Preferences are checked at queue time (CommunicationService.php:~102). A STOP webhook records the opt-out but does not cancel rows already Queued. The retry span is about 42 min (config/communications.php:23-24). |
| Impact | An SMS or WhatsApp message goes out after the candidate replied STOP or withdrew consent. That is a consent and compliance exposure, not a data leak. |
| Affected records | candidate_communications |
| Recommendation | Re-evaluate preferences and consent in `claim()`, and move the row to Blocked. Cancel Queued rows when an opt-out arrives. |
| Implementation required | Yes · **Blocks 8.7:** yes. The policy for other state changes is product decision D8.7-024. |

### SEC-87-04: Queued listener sends from a stale model snapshot (MEDIUM)

| | |
|---|---|
| Evidence | Listeners/SendCandidateCommunications.php:45-72 use `$event->interview` and `$event->offer` as serialized at dispatch |
| Impact | With a communications backlog, the candidate gets "interview scheduled" after a cancellation, or "offer released" after a withdrawal, with stale date and time details. That is misinformation to candidates, and the stale content is rendered and stored. |
| Recommendation | Re-read by id and check the current status before sending (with SEC-87-01). |
| Implementation required | Yes · **Blocks 8.7:** yes |

### SEC-87-05: Automation acts outside the owner's current record scope (MEDIUM)

| | |
|---|---|
| Evidence | Scope is matched at trigger time only (AutomationEngine.php:87). `perform` (:433-481) re-checks the owner at *scope* level (AutomationRuleService.php:110-121) but not the specific record's visibility, a scope re-match, or per-action permissions. Actions run with a null actor. |
| Impact | If a record moves out of the rule's scope (requisition reassigned, department changed) between trigger and run, a delayed execution still messages the candidate or moves the stage under the old owner's authority. This is a hierarchy bypass within delay windows. |
| Affected roles | Managers or assistant managers owning team-scope rules |
| Recommendation | Re-match scope and check `HierarchyService::canView(owner, record)` in `perform`; check each action's required permission. |
| Implementation required | Yes · **Blocks 8.7:** yes (D8.7-010 / 016) |

### SEC-87-06: Escalations run without an owner authority re-check (MEDIUM)

| | |
|---|---|
| Evidence | EscalationService.php:86-110 checks only `rule->isActive()`: no effective dates and no `AutomationRuleService` owner check. It runs inline in `recruitment:automation:process`. The candidate-template escalation skips the daily message cap (the cap exists only in SendCommunicationAction). |
| Impact | Escalation messages and notifications continue after the rule owner is suspended or revoked, until the ownership handoff pauses the rule. If the handoff job fails, there is no `failed()` and the escalations continue indefinitely. |
| Recommendation | Apply the same `perform` guards and the message cap to escalations. |
| Implementation required | Yes · **Blocks 8.7:** yes |

### SEC-87-07: Personal data in logs and exception traces (MEDIUM)

| | |
|---|---|
| Evidence | SendCommunicationJob.php:102 throws with provider error text (it can include the phone number or email address), written to `laravel.log` and `failed_jobs.exception` on every attempt. `CommunicationService::sendAutomatic` `report($e)` (:195): a QueryException includes SQL bindings (rendered body, recipient). No redaction processor (config/logging.php). `MAIL_MAILER=log` (.env.example:51) writes full candidate emails and set-password, invitation and email-change links to the log. |
| Impact | PII and live account links in log files and log shipping |
| Recommendation | A log redaction processor. Catch `UniqueConstraintViolation` explicitly. Truncate provider errors to codes. Refuse the `log` mailer in production (boot check or health warning). |
| Implementation required | Yes · **Blocks 8.7:** no (should-fix) |

### SEC-87-08: AI jobs do not re-check the requesting user (LOW)

GenerateRoleDnaSuggestionsJob.php:54 and the Summarize* jobs load the user by id only.
- **Impact:** a suspended user's request still completes. The output is suggestions or summaries only: unconfirmed and gated by the egress guard.
- **Recommendation:** skip when the requester is no longer permitted (`StaffAccessService::permits`), and audit the skip.
- **Blocks 8.7:** no.

### SEC-87-09: Unpublished knowledge article remains indexed (LOW)

`ReindexKnowledgeArticleJob` does not re-check `is_published`, and its `ShouldBeUnique` lock drops a save made while a reindex is running.
- **Impact:** Copilot can retrieve withdrawn internal guidance for staff.
- **Recommendation:** re-check `is_published`; use `ShouldBeUniqueUntilProcessing`.
- **Blocks 8.7:** no.

### SEC-87-10: Orphan external calendar events after cancellation (LOW)

`SyncInterviewCalendarJob` is not unique, fixes its operation at dispatch, and does not check interview status. A retried `create` after a `cancel` creates an external event or Zoom meeting carrying the candidate's email (CalendarSyncService.php:43-75, :147).
- **Recommendation:** make the job unique per interview and provider, and re-check status.
- **Blocks 8.7:** no.

### SEC-87-11: Manual and AI sends are not idempotent (LOW)

A random UUID idempotency key is used (CommunicationService.php:66), so a double submit sends twice.
- **Recommendation:** a key per submit or approval.
- **Blocks 8.7:** no.

### SEC-87-12: Portal password-link timing reveals which emails have accounts (LOW)

`CandidatePortalService::sendPasswordLink` sends synchronously only when an account exists, so response times differ.
- **Recommendation:** queue it, or pad the response time.
- **Blocks 8.7:** no.

### SEC-87-13: No actor on asynchronous audit rows (INFORMATIONAL)

`AuditLog::record` uses `auth()->user()` (AuditLog.php:46), which is NULL in workers and the scheduler. This covers automation, cron, queued AI (P7-BACKLOG-007) and scheduler request ids.
- **Impact:** forensic traceability, not access.
- **Recommendation:** D8.7-015 / 014.

### SEC-87-14: Dead AI communication providers (INFORMATIONAL)

`app/Services/AI/Communication/*` and the queued `AiCopilotEmail` are bound in AiServiceProvider.php:73-75 but never resolved. If they were ever wired, `AiCopilotEmail` would put its body on `default`.
- **Recommendation:** remove it, or document it as unused.

---

## Controls verified as intact

| Control | Evidence |
|---|---|
| After-commit dispatch for all domain events; no side effect before commit | §18 of the discovery report |
| AI action exactly-once, expiry, and authority invalidation | ActionExecutor.php:241-274; InvalidateAiActionsOnAuthorityChange |
| AI egress guard on every gateway method, including queued jobs | AiGateway.php:57, 80, 104 |
| Automation owner re-check and pause (8.4) | AutomationEngine.php:445-450 |
| Signed provider webhooks with replay protection | CommunicationWebhookController; unique `(provider, provider_event_id)` |
| No compensation merge variables in templates | TemplateRenderer.php:17-35 |
| No in-app exposure of `jobs` or `failed_jobs` | no resource or page |
| No automation action that is financial or security/access | AutomationActionRegistry.php:26-36 |

## Stop-condition check

| # | Condition | Result |
|---|---|---|
| 4 | Critical production vulnerability | **No** |
| 5 | Queue executes a security-sensitive action without authorization | **Partially:** SEC-87-05 and 06 act under stale or partial authority. The actions are communications and early-stage moves, not access or financial. Documented; D8.7-010 / 016. |
| 6 | Communication path leaks sensitive info | **At rest only** (SEC-87-01, 02, 07). No disclosure to an unauthorized recipient was found. |
| 7 | Irreversible duplicate business effects | Offer accept/expire race (conflicting terminal states); orphan external calendar events. Documented. |
| 8 | Historical record rewritten asynchronously | **Yes:** Risk Radar false auto-resolution (DQ-87-01). Documented, not fixed. |
