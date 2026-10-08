# Phase 8.6 Discovery: Data Governance, Master Data & Configuration Integrity

**Scope of this document:** discovery and decision lock only.
- **Not changed:** application code, migrations, tests, routes, configuration and permissions.
- **Evidence:** three read-only code inventories (master data; pipeline, offer, settings, outcome, Role DNA, incentive and target configuration; automation, communication, notification, integration and audit). Direct `grep`/`sed`/`git show` reads verified the key claims.
- **Probes:** the Filament authorization probes were run in the preceding session, before this read-only instruction; they are reported in §17.

The decision register is `docs/phase-8-6-decision-record.md`.

## 1. Executive Summary

**Recruitment Edge governs its *transactional* history well, but not its *configuration*.** Well governed today:
- per-requisition pipeline snapshots;
- automation rule versions and version-bound executions;
- rendered communication content;
- immutable Role DNA versions;
- versioned Outcome records;
- frozen performance months;
- offer term locks and revisions.

**Configuration and master data can still silently rewrite history.** The central finding is that today:

| Change made today | What it rewrites |
|---|---|
| Edit an SLA target | Historical SLA compliance |
| Edit an offer letter template, or change the default | The letter of already-accepted offers (letters are re-rendered on every download) |
| Force-delete a department, location or source | **Deletes** historical recruitment costs, incentive rules and targets, and **nulls** frozen outcome snapshots through database cascades (no audit) |
| Rename master data | Every historical record's label |
| Edit an incentive slab or rule | Re-prices pending calculations; approved or paid calculations show drifting parameters (stored amounts are safe) |
| Re-apply a pipeline template | Moves applications with no stage-history row and no audit |

- **Master data** (departments, designations, locations, sources, reasons, interviewers) is **not audited at all**.
- **No configuration change records a reason.**
- **A critical security defect was found and contained** (§17, SEC-1): Filament's missing-policy-method fallback allowed any recruiter to permanently delete offers, interviews, joinings and candidates.
  - The containment (`dcff76e`) and a `main` hotfix branch (`2fab3fd`) exist, **not pushed**.
  - Production deployment is decision D8.6-030.

| Count | |
|---|---|
| Master-data entities | 12 |
| Configuration entities | 27 |
| Audit gaps | 13 |
| Delete / dependency risks | 17 |
| Versioning gaps | 12 |
| Historical-reproducibility gaps | 14 |
| Security findings | 8 (1 critical, contained) |
| Performance findings | 5 |
| Data-quality findings | 10 |
| Open decisions | 30 |

## 2. Baseline

| | |
|---|---|
| Branch | `feature/sep_25_hrm` |
| HEAD | `dcff76ec84e5bd5f4f1cb4fb4388c37b458f2288` |
| Lineage | The Phase 8.5 freeze `05c922a`, plus **one approved commit**, `dcff76e` (security containment, approved "ok" in this session). It adds 4 tests (1,717 tests); no migrations, routes or schema. |
| Migrations | 150 (unchanged since 8.5) |
| Routes | 237 (unchanged) |
| Working tree before discovery | Only this session's own untracked 8.6 drafts. The superseded draft `phase-8-6-decisions.md` was removed; this document replaces the earlier draft of `phase-8-6-discovery.md`. |
| Other branch | `hotfix/filament-delete-authorization` @ `2fab3fd`, based on `main` `9cba8e3`, not pushed |

`git log -15` at discovery time: `dcff76e`, `05c922a`, `7d2dff4`, `21815d3`, `2eb8b1a`, `8b2c62a`, `c751248`, `61e3482`, `8972f32`, `1d17563`, `a38a8d9`, `c229f9d`, `a6ae128`, `c5f3db0`, `e10a045`.

## 3. Master Data Inventory

The references for each row (model, migration and line) are in the inventory notes; the key ones are cited inline.

**Abbreviations:**
- **SD**: soft deletes.
- **Force del.**: force delete (hard delete).
- **`s.m`**: the permission `settings.manage`.
- **"Eff."**: the action is effective (usable in the UI).

