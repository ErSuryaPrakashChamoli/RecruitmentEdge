# Phase 8.7 Implementation: Platform Reliability, Queue, Automation & Communications Integrity

**Principle:** a queued job is a request to attempt an operation later — not permission to perform it later. Every asynchronous operation re-checks, when it runs, that it is still allowed and still makes sense.

**Scope:** implements the 30 decisions in `phase-8-7-decision-record.md` (13 product-owner decisions at their recommended options, 17 engineering defaults). Findings and evidence are in `phase-8-7-discovery.md`, `phase-8-7-security-review.md` and `phase-8-7-performance.md`. Nothing from Phase 8.8 is implemented.

**Baseline:** `feature/sep_25_hrm` @ `fa4e858` (Phase 8.6 freeze): 158 migrations, 235 routes, 1,798 tests / 19,922 assertions.

## 1. Decisions → implementation

### Queue payloads, logs and failed jobs

| Decision | Implementation |
|---|---|
| D8.7-017, SEC-87-01 | All 36 events use `SerializesModels` (payloads hold ids, models are re-read when the listener runs). The three queued listeners, `StaffDatabaseNotification`, the auth notifications, `AiCopilotEmail` and `CandidatePortalLink` implement `ShouldBeEncrypted`. |
| SEC-87-02 | `App\Notifications\Auth\ResetPassword` and `NoticeOfEmailChangeRequest` (encrypted, `notifications` queue) replace Filament's classes through container bindings in `AppServiceProvider::register()`. |
| D8.7-001 | New `notifications` queue for in-app alerts and auth/portal mails. `default` stays empty. |
| D8.7-012 | `failed_jobs` pruned daily after `QUEUE_FAILED_RETENTION_HOURS` (720 = 30 days). Queue health page lists them (class, queue, time, redacted first line) with an audited Retry. |
| SEC-87-07 | `SensitiveDataRedactor` + `RedactSensitiveData` tap on every log channel: emails, phone numbers, bearer/basic credentials, token/signature/password parameters, provider keys, message bodies in SQL bindings; sensitive context keys replaced whole. `RedactingFailedJobProvider` redacts the exception text stored in `failed_jobs`. Exception text copied into `automation_executions.failure_reason` and `candidate_communications.error` by `failed()` handlers is redacted too. |

### Execution-time authorization and state (D8.7-010, 016, 023, 024; SEC-87-03…06, 08)

- **Communications** — `SendTimeGuard`: when `SendCommunicationJob` claims a message it compares what the message was about when queued (`metadata.queued_state`: ids and statuses only) with the records now. It suppresses the message — status **Blocked**, `blocked_reason`, audit `communication_suppressed`, an internal timeline note — when:
  1. the candidate opted out of, or has not consented to, the channel;
  2. the application was open when queued and has since closed;
  3. the interview was cancelled, completed, marked no-show or moved (the cancellation notice itself is exempt);
  4. the offer was withdrawn, expired, declined or sent back to draft;
  5. the candidate joined and it is a recruitment-stage template (keys starting `joining`/`onboarding` still go);
  6. its template was archived after it was queued (D8.7-008 c). An edit after queueing does not change the stored content.
  Suppression is a policy outcome: it never fires `CommunicationFailed` and never counts as a provider failure.
- **Listeners** re-read the interview/offer and skip announcements that no longer apply (8.7 part of SEC-87-04).
- **Automation** (`AutomationEngine::perform`): after the 8.4 owner check, the record must still be in the rule's scope and visible to the owner (`AutomationScopeResolver::outsideAuthority`) — otherwise the run is **Skipped** and audited `automation_skipped_authority`. Each action needs its permission (`AutomationActionRegistry::PERMISSIONS`: send → `communications.send`, move/hold → `pipeline.transition`, action item → `actions.manage`, follow-up → `followups.manage`, timeline note → `candidates.update`); a refused action is Skipped with the permission named; a run whose every action was refused is Skipped.
- **Escalations** (`EscalationService::process`): re-check effective dates, the owner's authority (pausing the rule), scope and visibility; candidate messages need `communications.send` and count toward the daily cap; run under `AuditLog::asActor('automation', owner)`.
- **processDue** pauses active rules whose owner lost authority, even when the 8.4 handoff never ran.
- **AI jobs** (`RunsForRequester`): the requester is reloaded; if `StaffAccessService` no longer permits them the job audits `ai_request_skipped` (actor `ai`) and does nothing.

