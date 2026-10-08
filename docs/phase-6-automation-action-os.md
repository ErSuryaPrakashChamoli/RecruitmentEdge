# Phase 6 — Recruitment Automation & Action OS

Developer reference for the automation layer:

- rules, versions, triggers, conditions, actions and executions;
- time-based automation, escalation and loop prevention;
- deterministic Next Best Action;
- the Action Center, the Notification Center and templates;
- dry run, health and analytics.

Short rules for agents and teammates live in `.ai/rules/automation.md` and `.ai/rules/app-filament.md`.

Phase 6 is **deterministic**. There is no AI in any decision here, and no rule can reject a candidate, select a candidate or make an offer.

---

## 1. Design principles

- **One engine per concern; automation only orchestrates.** Every action calls the engine that already owns that kind of change:

  | Action | Engine it calls |
  | --- | --- |
  | Send candidate message | `CommunicationService` |
  | Notify a team member | `NotificationDispatchService` |
  | Create an Action Center item | `RecruiterActionService` |
  | Move stage, put on hold | `StageTransitionService` |
  | Timeline note | `CandidateTimelineService` |
  | Audit entry | `AuditLog` |
  | Resolve recipients | `HierarchyService` |
  | SLA condition | `RecruitmentSlaService` |

- **Real events.** Event triggers are the domain events services already dispatch after commit. Phase 6 added three that were missing: `InterviewConfirmed`, `InterviewCompleted` and `CommunicationFailed`.
- **Data, not code.** Triggers, condition fields, operators and action types come from fixed registries. A rule can never name a class, a column or an expression.
- **History keeps its meaning.** Each saved configuration is frozen as an immutable version. An execution always runs, and reports, the version it was created with.
- **Human-controlled.** New rules are Drafts. Templates create Drafts. Activation is validated and permission-checked.

## 2. Database (additive)

| Table | Purpose |
| --- | --- |
| `automation_rules` | Key, name, status, priority, trigger, scope, owner, effective window, failure behaviour, limits. Also the editable `conditions`, `actions`, `timing` and `escalation` JSON, plus the template key and version. |
| `automation_rule_versions` | Immutable JSON snapshot per saved version. |
| `automation_executions` | One rule run for one record. Holds: <ul><li>the unique `idempotency_key`;</li><li>status, `scheduled_for`, condition trace and safe context IDs;</li><li>skip and failure reasons, retry count;</li><li>chain `depth` and `parent_execution_id`.</li></ul> |
| `automation_action_executions` | One row per configured action: status, summary, error, attempts, and `target` (what the action produced). |
| `automation_escalations` | Scheduled escalation steps: target, status, due time, resolved recipient, outcome. |
| `recruiter_actions` | Action Center items: type, priority, status, owner, entity links, due time, source rule and execution, unique `dedupe_key`. |

No new notification table was added. The Notification Center reads Laravel's `notifications` table. Permissions were granted additively by `2026_09_26_000002_grant_phase_six_permissions`.

JSON is used only for rule configuration and immutable snapshots. Everything that is reported or filtered on is a real column: status, trigger, rule, recruiter, action type and priority.

## 3. Rule lifecycle

`AutomationRuleService` is the only writer:

```
Draft → (activate: validated) → Active ⇄ Paused → Archived
```

**Every save.** `AutomationRuleValidator::errors()` checks:

- the trigger;
- the condition tree (fields, operators, values, depth ≤ 3);
- the timing;
- each action's own validation;
- escalation targets;
- the limits and the scope.

**Activation** (`activationErrors()`) additionally requires:

- at least one action;
- an active template for every message;
- a configured provider for any explicitly chosen channel;
- `automation.activate`;
- rights over the scope. Organization scope needs `automation.organization`; any narrower scope must sit inside the user's hierarchy.

**Other lifecycle rules.**

- An active rule cannot be edited into an invalid state.
- Archiving cancels the rule's pending runs and escalations.
- Every lifecycle change is audited, and edits are also audited by `Auditable`.

## 4. Triggers — `AutomationEventRegistry`

**Event triggers**, fed by the synchronous `TriggerAutomationRules` listener:

- `candidate.stage_changed` (this includes joining confirmed and joined);
- `application.applied_online`;
- `interview.scheduled`, `interview.rescheduled`, `interview.cancelled`, `interview.confirmed`, `interview.completed`;
- `offer.released`, `offer.accepted`;
- `referral.submitted`, `referral.status_changed`;
- `communication.failed`.