| # | Entity | Model / table | Status field | SD | Unique | Create / edit | Delete | Bulk | Restore | Force del. | Policy / permission | Hierarchy | In-use guard | Audit | Version / eff. dates | Import |
|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|---|
| 1 | Department | `Department` / `departments` (`2026_08_26_100227`) | `is_active` | yes | `code` (including trashed) | yes; **code editable** | yes | Delete, force, restore (eff.: per-record check) | yes | **yes** | `DepartmentPolicy`: `s.m`; view = true | none | **none** (DB restrict from employees and requisitions) | **no** | no | seeder (`firstOrCreate` by code) |
| 2 | Designation | `designations` (`…100228`) | `is_active` | yes | `code` | yes | yes | yes | yes | **yes** | `s.m` | none | DB restrict (employees, requisitions) | **no** | no | seeder |
| 3 | Location | `locations` (`…100226`) | `is_active` | yes | `code` | yes | yes | yes | yes | **yes** | `s.m` | none | **none** (no restrict anywhere) | **no** | no | seeder |
| 4 | Candidate source | `candidate_sources` (`…103804`) | `is_active` | yes | `code` | yes | yes | yes | yes | **yes** | `s.m` | none | DB restrict (candidates) | **no** | no | seeder; looked up **by name** by the career site and referrals |
| 5 | Rejection reason | `recruitment_rejection_reasons` (`…103805`) | `is_active` + `category` | yes | `code` | yes | yes | yes | yes | **yes** | `s.m` | none | usage guard only (`isSelectable`) | **no** | no | seeder |
| 6 | Dropout reason | same table (joining / general category) | same | yes | same | same | same | same | same | same | same | none | same | **no** | no | seeder |
| 7 | Skill | free-text JSON on `candidates.skills` and `recruitment_requisitions.skills` | — | — | — | via forms | — | — | — | — | — | — | — | via Candidate | Role DNA versions only | — |
| 8 | Qualification | free-text string columns | — | — | — | via forms | — | — | — | — | — | — | — | via Candidate | no | — |
| 9 | Interviewer | `Interviewer` / `interviewers` (`2026_09_14_124342`) | `is_active` (inline toggle) | **no** | `employee_id` | yes | **hard delete** | Delete (eff.) | — | — | `s.m`, including `deleteAny` | none | **none** (no future-interview check) | **no** | no | `InterviewerImportService`: silent reactivation, no audit, no transaction |
| 10 | Interview type (mode) | enum `InterviewMode` | — | — | — | code | — | — | — | — | — | — | — | via Interview | code | — |
| 11 | Interview round | enum `InterviewRoundNumber`; the round *name* is stored as text in 3 tables | — | — | — | code | — | — | — | — | — | — | — | via Interview | code | — |
| 12 | Employee (as master reference) | `employees` | `status` | yes | `employee_code`, `email` | via `users.manage` | via `HierarchyIntegrityService` (reports and login guard) | none (8.4) | yes | **false** | `users.manage` + hierarchy | **yes** | reports and login only | **yes** | no org history | conversion only |

**Historical dependencies:**
- Metric filters (`MetricScope`).
- Outcome filters use snapshot ids with live names.
- Targets and incentive rules resolve on the recruiter's **current** department, designation and location.
- Automation scope and conditions.
- Snapshots freeze ids (source name only).
- Hiring Memory freezes names.

## 4. Dependency Analysis

These are the effects of **force delete**. Soft delete fires no foreign-key action, but it blanks labels (DR-15).

| Entity deleted | CASCADE (rows deleted, unaudited) | SET NULL (history altered) | RESTRICT | Orphan |
|---|---|---|---|---|
| Department | `recruitment_costs`, `recruitment_incentive_rules` (→ slabs), `recruitment_daily_targets` | `hiring_outcome_snapshots.department_id`, `hiring_memory_records`, `talent_pools`, `designations` | `employees`, `recruitment_requisitions` | `automation_rules.scope_id` (no FK) |
| Designation | incentive rules, daily targets | `offers`, `offer_revisions`, snapshots, memory, `outcome_insights`, `role_dna_profiles` | employees, requisitions | — |
| Location | `recruitment_costs`, incentive rules | `employees`, `requisitions`, `offers`, `offer_revisions`, snapshots | **none** | automation scope |
| Source | `recruitment_costs`, `recruitment_campaign_sources` | snapshots, `employee_referrals` | `candidates` | — |
| Rejection / dropout reason | — | `candidate_applications` (×2), `interviews`, `candidate_joinings`, `employee_referrals` | none | — |
| Interviewer | — (nothing references `interviewers`) | — | — | membership history lost |
| Skill / qualification | n/a (free text) | — | — | — |

**Consequences:**
- The model-level immutability guard on `HiringOutcomeSnapshot` is **bypassed by database set-null**.
- An incentive rule with calculations makes the cascade fail with a raw FK error (`recruiter_incentive_calculations` restrict).

## 5. Pipeline Governance

| # | Question | Finding |
|---|---|---|
| 1 | Template versioning | An integer `version`, bumped when the stage list changes, with a `stages_updated` audit summary. **No stored definition per version.** |
| 2 | Requisition snapshots | **Yes.** `RequisitionPipelineStage` is a full copy, immutable (update throws except `superseded_at`, delete throws). |
| 3 | Stages editable after use | Library stages: yes, except the code (and the milestone of system stages). Requisition snapshots: no. |
| 4 | Stage identity immutable | `code` immutable; snapshot rows immutable. |
| 5 | Stage order mutable | Library order yes (audited); snapshot order fixed. |
| 6 | Terminal mutable | Library yes; **templates are not re-validated** when a library stage changes. |
| 7 | Stage deletion | Not possible (policy false; template FK restrict). |
| 8 | Applications depend on current config | No: they point at snapshot rows. |
| 9 | Template re-apply | Supersedes old snapshot rows; remaps each application by code, then milestone. **Needs only `requisitions.update`, no status guard.** |
| 10 | Remap audited | **No.** `saveQuietly`: no stage-history row, no per-application audit. |
| 11 | Reports reproducible | Yes for stage entries (stage history). The configured stage of a remapped application changes silently. |
| 12 | Metrics depend on stage keys | Governed metrics use the canonical milestone (`CandidateStage`) and genuine entries (8.5), **not** configured stage names. Pipeline SLA (`sla_hours`) comes from the snapshot. No 8.5 dependency on template configuration. |

## 6. Offer Governance