### Idempotency and locking (D8.7-005, D8.7-022, D8.7-026)

- Offer `moveTo` and every interview transition (reschedule, cancel, hold, no-show, confirm, complete) take the row lock and decide on the **current** status inside the transaction. A stale copy is refused.
- Staff alerts for offer and interview changes carry dedupe keys (offer keys include the history position, so a re-released revised offer still alerts). `NotificationDispatchService::alert` claims each key atomically.
- Manual and AI sends without a caller key get a deterministic key (sender, candidate, application, channel, template, content, five-minute window). A blocked attempt can be retried.
- Reschedule notices are keyed by interview, new time and reschedule time (fixes the A→B→A collision).
- `SyncInterviewCalendarJob` decides create/update vs cancel from the interview as it is when it runs, is unique per interview until processing and never overlaps per interview. `PublishJobDistributionJob` is unique per distribution and operation, never overlaps per distribution, and skips operations the posting no longer calls for.
- The automation daily message cap is counted and sent under a per-candidate lock.
- Automation redo: `create_followup` and `add_timeline_event` find their own earlier result (run reference); `hold_application` reports an existing hold as done.
- Hiring Memory capture writes record, evidence and audit in one transaction. Hiring Health and Talent Signal refreshes serialise on the parent row.
- Knowledge reindex is `ShouldBeUniqueUntilProcessing` and skips unpublished articles (SEC-87-09).

### Retry, backoff, failure and recovery (D8.7-003, 004, 013, 019)

| Class | Tries | Backoff (s) | `failed()` |
|---|---|---|---|
| Candidate messages | 5 | 30, 120, 600, 1800 (unchanged) | marks the row Failed (redacted), audits |
| Listeners | 3 | 10, 60 | logs event class and exception class |
| Notifications | 3 | 10, 60 | logs |
| Ownership handoff | 3 | 30, 120 | audits `ownership_handoff_failed`, platform alert |
| Automation run | unchanged | unchanged | marks the run Failed (redacted) |
| AI summaries / Role DNA | unchanged | unchanged | audits `*_ai_failed`; provider outages now rethrow so `tries` applies |
| Calendar / distribution | unchanged | unchanged | logs; distribution marks a pending row Failed |

- **Circuit breaker** (`ProviderCircuitBreaker`): 5 temporary failures in 5 minutes pause a provider for 5 minutes. Messages stay **Queued** without spending attempts.
- **`reliability:sweep`** (every 5 min): re-queues messages Queued for over 10 minutes (lost job or held by the breaker; the job is unique and claims under a lock, so nothing is sent twice); fails messages stuck **Sending** for 30 minutes ("delivery state unknown", never resent); fails AI actions stuck **Approved** for 30 minutes with an honest note.
- **Stuck automation runs** (`processDue`): re-queued once if no action had started, otherwise Failed for a person to retry.
- **Resend** (D8.7-007 a): failed or bounced messages have a Resend action (policy ability `resend` = `communications.send` + candidate visibility). It creates a new message with the stored content, key `resend:{id}:{n}`, current contact details, full consent and state re-check, audited with a reason.
- **`delivered_externally`** (D8.7-007 c): recorded when a provider accepts a message; a log or array mailer shows "No — not delivered externally".
- **Early webhooks** (DQ-87-08): a status for a message id not yet saved is held for an hour and applied when the job records the id.