**Schedule triggers**, found by `recruitment:automation:dispatch` with targeted and indexed queries:

- `interview.upcoming`, `interview.feedback_pending`;
- `application.stuck`, `application.selected_without_offer`;
- `offer.awaiting_response`;
- `joining.upcoming`;
- `requisition.ageing`;
- `referral.awaiting_review`.

**Timing.**

- An event rule runs immediately, or after a delay measured from either the event itself or a date on the record (e.g. *24 hours before* `interview.scheduled_at`).
- A schedule rule has a threshold (e.g. *interview within 24 h*, *no activity for 3 business days*) and an optional `repeat_every_hours`.
- A run that was timed against a date is skipped if that date changes before it runs (for example, the interview was rescheduled). The new date gets its own run.
- **Business days** skip Saturdays and Sundays only. There is no holiday calendar in the system, so public holidays count as business days.
- All times use the application timezone.

## 5. Conditions — `AutomationFieldRegistry` + `ConditionEvaluator`

- **Tree shape:** `{match: all|any, negate, rules: [leaf | group]}`, at most 3 levels deep. The builder exposes one level of groups.
- **Leaf shape:** `{field, operator, value | amount+unit}`.
- **Fields:**
  - candidate: source, city, experience, per-channel preference, last reply;
  - application: stage, configured stage, status, created, last activity, recruiter, priority, **SLA breached** (`RecruitmentSlaService::breachFor()`);
  - interview: status, confirmed, time, result, feedback, no-show, round, interviewer;
  - offer: status, accepted, dates;
  - joining: status, date, confirmed, risk, joined;
  - requisition: status, opened, pipeline count, openings, priority, department, location;
  - message: status, channel;
  - referral: status;
  - event: new stage, previous stage, time.
- **Operators per type:**

  | Type | Operators |
  | --- | --- |
  | enum | equals, not_equals, in, not_in, exists, not_exists |
  | string | all of the enum operators, plus contains and not_contains |
  | number | equals, not_equals, >, ≥, <, ≤, exists, not_exists |
  | boolean | equals |
  | datetime | within, older_than, newer_than, before, after, exists, not_exists |

- Unknown fields or operators never pass.
- Each evaluation produces a human-readable trace, which is stored on the execution and shown in the dry run.

## 6. Actions — `AutomationActionRegistry`

| Key | What it does |
| --- | --- |
| `send_communication` | Candidate message via `CommunicationService` (trigger `automation`). The template keys already sent by Phase 5 listeners and reminders are **reserved**. Capped per candidate per day. |
| `notify` | In-app notification to a recipient: recruiter, interviewer, requisition manager or a hierarchy level. |
| `create_action` | Action Center item, idempotent per execution and position. |
| `create_followup` | A follow-up in the existing follow-up log (reminders). |
| `escalate` | Immediate hierarchy escalation, recorded as an escalation. |
| `move_stage` | Early, non-decision stages only (`MoveStageAction::ALLOWED_STAGES`), through `StageTransitionService`. |
| `hold_application` | Reversible hold with a remark. |
| `add_timeline_event` | Internal timeline note. It is never candidate-visible. |
| `add_audit_event` | Audit entry on the record. |

Notification and action titles may use the whitelisted `{{variables}}` from `TemplateRenderer`.

## 7. Execution engine — `AutomationEngine`

1. The event (or sweep) looks up active rules for the trigger. The set of active triggers is cached, so an event with no rules costs no queries.
2. Checks the effective window and the scope. `AutomationScopeResolver` applies the scope in PHP for events and in SQL for sweeps.
3. **Loop prevention.** A run is recorded as Skipped and audited (`automation_loop_prevented`) when either:
   - the rule is already running earlier in the same chain; or
   - the chain has reached `automation.max_chain_depth` (3).
4. Creates the execution under its `idempotency_key`. A duplicate is silently ignored. The key is:

   ```
   rule : version : trigger : entity : discriminator
   ```

   The discriminator is event data plus the record's `updated_at`, or the anchor date plus the repeat bucket for schedule triggers.