| # | Question | Finding |
|---|---|---|
| 1 | Template versioning | **None.** A Word re-upload **deletes the previous file**; the audit keeps only paths. |
| 2 | Issued offers preserve exact content | **No.** The PDF is re-rendered on every download (`OfferLetterRenderer`): the offer's own body, else the chosen template *while active*, else the current default, else the system template, else the built-in view. Merge values are read live. |
| 3 | Historical offers depend on the current template | **Yes**, unless the offer has a custom body. |
| 4 | Delete while referenced | **Yes.** No in-use guard; the FK nulls the locked term `offer_letter_template_id` at DB level. |
| 5 | Template changes audited | Yes (`Auditable`). Changing the default is an unaudited mass update. |
| 6 | Offer configuration audited | Offer settings (alert windows) are audited through settings. |
| 7 | Revisions preserve terms | **Yes** (8.3 `offer_revisions`, original release kept as revision 1). |
| 8 | Accepted offers immutable | **Terms yes** (lifecycle lock). **The letter no** (see 2). |

## 7. Recruitment Settings

- **Authority:** `settings.manage` (CHRO only, seeded).
- **Audit:** old and new values per key, no reason.
- **Cache:** `rememberForever`, forgotten on save or delete of the current key only.
- **Effective dates:** none.
- **Two editors:**
  - the typed page, with range validation and no cross-field checks;
  - raw CRUD, with any key, value or type (no `float`), an editable key and a delete action.

| Setting group | Keys | Changes historical results? |
|---|---|---|
| SLA leg targets | `sla_days_*` (7) | **Yes**: `sla.leg_compliance` over completed past legs |
| Time-to-hire target | `sla_days_time_to_hire_target` | **Yes**: on-track status for any past period |
| TTH start point | `time_to_hire_start_point` | Hires with a snapshot: **no** (frozen, 8.5 D1). Pre-snapshot hires and Hiring Memory builds: **yes** |
| Vacancy / position risk | `vacancy_ageing_alert_days`, `position_risk_*` | Point-in-time views: no. Hiring Health snapshots do not record the thresholds used. |
| Stall / feedback / offer windows | `candidate_stall_days`, `offer_expiry_alert_days`, … | Current alerts only |
| Notification thresholds | `notification_*` (5) | Alerts only |
| Joining | `joining_risk_followup_days`, `joining_reminder_days` | Live risk colour only |
| Activity window | `activity_backdate_days` (8.5) | **Yes, indirectly**: widening it allows retroactive activity for targets and incentives |
| Scheduling | `self_scheduling_*`, `interviewer_daily_capacity` | Future behaviour only |
| `config/metrics.php` | business timezone, minimum sample, compensation group, cache TTL | **Yes**: timezone and sample changes re-cut past periods; environment or code only |

## 8. Outcome Configuration

- **Hard-coded:** outcome types (`OutcomeType` enum); the rules in `OutcomeCalculator`.
- **Versioned constants stamped on every row:** `outcome-rules/1`, `hiring-snapshot/2`, `outcome-learning/1`.
- **Configurable (file only, not admin):** `config/outcomes.php`, holding status checkpoints `[30, 90, 180]`, grace days 7, sample bands 3/10, learning checkpoint 90, catch-up 7 days, batch size and queue.
- **Effective-dated:** no.
- **Reproducibility:**
  - Outcome rows and snapshots are frozen and versioned.
  - Changing sample bands or grace days in config re-bands past aggregates and insights **without bumping the rule version** (VG-11).
- No semantic change is proposed.

## 9. Role DNA Configuration

- **Authority:** `intelligence.role-dna.manage` (VP HR, manager).
- **Versioning:** `RoleDnaVersion` is immutable (update throws), and every change creates a new version with an audit (`role_dna_version_created`, `_attribute_added/_confirmed/_rejected`, `role_dna_confirmed`).
- **AI suggestions:** unconfirmed until a person confirms them; a rejection carries a reason.
- **Thresholds:**
  - `RoleDnaBuilder::MIN_HISTORY = 3` (code);
  - the latest 200 memory records;
  - `config/intelligence.php`, which bounds scans and refreshes but does not change stored results.
- **Hiring Memory:** frozen per hire and correctable (audited).
- **Outcome Loop:** accepted learning becomes a Preferred or Informational attribute only (8.2).
- **Gap:** version rows have **no deleting guard**, and cascade with the profile, which cascades with the requisition (only possible through a DB-level requisition delete, now forbidden in the UI).

## 10. Incentive Governance

| # | Question | Answer |
|---|---|---|
| 1 | Rules versioned | **No** |
| 2 | Effective-dated | `effective_from` / `effective_to`, **not validated** (order or overlap) |
| 3 | Slabs versioned | **No** |
| 4 | Edits audited | Rules yes (no reason); **slabs no** |
| 5 | Deletes protected | Rule with calculations: raw FK error. Rule without: slabs cascade, unaudited. **Slab delete nulls the calculation's slab.** |
| 6 | Approved incentives changeable | **Stored amount no** (recalculation guard; `IncentiveApprovalService`). **Displayed rule and slab parameters drift** (live read). |
| 7 | Pending calculations change | **Yes**: re-priced with the current rule and slabs on recalculation; the amount rewrite is unaudited |
| 8 | Recalculation uses the original rule | **No**: the current rule, selected by `effective_from` / `effective_to` / `is_active` as they are now |
| 9 | Approved statement recomputed | Totals come from stored amounts plus adjustments (`effectiveAmount`); the displayed parameters are live |
| 10 | Config affects paid incentives | Amounts no. Retroactive slab mode adds top-up adjustments (by design, audited in the approvals table). |