### Scheduler and workers (D8.7-002, 011, 020)

- Every scheduled task: `withoutOverlapping(<minutes sized to the task>)` and `onOneServer()`. `performance:snapshot`, `intelligence:refresh` and `outcomes:evaluate` run in the background. One scheduler (`schedule:work` service); `routes/console.php` says so.
- Per-item isolation: one failing item never stops incentive release, offer expiry, stale-posting closure, recruiter snapshots, reminders, rule sweeps or alert checks.
- Heartbeat: every task's outcome (finished / failed / skipped) and the last tick, in the cache (`SchedulerHeartbeat`).
- Workers: `queue` (communications, notifications, default; timeout 120), **new** `queue-automation` (automation, default; timeout 120), `queue-background` (intelligence, integrations, default; timeout 300). `stop_grace_period: 330s` on all workers and the scheduler. The entrypoint uses `exec setpriv` (not `su -c`), so SIGTERM reaches the worker. `DB_QUEUE_RETRY_AFTER=330` everywhere (also the config default now); `queue.worker_max_timeout` = 300.
- **Found while implementing:** the `notifications` queue introduced earlier in this phase had no consumer in `docker-compose.yml`. Fixed before freeze; `QueueTopologyTest` now scans notifications and mailables.

### Observability (D8.7-014, 015, 021, 028)

- **Correlation ids:** HTTP requests keep their `request_id`; artisan and scheduled commands get `cmd:<uuid>`; jobs carry the dispatching id (Laravel Context) or get `job:<uuid>`. `automation_executions` and `candidate_communications` store `origin_request_id`.
- **Actor attribution:** `audit_logs.actor_kind` (user, candidate, automation, ai, scheduler, console, queue, system) and `on_behalf_of_user_id`. Automation acts on its owner's behalf (`user_id` null), AI on the requester's. The audit log shows and filters by actor.
- **Queue health page** (Administration → Queue health, `settings.manage`): per-queue depth and oldest age, failed jobs, stuck messages / runs / AI actions, paused providers, scheduler heartbeat; audited retry.
- **`GET /health/queue`**: JSON for a signed-in administrator or a `QUEUE_HEALTH_TOKEN` bearer; 503 while anything needs attention; counts only.
- **`queue:health-check`** (every 5 min): in-app alerts to `settings.manage` holders (`PlatformAlertService`), one per problem per hour, when a job failed in the last hour, a queue's oldest job waited over 15 minutes, work has been stuck over 30 minutes, the scheduler has been silent over 15 minutes, a provider is paused, or `retry_after` is not above the worker timeout.

### Risk Radar (DQ-87-01, D8.7-026, D8.7-027)

- The scan covers **every** open requisition in chunks of 200; joining and offer risks have no row cap. Auto-resolution runs only after the whole population was evaluated. A full scan holds a lock; a concurrent one is skipped. `intelligence:refresh` refreshes all open requisitions (per-item isolation; `--limit` is manual only). `intelligence.requisitions_per_run` removed.

### Metric governance (D8.7-025 a)

- `MetricGovernanceArchitectureTest` (the guard `MetricRegistry` cites) inventories the raw counters: consumers of `RecruiterDailyMetricsService` and of the raw vacancy-ageing / SLA-breach sweeps. A new consumer fails the test. The incentive "offers" input is pinned to its current definition (offer date).
- **Not done (b):** aligning recruiter daily metrics with the governed first-release definition changes performance and incentive inputs. It needs product and payroll approval with a versioned effective date (stop conditions 11 and 14).

### Portal (DQ-87-05, SEC-87-12)

- The portal timeline shows a message's entry only once the message went out (filtered when reading; events stay append-only).
- `CandidatePortalLink` is queued (encrypted), so "forgot password" takes the same time whether or not an account exists.

## 2. Migrations (158 → 160, additive)