5. A due execution goes to the `automation` queue as `RunAutomationExecutionJob`, which is unique per execution. The engine claims only Pending executions, under a row lock.
6. When the job runs:
   - re-check that the rule is active, the record still exists and its anchor date is unchanged;
   - apply the per-record **cooldown**, the per-record maximum and the daily maximum (rule limit, capped by the global `automation.max_executions_per_rule_per_day`);
   - evaluate the conditions against **fresh** state;
   - run the actions inside `AutomationRuntime`, each with its own result row and the rule's failure behaviour;
   - set the final status: Completed, Partially Completed or Failed;
   - schedule escalation;
   - audit (`automation_executed`).
7. **Retry** re-runs only the failed actions (`retry_count` + 1, audited). **Cancel** stops a pending run and its pending escalation.
8. `processDue()` fails runs stuck in Running for more than 60 minutes, so they can be retried, and cancels runs more than 7 days past their time.

## 8. Escalation — `EscalationService` + `RecipientResolver`

**Targets** (each is resolved only when the step is due):

- recruiter, interviewer, requisition manager;
- `reports_to`, `levels_up:N`;
- the nearest ancestor holding a role: `assistant_manager`, `manager`, `vp_hr`, `chro`.

Hierarchy targets come only from `HierarchyService::managementChainOf()`, which uses the closure table's `depth`.

**Inactive employees.** Anyone inactive or without a login is skipped, moving one level further up. If nobody is found, the step is marked **Failed** ("No active recipient"). It is never silently dropped.

**Each step:** a delay after the rule ran, a priority, an optional Action Center item for the recipient, an optional candidate message, and an optional custom message.

**Stopping.** Before sending, a step checks whether the issue is resolved: the rule's Action Center item was closed, or the stop condition now holds. If so, all remaining steps become **Stopped** (audited).

## 9. Next Best Action — `NextBestActionService`

- A single set of deterministic rules turns an application's state into recommendations with priority, type, entity, reason, due time, suggested step, owner and source rule.
- The AI copilot's `recommend_next_step` tool now delegates to this service, so there is one rulebook.
- `forUser()` returns the most urgent step per visible active application, plus positions whose pipeline is too thin (via `positionHealth`). It is eager-loaded: the query count does not grow with the number of applications.
- The `NextBestAction` data object is designed for Phase 7 to add confidence and evidence.

## 10. Action Center, Notification Center, templates, dashboard

**Action Center** (`RecruiterActionResource`, Overview group).

- Tabs: All open, Critical, High, Medium, Upcoming, Overdue, Closed.
- Filters: priority, status, type, requisition, automation source, due date.
- Owners can Start, Complete (with a note) and Dismiss (a reason is required). Managers with `actions.manage` can assign and reassign within their team.
- Visibility follows the hierarchy.
- The cleanup command moves items away from inactive owners and expires items more than 14 days overdue. All of this is audited.
- "Suggested next steps" shows Next Best Action suggestions that nobody is already working on.
- The dashboard's existing Action Center widget now counts overdue items too.

**Notification Center** (`NotificationCenter` page).

- Reads the same database notifications the bell shows.
- `NotificationDispatchService::alert()` now also stores `priority`, `category` and source (`rule_id`, `execution_id`, entity) in `viewData`. Older alerts map their colour to a priority.
- Features: filters, "Take action", mark read or unread, mark all read.
- Critical notifications can be dismissed only once read.

**Templates** (`AutomationTemplateCatalog`, 10 templates, each versioned).

- "Use template" creates a Draft rule. A user without organization rights gets it scoped to their own team.
- Templates add the internal side only (actions, notifications, escalation). They never re-send messages Phase 5 already sends.
- `replaces_alert`: while a rule made from that template is **Active**, `notifications:dispatch-alerts` skips the matching built-in check, so nobody is alerted twice.

**Automation Dashboard.**

- Health (Healthy / Warning / Failed) from these signals: failure rate, missing template, unconfigured provider, no recipient, excessive retries, prevented loops, backlog.
- 30-day analytics from `RecruitmentAnalyticsService::automationAnalytics()`.
- A per-rule failure-rate table.
- It is kept off the main dashboard on purpose.
- It makes no causal claims.

**Dry run** (`/admin/automation-rules/{id}/dry-run`). Pick a real visible record and see:

- whether it is in scope;
- each condition with its actual value;
- when the rule would run;
- what each action would do;
- who each escalation step would reach.

Nothing is sent or created. The dry run is audited.

## 11. Permissions