**STOP-condition check (14):** "a configuration change could alter approved incentives":
- **Amounts: no.**
- **Displayed provenance: yes.** This is a display integrity gap, not a pay change. Documented, not stopping; resolved by D8.6-012.

## 11. Target / Performance Governance

- **Targets:** exactly one scope; effective from/to; audited; **no order or overlap validation**. Resolution uses the tier, then the latest `effective_from` covering the date, then proration.
- **Performance rules:** effective from/to; audited; **no overlap guard**, so a double-counted metric gets double weight.
- **Freeze (8.5 D48):**
  - A month is frozen on the day-1 run; a **missed run leaves it unfrozen**.
  - Live leaderboards and Copilot recompute past periods from current targets and rules.
  - A frozen month is recomputed only with the audited `--force --reason`.
- **Attribution:** the actor for activity, the current owner for outcomes (8.5 D8).
- **Hierarchy:** `targets.configure` plus scope.
- **Reproducibility:** frozen months, yes. Unfrozen or live views, no.

## 12. Automation Governance

| # | Question | Answer |
|---|---|---|
| 1 | Definition versioned | **Yes** (`automation_rule_versions`), for triggers, conditions, actions, timing, escalation, scope, failure behaviour and limits. **Not versioned: priority, owner, description.** |
| 2 | Execution stores the version | **Yes** (`automation_rule_version_id`; the idempotency key includes the version number) |
| 3 | Execution stores the action definition | Via the version snapshot (`perform` runs the snapshot) |
| 4 | Editing rewrites history | No for executed runs. Pending runs execute their old version; scope is matched against the **live** rule at trigger time. |
| 5 | Active rules deletable | No (policy false). **But versions, executions and escalations cascade at DB level**, with no model guard. |
| 6 | Archived | Yes, one-way; archiving cancels pending runs with bulk updates (no per-row audit) |
| 7 | Inactive owners execute | No: the Phase 8.4 owner re-check runs before each run and pauses the rule (intact, `AutomationEngine.php:445`) |
| 8 | 8.4 re-check intact | Yes |
| 9 | Changes audited | Yes (`Auditable` plus lifecycle entries); **no reason** (the UI always sends "Edited") |
| 10 | Executions reproducible | Yes, from the version snapshot plus the execution record |

**Authority:** the manager role authors **and** activates rules and can edit active rules (no separation of duties).

## 13. Communication Template Governance

- **Versioning:** wording only; a subject or body change creates a version. **`provider_template` (the WhatsApp approved id) and status changes do not.**
- **Sent content:** **preserved.** Each message stores the rendered subject and body, `template_version` (an integer, not a reference to the version row), provider template, parameters and language.
- **Audit:** `Auditable`, plus a per-message audit (`communication_sent/_failed`).
- **Delete:** policy false; archive through status. **No dependency check:** archiving a template used by an active rule or a built-in message silently skips sends.
- **Variables:** validated by `TemplateRenderer::validate`.
- **Authorship:** version authorship is Employee-based (null for users without an employee record).
- **Reproducibility:** **yes** for sent messages.

## 14. Notification Governance