| Migration | Change |
|---|---|
| `2026_09_27_161218_add_async_actor_and_origin_request_columns` | `audit_logs.actor_kind` (string 20, nullable), `audit_logs.on_behalf_of_user_id` (FK `audit_logs_on_behalf_of_fk`, null on delete); `origin_request_id` (string 64, indexed) on `automation_executions` and `candidate_communications` |
| `2026_09_27_162311_add_delivered_externally_to_candidate_communications` | `candidate_communications.delivered_externally` (boolean, nullable) |

Both are nullable, reversible, and not back-filled: rows written before 8.7 have no actor kind, origin id or external-delivery flag.

## 3. New classes and commands

| Class / command | Purpose |
|---|---|
| `Logging\SensitiveDataRedactor`, `RedactSensitiveData`, `RedactingFailedJobProvider` | central redaction |
| `Jobs\Concerns\RunsForRequester` | AI job requester re-check and failure audit |
| `Notifications\StaffDatabaseNotification`, `Notifications\Auth\*` | encrypted notifications on `notifications` |
| `Services\Communication\SendTimeGuard` | queue-time snapshot and send-time suppression |
| `Services\Communication\ProviderCircuitBreaker` | provider pause and hold |
| `Services\PlatformAlertService` | platform alerts to `settings.manage` holders |
| `Services\SchedulerHeartbeat` | scheduler outcomes and last tick |
| `Services\QueueHealthService` | queue, failed-job, stuck-work and heartbeat facts; alert thresholds |
| `Filament\Pages\QueueHealth`, `Http\Controllers\QueueHealthController` | page and endpoint |
| `reliability:sweep`, `queue:health-check` | recovery and alerting commands (scheduled every 5 min) |

## 4. Authorization summary

| Surface | Who |
|---|---|
| Queue health page, retry of a failed job | `settings.manage` |
| `GET /health/queue` | `settings.manage`, or the `QUEUE_HEALTH_TOKEN` bearer |
| Resend a message | `communications.send` and visibility of the candidate (`CandidateCommunicationPolicy::resend`) |
| Platform alerts | holders of `settings.manage` with current access |
| Automation actions at run time | the rule owner, per action permission |

## 5. Tests

- New: `tests/Feature/Reliability/*` (10 files), `tests/Feature/Metrics/MetricGovernanceArchitectureTest.php`, additions to `QueueTopologyTest`, `AutomationUiTest`, `CandidatePortalTest`, `CredentialLifecycleTest`.
- Existing tests updated for approved behaviour changes (each named after the decision):
  - `AutomationEngineTest`: a run kept on the old version now passes `keepPendingRuns: true` (D8.7-009 c); the interrupted-run test has a started action (D8.7-013 re-queues a run with none).
  - `CandidatePortalTest`: the portal link is queued, not sent inline (SEC-87-12).
  - `CredentialLifecycleTest`: uses the encrypted `ResetPassword` subclass (SEC-87-02).
  - `AutomationRuleFactory`: owners hold the permissions their actions need (D8.7-010 b).
- Test-only fix: `ConversionMetricsTest` placed an "upcoming" interview two hours ahead, which fails between 22:00 and midnight IST; it now runs at midday IST. The `StageHistoryEventTest` ordering flake from discovery was fixed in 8.6 (`a6aaffa`) and passed 8 of 8 repeated runs here.
- The 23 required test categories and the mutation checks are listed in `phase-8-7-freeze.md`.

## 6. Known limitations (accepted, documented)