| Permission | Grants |
| --- | --- |
| `automation.view` | Rules, templates, dashboard (read) |
| `automation.manage` | Create and edit rules, use templates |
| `automation.activate` | Activate, pause, archive (within scope) |
| `automation.organization` | Organization-, department- and location-wide rules |
| `automation.executions` | Execution history and escalations (hierarchy-scoped) |
| `automation.retry` | Retry and cancel executions |
| `automation.escalations` | Stop pending escalations |
| `automation.analytics` | Automation Dashboard |
| `actions.manage` | Assign and reassign Action Center items in the team |

Grants by role:

- **vp_hr:** everything.
- **manager:** everything except `automation.organization`.
- **assistant_manager:** view, executions, analytics, `actions.manage`.
- **recruiter:** nothing extra. Recruiters use their own Action Center and notifications.

## 12. Operations

### Queue worker

```bash
php artisan queue:work --queue=automation,communications,integrations,intelligence,default
```

### Scheduled commands

| Command | Schedule | Purpose |
| --- | --- | --- |
| `recruitment:automation:dispatch` | Every 15 min | Time-based sweeps. Options: `--rule=`, `--entity=`, `--limit=`, `--dry-run`, `-v`. |
| `recruitment:automation:process` | Every 5 min | Due runs and escalation steps, stale runs. Option: `--limit=`. |
| `recruitment:automation:cleanup` | Daily 02:00 | Reassign items from inactive owners, expire stale items, prune old "conditions not met" runs (90 days). Option: `--dry-run`. |

### Configuration (`config/automation.php`)

- `max_chain_depth`
- `max_executions_per_rule_per_day` (env `AUTOMATION_MAX_EXECUTIONS_PER_RULE_PER_DAY`)
- `max_candidate_messages_per_day` (env `AUTOMATION_MAX_CANDIDATE_MESSAGES_PER_DAY`)
- batch limits, stale-run windows, action expiry and prune age

### Database notifications need the queue worker

Filament database notifications may be queued, so the queue worker must be running for them to be delivered.

## 13. Testing

- **Automated tests:** 104. 102 are in ten new files:

  | File | Covers |
  | --- | --- |
  | `AutomationEngineTest` | Runs, idempotency, conditions skip, scope, partial / stop-on-failure, retry, cooldown, daily limit, loop and chain depth, versions, anchored timing, pause, stale runs, privacy |
  | `AutomationConditionTest` | AND / OR / NOT, depth, every operator type, business days, entities, SLA, preferences, unknown fields |
  | `AutomationEscalationTest` | Every hierarchy level, inactive users, send, stop condition, action closed, no recipient, escalate-now, cancel |
  | `AutomationRuleServiceTest` | Versioning, 11 validation cases, scope permissions, activation readiness, lifecycle audit, duplicate, templates, alert supersession |
  | `AutomationTimeBasedTest` | Sweep idempotency, re-arming, repeat, command options, scope in SQL, schedule triggers, message cap and preferences |
  | `RecruiterActionServiceTest` | Action Center lifecycle and permissions |
  | `NextBestActionServiceTest` | Recommendations, priority, owner, due time, scoping |
  | `AutomationUiTest` | Filament pages, builder round-trip, activation, dry run, failures and retry, scoping, Action Center, Notification Center, templates |
  | `AutomationAnalyticsHealthTest` | Health signals and analytics |
  | `AutomationPerformanceTest` | No N+1 in Next Best Action, bounded sweeps, zero-query events without rules |

  Two existing files gained one test each:

  | File | Added |
  | --- | --- |
  | `RecruitmentSlaServiceTest` | Open-breach regression test |
  | `RolePermissionSeederTest` | Additive permission migration |

- **Real browser:** a 24-check Playwright run against a throwaway MySQL database. It covered the builder, validation, versioning, dry run, activate and pause, sweep and escalation, failures and retry, templates, the Action Center, the Notification Center, dark mode, all 8 themes and permissions.

## 14. Known limitations and deferred items

- No holiday calendar: business days exclude weekends only.
- Escalation stop conditions are re-checked when a step is due, not continuously.
- Condition groups in the builder are one level deep; the engine allows three.
- Deferred to Phase 7: Role DNA, Talent Signal, AI ranking, Hiring Health, Risk Radar, Talent Rediscovery, Hiring Memory.
- Deferred to Phase 8: 30/60/90/180-day outcomes, Quality of Hire, Hiring Replay, Outcome Loop.
- Never automated: rejection, hiring and offer decisions.