- **Configuration:** only the `notification_*` and alert settings (CHRO); routing is code (`NotificationDispatchService`).
- **Inactive users:** alerts reroute to a reachable manager or HR (8.4). The reroute is logged, **not audited**.
- **Hierarchy:** recipients come from the requisition and reporting line.
- **Versioning and effective dating:** none (thresholds are alert-only).
- **Per-user preferences:** none (only Laravel's `notifications` table).
- **Escalation:** configured per automation rule (versioned).

## 15. Integration Configuration

- **Job boards:** code-registered connectors (`JobBoardRegistry`: LinkedIn, Naukri, Indeed), with credentials in the **environment**.
- **Postings:** `jobs.publish` plus requisition visibility, `Auditable`, with publish, unpublish (with a reason), pause and failure audited.
- **Calendar and video:**
  - `integrations.manage`, or the owner via `calendar.connect`.
  - Tokens are **encrypted** and hidden; token changes are invisible in the audit.
  - Connection tests are audited.
- **Secrets:** none in the database; all in `config/services.php` / environment.
- **Delete behaviour:** postings and calendar connections have delete false. `job_postings` cascade from the requisition (DB only).
- **Reproducibility:** distribution results are stored per channel.

## 16. Audit Coverage

**`AuditLog` fields:**
- **Recorded:** actor (`user_id`, or the polymorphic actor for the portal), entity, action, `old_values`, `changes`, `ip_address`, `request_id` (8.4) and `created_at`.
- **Not recorded:** user agent, reason.
- **Missing protections:**
  - no immutability guard;
  - no retention or pruning of audit rows (no pruner exists);
  - not hierarchy-scoped (`audit.view`, held by VP HR and CHRO).
- **The UI:**
  - searches actor and type, and filters by action and type;
  - **has no date or actor filter and no export**;
  - does not show or filter the request id.

| Area | Coverage | Mechanism |
|---|---|---|
| Candidate | yes | `Auditable` |
| Application | yes | stage history (immutable) + `application_moved/_reassigned`; not `Auditable` by design |
| Requisition | yes | approvals / status history + `pipeline_applied` |
| Interview | yes | `Auditable` + feedback events |
| Offer | yes | `Auditable` (compensation redacted) + status history + revisions |
| Joining | yes | `Auditable` |
| Employee | yes | `Auditable` + lifecycle events |
| Outcome | yes | versioned rows + supersede / correct / void / reobserve audits |
| AI | yes | `AiActionLog`, tool calls, conversations, usage logs, privacy events |
| Automation | yes | `Auditable` + versions + execution / escalation audits |
| Configuration (stages, templates, settings, offer templates) | partial | `Auditable`; mass updates and `saveQuietly` gaps |
| **Master data** | **no** | — |
| Permissions / roles | yes | explicit (`roles_assigned/_changed/_removed`, `permissions_updated`, `protected_role_refused`) |
| Login / authentication | yes | `login`, `login_failed`, `login_locked_out`, `logout`, MFA, password events |
| Settings | yes (no reason) | `Auditable` |
| Incentives | calculations: approvals table; rules: `Auditable`; **slabs: no** | mixed |
| Targets / performance rules | yes | `Auditable` |

**Audit gaps:**

| ID | Gap |
|---|---|
| AG-1 | Master data (departments, designations, locations, sources, reasons) unaudited |
| AG-2 | Interviewer delete, toggle and import unaudited |
| AG-3 | Incentive slabs unaudited |
| AG-4 | Follow-ups, manual activities and AI knowledge articles unaudited |
| AG-5 | No reason column; no configuration change records a reason |
| AG-6 | Force delete looks the same as soft delete; restore shows as `updated` |
| AG-7 | Unaudited mass or quiet writes: offer default flip, automation archive cancellations, pending incentive re-price, pipeline remap, `createFromTemplate`, version bump |
| AG-8 | Database cascades unaudited |
| AG-9 | Scheduler and console rows have no request id; the request id is not in the UI (→ 8.7) |
| AG-10 | Explicit `AuditLog::record()` payloads are not redacted; hidden-attribute changes are invisible |
| AG-11 | The audit UI has no date or actor filter, no export, no user agent |
| AG-12 | No audit immutability guard; no retention policy (legal policy unknown → 8.8) |
| AG-13 | Notification reroute logged, not audited |

## 17. Administration Security

**Seeded holders of each admin permission** (CHRO holds everything, `'*'`):

| Permission | Holders | Assessment |
|---|---|---|
| `settings.manage` | CHRO | appropriate (includes master-data force delete: SEC-5) |
| `pipeline.configure` | VP HR | appropriate |
| `pipeline.override` | VP HR, manager | reasoned and audited (8.3) |
| `incentives.configureRules` | VP HR | appropriate; slab changes unaudited |
| `targets.configure` | VP HR, manager | hierarchy-scoped (policy) |
| `performance.configure` | VP HR | appropriate |
| `intelligence.role-dna.manage` | VP HR, manager | versioned and audited |
| `communications.templates` | VP HR | appropriate |
| `integrations.manage` | VP HR | appropriate |
| `automation.manage` / `.activate` | VP HR, manager | **no separation of duties** (SEC-4) |
| `automation.organization` | VP HR | appropriate |
| `users.manage`, `roles.manage` | CHRO | 8.4 guardrails intact: no self-escalation, only held permissions grantable, protected CHRO by key |
| `audit.view` | VP HR, CHRO | global, not hierarchy-scoped |

**Security findings:**

- **SEC-1 (Critical, contained): Filament missing-method fallback.**
  - Filament allows an action when its policy method is missing. Recruiters could permanently delete offers, interviews, joinings and candidates; managers could force-delete requisitions.
  - Contained in `dcff76e` (this branch) and `2fab3fd` (the `main` hotfix, **not deployed**).
  - The coverage test `PolicyActionCoverageTest` now fails if a destructive action lacks an explicit policy method.
  - Remaining: deployment (D8.6-030) and strict authorization (D8.6-027).
- **SEC-2 (Medium): re-applying a pipeline template.**
  - Needs only `requisitions.update`, with no status guard.
  - The remap is unaudited, so a manager can re-pipe a closed requisition.
- **SEC-3 (Medium, integrity): the raw settings CRUD.**
  - It bypasses typed validation: any key or type, an editable key, and delete.
  - The CHRO is the only holder, but it is still an error-prone path.
- **SEC-4 (Medium): no separation of duties in automation.**
  - One manager can author and activate a rule.
  - Editing an active rule takes effect immediately.
- **SEC-5 (Medium): master-data force delete.**
  - It is exposed to `settings.manage` and causes irreversible cascades.
  - This is destructive authority with no in-use guard and no audit.
- **SEC-6 (Medium): offer template delete bypasses the locked offer term** (DB set-null).
- **SEC-7 (Low): the audit log.**
  - It has no immutability guard.
  - It is global to `audit.view` holders.
  - Explicit payloads are not redacted.
- **SEC-8 (Low): calendar token rotation is invisible in the audit** (hidden attributes are skipped).

## 18. Hardcoded Configuration

Classification key: **A** domain invariant · **B** code-defined configuration · **C** organisation configuration · **D** versioned business policy · **E** metric definition · **F** workflow definition · **G** candidate-facing content · **H** future custom fields.

| Value | Where | Class |
|---|---|---|
| `CandidateStage` milestones and order | enum | A |
| Requisition status transitions (`ALLOWED_TRANSITIONS`) | `RequisitionApprovalService` | F (code) |
| Offer status machine, `OPEN_STATUSES` | `OfferService` | A |
| Outcome types and rules; rule versions | `OutcomeType`, `OutcomeCalculator` | A / D (versioned constants) |
| Outcome checkpoints 30/90/180, grace 7, sample 3/10 | `config/outcomes.php` | D (needs a version bump on change, VG-11) |
| Metric definitions (28), min sample 3, compensation group 5, business timezone | `app/Services/Metrics`, `config/metrics.php` | E (fingerprinted); config values D |
| SLA legs (`RecruitmentSlaService::LEGS`) | code | E; targets C (settings) |
| `RoleDnaBuilder::MIN_HISTORY = 3`, `EXCLUDED_CRITERIA = culture_fit` | code | B / A (fairness) |
| Talent Signal `MIN_COMPLETENESS = 40` | code | E / D (`talent-signal/1`) |
| Risk radar dismissal 7 days; health freshness 6 h | code | B |
| `FAIRNESS_PATTERN`, `CAUSAL_PATTERN` | `IntelligenceAiService` | A |
| Portal `LINK_VALID_HOURS = 48`; uploadable document types; editable profile fields | `CandidatePortalService` | B / G |
| Interview unconfirmed window 2 days | Action Center | B (differs from alerts: the 8.5 discovery) |
| Duplicate `STRONG_CONFIDENCE = 85` | `CandidateDuplicateDetector` | B |
| Automation global limits (chain depth 3, 500/day, 3 messages/candidate/day, …) | `config/automation.php` | B (safety backstops) |
| Automation `MAX_ACTIONS = 10`, escalation steps 5, operators, units | code | F |
| Interview mode and round names | enums | C / H (organisation-specific round names) |
| Skills, qualifications | free text | H (taxonomy, P7-BACKLOG-001) |
| Offer letter built-in view, standard template | code / seeded | G |
| Job-board connectors | `JobBoardRegistry` | B |

## 19. Historical Reproducibility

The question is "if this changes tomorrow, can we reproduce yesterday's decision?"

| ID | Area | Reproducible? | Why not / gap |
|---|---|---|---|
| HR-1 | SLA compliance | **No** | Leg targets read live |
| HR-2 | TTH target status | **No** | Target read live |
| HR-3 | Time to hire (pre-snapshot hires) | **No** | Start point live |
| HR-4 | Offer letter | **No** | Re-rendered from the current template |
| HR-5 | Incentive (pending, and displayed parameters) | **Partly** | Amounts safe once approved; parameters drift; pending re-priced |
| HR-6 | Targets / performance (unfrozen and live views) | **Partly** | Frozen months yes; freeze catch-up missing |
| HR-7 | Pipeline template version | **No** | No stored definition per version |
| HR-8 | Pipeline remap | **No** | Unrecorded |
| HR-9 | Master-data labels | **No** | Renames rewrite; no frozen names in snapshots |
| HR-10 | Outcome snapshot dimensions after a force delete | **No** | FK set-null |
| HR-11 | Automation | **Yes**, mostly | Scope matched live; priority and owner unversioned |
| HR-12 | Hiring Health | **Partly** | Thresholds not recorded |
| HR-13 | Outcome / metric aggregates after a config change | **Partly** | Config values not fingerprinted |
| HR-14 | Target and incentive rule applicability after an employee transfer | **No** | Current org attributes (P85-BACKLOG-002) |

**Fully reproducible:**
- Communications (rendered content).
- Role DNA (immutable versions).
- Hiring Memory (frozen, correction-audited).
- Outcome records (versioned).
- Governed metric definitions (fingerprinted, 8.5).

## 20. Risk Matrix

| Entity | Current state | Audited | Versioned | Effective-dated | Delete safe | In-use guard | Reproducible | Hierarchy | Risk | Evidence |
|---|---|---|---|---|---|---|---|---|---|---|
| Department | soft delete + force | no | no | no | **no** (cascades) | no | no (renames) | no | **High** | FK table §4 |
| Designation | soft delete + force | no | no | no | **no** | no | no | no | **High** | §4 |
| Location | soft delete + force | no | no | no | **no** (no restrict) | no | no | no | **High** | §4 |
| Candidate source | soft delete + force; name lookups | no | no | no | **no** | DB restrict (candidates) | no | no | **High** | §4, `CareerApplicationService:160` |
| Rejection / dropout reason | soft delete + force | no | no | no | **no** (nulls 5 columns) | usage only | no | no | Medium | §4 |
| Interviewer | hard delete | no | no | no | partial | no | n/a | no | Medium | `InterviewerResource:83-88` |
| Skill / qualification | free text | via Candidate | no | no | n/a | n/a | frozen in snapshots | — | Low | — |
| Interview mode / round | enums / text | via Interview | code | — | n/a | — | yes | — | Low | — |
| Employee | service delete | yes | no org history | no | yes (8.4) | reports and login | partial | yes | Medium | HR-14 |
| Pipeline stage (library) | no delete | yes | no | no | yes | FK restrict | via snapshots | no | Low | §5 |
| Pipeline template | no delete | yes | number only | no | yes | — | **no** (HR-7) | no | Medium | §5 |
| Requisition pipeline snapshot | immutable | via `pipeline_applied` | superseded | superseded_at | yes (UI) | — | yes | via requisition | Low | — |
| Template re-apply | unaudited remap | partial | — | — | — | — | **no** | `requisitions.update` | Medium | SEC-2 |
| Offer letter template | overwrite, delete | yes | **no** | no | **no** | **no** | **no** | no | **High** | §6 |
| Recruitment settings | typed + raw CRUD | yes (no reason) | **no** | **no** | **no** (raw delete) | — | **no** (SLA) | no | **High** | §7 |
| Outcome config | file | no (deploy) | constants | no | n/a | — | partial | — | Medium | §8 |
| Role DNA | versions | yes | **yes** | created_at | policy | — | yes | requisition | Low | §9 |
| Intelligence config | file | no | no | no | n/a | — | yes | — | Low | §9 |
| Incentive rule | editable, delete | yes | **no** | yes (unvalidated) | raw FK error | DB restrict | partial | no | **High** | §10 |
| Incentive slab | editable, delete | **no** | **no** | no | **no** | **no** | **no** | no | **High** | §10 |
| Daily target | editable, delete | yes | no | yes (unvalidated) | yes | — | partial | yes | Medium | §11 |
| Performance rule | editable, delete | yes | no | yes (unvalidated) | yes | — | partial | no | Medium | §11 |
| Performance snapshot | freeze | recompute audited | frozen | month | n/a | — | yes when frozen | yes | Low | §11 |
| Automation rule | archive only | yes (no reason) | yes (partial fields) | effective window | DB cascade | — | yes | scope | Medium | §12 |
| Communication template | status archive | yes | wording only | no | DB cascade | **no** (rules) | yes (sent) | no | Medium | §13 |
| Notification thresholds | settings | yes | no | no | raw delete | — | n/a | no | Low | §14 |
| Notification routing | code | reroute logged only | code | — | — | — | — | yes | Low | §14 |
| Job boards / integrations | env + audited tests | yes | no | no | no delete | — | yes | — | Low | §15 |
| Calendar connection | encrypted tokens | yes (tokens invisible) | no | no | no delete | — | — | owner | Low | §15 |
| Metrics config | file / env | no | spec versions | no | n/a | — | fingerprinted specs; config not | — | Medium | §7 |
| Automation config | file / env | no | no | no | n/a | — | n/a | — | Low | §18 |
| Audit log | append (no guard) | — | — | — | not deletable in UI | — | — | global | Medium | §16 |

## 21. Decision Register

See **`docs/phase-8-6-decision-record.md`**: 30 decisions (D8.6-001 … D8.6-030), each with question, current evidence, options, impact, dependencies, proposed decision and whether it needs product approval.

## 22. Proposed Phase 8.6 Scope

Subject to the decision record:

1. **Master-data lifecycle.**
   - Active → Inactive → Archived; no force delete.
   - In-use checks listing dependents.
   - Audited with a reason.
   - Historical labels resolve archived items.
   - Codes immutable.
   - Active-only selection enforced server-side.
   - Source lookups by code.
   - Interviewer deactivation instead of delete; an audited, transactional import; scheduling needs an active listed interviewer.
2. **Offer letters.** The issued letter is stored at release (immutable, hashed). Templates are versioned, never overwritten, and not deletable while referenced; the default change is audited.
3. **Settings.** A history with effective dates; SLA and TTH targets are resolved as of when the event happened (governed metric version bump); raw CRUD is removed; cross-field validation; a reason on save.
4. **Incentives.** A pricing snapshot on each calculation, and the calculation view shows it. Rules and slabs are locked once used (new price = new rule version). Slabs audited. Graceful in-use refusal. Effective ranges validated.
5. **Targets and performance.** Range validation, and a freeze catch-up for missed months.
6. **Pipeline.** Stored template versions. Re-apply needs `pipeline.configure` and an open requisition, and writes an audited remap with a new `pipeline_remapped` stage-history event (never a stage entry). Library edits are re-validated against templates.
7. **Automation and communication.**
   - Version and execution deleting guards.
   - Priority and owner frozen in versions.
   - A change reason on edits and lifecycle changes.
   - A template dependency check on archive.
   - Template versions include `provider_template` and status; sent messages reference the version row.
   - Separation of duties (product decision).
8. **Audit model.**
   - A `reason` column.
   - `restored` and `force_deleted` as their own actions.
   - Redaction of explicit payloads; a "[changed]" marker for hidden fields.
   - Audit UI: date and actor filters, request id column.
   - Master data, slabs, follow-ups, manual activities and knowledge articles become auditable.
9. **Configuration-in-code fingerprint.** Outcome, metric and intelligence values that change history require a rule-version bump.
10. **Filament strict authorization mode,** if approved.
11. **Governance audit command.** A report-only `governance:audit` listing existing damage (null snapshot dimensions, orphan automation scopes, calculations with null slabs, released offers without a stored letter). **No repair.**

## 23. Proposed Non-Goals

- Changing any Phase 8.5 metric semantics. (D8.6-008 adds a *new version* of `sla.leg_compliance` with as-of targets. That is a versioned change, not a silent one, and needs explicit approval.)
- Outcome Loop, lifecycle or identity semantics.
- Incentive formulas or pay recalculation of approved or paid items.
- Historical repair (report only).
- FK action migration (deferred).
- Skills and qualification taxonomy (P7-BACKLOG-001).
- Effective-dated employee org history (P85-BACKLOG-002).
- Request id for scheduler and jobs (8.7).
- Audit retention (8.8, legal).
- Approval workflows for configuration (D8.6-026 proposes none beyond separation of duties).
- Multi-tenancy.

## 24. Implementation Order

1. Audit model foundation: the reason column, restored / force_deleted actions, redaction (unblocks every later step).
2. Master-data lifecycle and audit.
3. Settings history and removal of raw CRUD.
4. Offer letter issuance store and template versions.
5. Incentive pricing snapshot and locks.
6. Target and performance validation, and freeze catch-up.
7. Pipeline template versions and the re-apply guard.
8. Automation and communication guards.
9. The config fingerprint and strict authorization.
10. The `governance:audit` report.
11. Tests, security review, browser smokes, performance, documentation and freeze.

## 25. Migration Candidates

All additive.

| Table | Change |
|---|---|
| `audit_logs` | `+ reason` (text, nullable) |
| `recruitment_setting_changes` (new) | key, old/new value, effective_at, actor, reason, request_id |
| `offer_letters` (new) | offer, revision, storage path, sha256, template id, template version, rendered_at, released_by |
| `offer_letter_template_versions` (new) | template, version, format, body or file path, created_by |
| `offer_letter_templates` | `+ version` |
| `recruiter_incentive_calculations` | `+ pricing_snapshot` (json, nullable; null for historical rows) |
| `recruitment_pipeline_template_versions` (new) | template, version, stage list json, created_by |
| `candidate_communications` | `+ communication_template_version_id` (nullable FK, null on delete) |
| `automation_rule_versions` | no schema change; priority and owner go into the snapshot JSON |

No destructive migration, and **no FK action change** (deferred, D8.6-006).

## 26. Test Requirements

- **Feature tests per decision:**
  - Master data: archive refused when in use; the reason is audited; force delete absent; labels survive archive; inactive items refused server-side.
  - Offer letters: the letter is identical after a template edit; a template in use can't be deleted.
  - Settings: SLA as-of target.
  - Incentives: the calculation view shows the snapshot; the lock; slab audit.
  - Pipelines: the remap history.
  - Automation: the reason is required.
- **Architecture tests:**
  - no `forceDelete` on master data;
  - every configuration model is auditable or has a history table;
  - the config fingerprint;
  - policy action coverage (exists).
- **Mutation checks** on the in-use guard, the offer letter immutability and the incentive lock.
- **Regression:** Phases 8.1–8.5 suites, and browser smokes 6 → 8.5 plus a new 8.6 smoke.

## 27. Security Requirements

- Deploy SEC-1 (D8.6-030) before 8.6 ships.
- No new permission architecture. Reuse `settings.manage`, `pipeline.configure`, `incentives.configureRules`, `communications.templates` and `automation.*`.
- Separation of duties (D8.6-020) uses existing permissions.
- Stored offer letters are private files served only through the offer policy (and `compensation.view` for pay figures).
- Audit payloads never contain compensation or secrets (redaction).
- Strict authorization (D8.6-027) in development and test at least.

## 28. Performance Considerations

| ID | Finding |
|---|---|
| PF-1 | The metric cache (8.5) does not key on setting values. With D8.6-008, settings resolved as-of are read per leg; cache results per metric version. |
| PF-2 | The pipeline re-apply remaps application by application; a large requisition needs chunking with the history writes. |
| PF-3 | The interviewer import is row by row, with no chunking or transaction. |
| PF-4 | The retroactive incentive re-price loops per sibling calculation. |
| PF-5 | In-use checks need counts across many referencing tables; indexed FKs exist on most, so run them only on archive and delete. |

**Data-quality findings:**

| ID | Finding |
|---|---|
| DQ-1 | Codes can never be reused (unique including trashed); the seeder's `firstOrCreate` collides with archived rows |
| DQ-2 | Source attribution by name |
| DQ-3 | Skills and qualifications are free text |
| DQ-4 | Interview round names are text in 3 tables |
| DQ-5 | Orphaned automation scope ids |
| DQ-6 | Inactive master data can be selected |
| DQ-7 | The raw settings type has no `float` (recasting) |
| DQ-8 | The settings default is cached forever on first read |
| DQ-9 | No effective-date order or overlap validation |
| DQ-10 | The interviewer import silently reactivates |

## 29. Backlog

Proposed new items; owners and workstreams are listed.

| ID | Item | Severity | Owner / phase | Blocks release |
|---|---|---|---|---|
| P86-BACKLOG-001 | FK action hardening (cascade / set null → restrict) for master data | Medium | 8.6+ / 8.12 | No |
| P86-BACKLOG-002 | Skills and qualification taxonomy (with P7-BACKLOG-001) | Low | 8.8+ | No |
| P86-BACKLOG-003 | Scheduler and job request id; request id in more records | Medium | 8.7 | No |
| P86-BACKLOG-004 | Audit retention and immutability guard (legal policy) | Medium | 8.8 | Pending legal |
| P86-BACKLOG-005 | Configuration approval workflow beyond separation of duties | Low | future | No |
| P86-BACKLOG-006 | Employee org history (same as P85-BACKLOG-002) | Medium | future HRMS | No |

## 30. Release Gate

Phase 8.6 is complete only when:
- the decisions are locked;
- every in-scope item is implemented with tests and mutation checks;
- no Phase 8.1–8.5 semantics changed silently (D8.6-008 is explicit and versioned);
- migrations are additive and reviewed;
- the full suite passes, serial and parallel;
- browser smokes 6 → 8.6 pass;
- performance is measured for the governance checks;
- documentation is written, including the deployment and rollback procedures;
- the backlog is recorded;
- the tree is clean;
- **the SEC-1 hotfix is deployed or its deployment is explicitly scheduled by the product owner.**