- **Recruiter daily metrics** keep dating offers by `offer_date` (D8.7-025 b needs approval).
- **`{{links.scheduling}}`** is still never supplied, so templates using it are Blocked (honestly). Putting a signed self-scheduling link into stored message bodies is a product and security decision (DQ-87-16, deferred).
- **WhatsApp stored body** is the locally rendered text; the provider sends its approved template with the same parameters (DQ-87-07, inherent, documented).
- **Queue driver** stays `database` (D8.7-027). Supported scale (measured, `phase-8-7-performance.md`): up to 100k applications and about 500 open requisitions on three workers; beyond that, Redis and more workers.
- **Historical rows** are not back-filled (actor kind, origin id, external delivery, queued state). Messages still queued at deploy time have no `queued_state`: consent, archived template, closed application and cancelled / completed / no-show interview still suppress them; the interview-moved, offer and candidate-joined checks need the snapshot and do not apply to them. Drain the communications queue before deploying to avoid the gap.
- **Concurrency tests** in the suite reproduce races with stale copies of a record and held locks on SQLite. Real multi-process races rely on MySQL row locks (`lockForUpdate`) and cache locks; no multi-process load test was run.
- **Circuit breaker, heartbeat and alert dedupe** use the cache store: the production cache must be shared by all workers (the database cache is).

## 7. Deployment and rollback

The full procedure is in `docs/runbooks/queue-operations.md` §1. In short:

1. `php artisan queue:restart`, then wait until `jobs` holds no reserved rows (the new listener, notification and mail payloads are encrypted; old payloads still decrypt).
2. Deploy code; `composer install --no-dev`; `npm ci && npm run build`.
3. `php artisan migrate --force` (two additive migrations).
4. `php artisan optimize:clear && php artisan optimize`.
5. Set `DB_QUEUE_RETRY_AFTER=330`, optionally `QUEUE_HEALTH_TOKEN`; ensure `QUEUE_WORKER_MAX_TIMEOUT` matches the longest `--timeout`.
6. Start the three workers (`queue`, `queue-automation`, `queue-background`) and **one** scheduler. Remove any cron `schedule:run`.
7. Check Administration → Queue health: all queues moving, scheduler heartbeat present after five minutes.

**Rollback:** redeploy the previous code (its compose file has two workers; drain `notifications` and `automation` first); `php artisan migrate:rollback --step=2` is optional — the columns are nullable and ignored by older code.

### Production security hotfix (D8.6-030) — unchanged

`hotfix/filament-delete-authorization` @ `2fab3fd` was **not modified, not pushed and not deployed** in Phase 8.7. The manual-joining `create()` authorization fix (SEC-86-I-01) is **required** before that hotfix is deployed to production; the procedure is in `phase-8-6-implementation.md` §7.

## 8. Backlog

| ID | Item | Status |
|---|---|---|
| P87-BACKLOG-001 | Recruiter daily metrics → governed offer definition (D8.7-025 b) | needs product + payroll approval |
| P87-BACKLOG-002 | Automation rule priority groups, first match wins (D8.7-022 c) | open, product |
| P87-BACKLOG-003 | `{{links.scheduling}}` supplied from an active self-scheduling invitation (DQ-87-16) | open, product/security |
| P87-BACKLOG-004 | Risk Radar: batch `last_seen_at` updates; Hiring Health compute cost per requisition (with P85-BACKLOG-007) | open, performance |
| P87-BACKLOG-005 | Redis queue and horizontal workers beyond the supported scale (D8.7-027 c) | open, needs approval |
| P87-BACKLOG-006 | Email to an operations address for platform alerts (D8.7-028 b) | open |
| P87-BACKLOG-007 | Remove the unused `app/Services/AI/Communication/*` providers and `AiCopilotEmail` (SEC-87-14) | open, Informational |
| P87-BACKLOG-008 | Legal retention period for `failed_jobs` and logs | open, 8.8 |
| P87-BACKLOG-009 | Index or replace the JSON dedupe lookup on `notifications` (PF-87-04) | open, Medium |
| P87-BACKLOG-010 | `OutcomeLearningService::refresh` loads full history (PF-87-08) | open, Low |
| P87-BACKLOG-011 | Very large document embedding approaching the 300 s worker timeout (PF-87-09) | open, Low |
