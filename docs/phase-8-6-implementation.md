# Phase 8.6 Implementation: Data Governance, Master Data & Configuration Integrity

**Principle:** historical business meaning must not change because current configuration changed.

**Scope:** implements all 30 approved decisions in `phase-8-6-decision-record.md`. Findings and evidence are in `phase-8-6-discovery.md`. Nothing from Phase 8.7 is implemented.

**Baseline:** `feature/sep_25_hrm` @ `dcff76e` (8.5 freeze + security containment), 150 migrations, 237 routes, 1,717 tests.

## 1. Decisions → implementation

### Audit foundation

| Decision | What changed |
|---|---|
| D8.6-003 / 024 | `audit_logs.reason`. `AuditLog::record(..., $reason)` and `AuditLog::withReason($reason, fn)`, so every row written inside a change carries the reason, including `Auditable` rows. |
| D8.6-024 | `Auditable` writes three new actions: `restored` (once, not as an update of `deleted_at`), `force_deleted`, and `archived` (for governed master data). |
| D8.6-024 | A change to a hidden attribute is recorded as `[changed]` / `[hidden]`, never its value. Derived keys can opt out through `auditDerivedAttributes()`. |
| D8.6-024 | Explicit `record()` payloads are redacted with the subject's `auditRedactedAttributes()`. |
| D8.6-024 | Audit UI: reason and request-id columns; date-range, actor and request-id filters. |
| D8.6-025 | `RecruitmentFollowup`, `RecruitmentManualActivity` and `AiKnowledgeArticle` are `Auditable`, with free text redacted. |

### Master data

| Decision | What changed |
|---|---|
| D8.6-001 / 002 / 003 | `GovernedMasterData` trait (Department, Designation, Location, CandidateSource, RecruitmentRejectionReason): code immutable, force delete refused at the model, soft delete audited as `archived`. |
| | `MasterDataLifecycleService`: deactivate, activate, archive and restore. Requires `settings.manage` and a reason (except activate). Archive is refused while open work uses the record (open requisitions, active employees, current incentive rules and targets, active or paused automation scope); the refusal lists counts. Restore returns the record as Inactive. |
| | UI: `MasterDataLifecycleActions` on tables and view/edit pages; no Delete, ForceDelete or Restore actions, no bulk actions. The code is disabled on edit; the active toggle appears on create only. |
| | Policies: `forceDelete` and all bulk abilities (`*Any`) are `false`. |
| D8.6-004 | The 32 `belongsTo` relations to these models use `withTrashed()`. `MasterDataLabel` shows "(archived)" in 13 list and infolist displays. |
| D8.6-005 | `ReferencesActiveMasterData` on nine consuming models: an inactive or archived reference is refused on create or when the field changes (`ValidationException`); unchanged historical values stay valid. |
| | Form pickers use `ActiveMasterDataOptions::scope()`: active records plus the value the record already holds. |
| D8.6-006 | Foreign-key action hardening is deferred (P86-BACKLOG-001). The application-level guards close the reachable paths. |
| D8.6-007 | Seeders match `withTrashed()->firstOrCreate` by code and never restore. Career site and referrals look sources up by code. The Website and Employee Referral sources are protected. |
| D8.6-008 | `hiring-snapshot/3` freezes department, designation, location and source names. `dimensionName()` falls back for /1 and /2 snapshots. The Outcome source breakdown prefers the frozen name. |
| D8.6-009 | Interviewers: deactivate only (model and policy), `Auditable`, reason on deactivation. The import runs in one transaction, looks employees up in chunks, audits a summary, and reports deactivated interviewers instead of reactivating them. `InterviewService::schedule` requires an active listed interviewer for a new interview. |

### Offer letters

| Decision | What changed |
|---|---|
| D8.6-010 | `offer_letters`: immutable. Rendered in the release transaction (revision 1) and in `releaseRevision()` (revision n), stored privately with SHA-256, source, template and template version, and audited as `offer_letter_issued`. |
| | Downloads use `OfferLetterIssuanceService::pdfFor()`: the stored letter if its hash verifies, otherwise a regenerated letter that is flagged as such. |
| D8.6-011 | `offer_letter_template_versions`: immutable. Every body or file change records a version; the content before the first recorded change becomes version 1. Superseded Word files are kept. |
| | A template referenced by offers or letters, or with versions, cannot be deleted (model and policy). Default changes are audited per template. No bulk delete. |

### Settings and SLA

| Decision | What changed |
|---|---|
| D8.6-012 | `recruitment_setting_changes` (append-only). `RecruitmentSettingService::update()` requires `settings.manage` and a reason, applies cross-field rules (critical threshold below underperformance threshold; position-critical at least the ageing threshold), writes history and audits with the reason. |
| | `valueAt()` resolves a setting as of any moment. |
| | `sla.leg_compliance` v2 judges each leg against the target in force when it completed. `timeToHireSummary()` uses the target in force at the end of the period. |
| D8.6-013 | The raw settings resource is read-only (list, and a view showing change history). The typed Configure page requires a reason. |
| | The settings cache no longer caches a caller's default (the prefix is now `recruitment_setting:v2:`). |

### Incentives, targets and performance

| Decision | What changed |
|---|---|
| D8.6-014 | `pricing_snapshot` (rule, slab, basis) is written on every calculation and recalculation. The view and both statement PDFs show the priced parameters; pre-8.6 rows are marked "(current rule)". Re-pricing a pending calculation is audited as `incentive_repriced`. |
| D8.6-015 | Once a rule has any calculation, its pricing and scope fields and all its slabs are locked, and the rule cannot be deleted (model, policy, disabled form sections, read-only slab relation manager). Slabs are `Auditable`. No bulk delete. |
| D8.6-016 | Effective ranges must run forwards (incentive rules, targets, performance rules). Overlapping targets (same scope, metric and period type) and overlapping performance rules (same metric) are refused. Create and edit pages show refusals as notifications. |
| D8.6-017 | `performance:snapshot` freezes any of the last three completed months left unfrozen by a missed day-1 run. |

### Pipeline

| Decision | What changed |
|---|---|
| D8.6-018 | `recruitment_pipeline_template_versions`: immutable. Recorded on create, update and apply. |
| D8.6-019 | Re-application requires `pipeline.configure`, a requisition that is not Closed or Cancelled, and a reason. Each moved application gets a `pipeline_remapped` stage-history row (same milestone, never a stage entry). Audited as `pipeline_reapplied` with the count. |
| | Stage-library milestone or terminal edits are refused if they would break a template. |

### Automation and communication

| Decision | What changed |
|---|---|
| D8.6-020 | The author of a rule's latest version cannot activate it. CHRO and VP HR are exempt. |
| D8.6-021 | Priority and owner are part of the version (an owner transfer on activation creates a version). Edit, activate, pause and archive require a reason. Archiving audits the runs and escalations it cancels. Versions, executions and escalations cannot be deleted. `update()` compares with the stored configuration. |
| D8.6-022 | A template used by an active or paused rule (action or escalation step) cannot be archived. Archiving a built-in template shows a warning. |
| D8.6-023 | A `provider_template` change creates a version. Messages record `communication_template_version_id`. |
| D8.6-026 | Separation of duties only (as approved). A maker-checker workflow is backlog P86-BACKLOG-005. |

### Authorization, fingerprint, audit and hotfix

| Decision | What changed |
|---|---|
| D8.6-027 | Filament strict authorization in local and testing. In production, `Gate::before` denies any ability whose model policy lacks the method (fail closed). |
| | Explicit policies for every model shown by a resource or relation manager: 12 read-only history policies via `ReadOnlyRecord`, `RecruitmentStagePolicy::reorder`, and `CandidateJoiningPolicy::create`. Enforced by `PolicyCoverageTest`. |
| D8.6-028 | `ConfigurationFingerprint` pins the outcome and metric config that changes history to its rule version. Drift is reported by value-free group name. |
| D8.6-029 | `governance:audit` is read-only (asserted by a test): no writes and no audit rows. It exits non-zero on ERROR. |
| D8.6-030 | Hotfix verified; deployment remains a manual release action (§7). |

## 2. Migrations (150 → 158, all additive, nullable or new tables)

1. `2026_09_27_104041_add_reason_to_audit_logs_table`
2. `…105359_add_dimension_names_to_hiring_outcome_snapshots_table`
3. `…105827_create_offer_letter_template_versions_table` (+ `offer_letter_templates.version`)
4. `…105830_create_offer_letters_table`
5. `…110216_create_recruitment_setting_changes_table`
6. `…111302_add_pricing_snapshot_to_recruiter_incentive_calculations_table`
7. `…133804_create_recruitment_pipeline_template_versions_table`
8. `…134500_add_template_version_links_to_communications`

**Properties:**
- No data transformation, no back-fill, no foreign-key action change, no dropped column.
- Verified on MySQL 8.4: `migrate:fresh --seed`, then rollback of all eight and migrate again.
- Explicit short foreign-key and index names (MySQL 64-character limit).

**Phase 8.7 compatibility:** `audit_logs.reason` is its own column; 8.7's planned actor columns (D8.7-015) and origin request ids (D8.7-014) extend `audit_logs`, `automation_executions` and `candidate_communications` without touching it.

## 3. New services, classes and commands

| Kind | Items |
|---|---|
| Services | `MasterDataLifecycleService`, `OfferLetterIssuanceService`, `RecruitmentSettingService`, `Governance\ConfigurationFingerprint`, `Governance\GovernanceAuditor` |
| Models | `OfferLetter`, `OfferLetterTemplateVersion`, `RecruitmentSettingChange`, `RecruitmentPipelineTemplateVersion` (all immutable) |
| Concerns | `GovernedMasterData`, `ReferencesActiveMasterData`, `Policies\Concerns\ReadOnlyRecord`, `Filament\Concerns\GuardsDomainExceptionsOnSave` |
| Filament helpers | `Filament\Actions\MasterDataLifecycleActions`, `Filament\Support\ActiveMasterDataOptions`, `Filament\Support\MasterDataLabel` |
| Command | `governance:audit {--json}` (read-only) |

## 4. Authorization summary

No new permissions; existing ones are reused.

| Area | Permission |
|---|---|
| Master data | `settings.manage` |
| Settings | `settings.manage` |
| Offer letter templates | `settings.manage` |
| Pipeline re-application | `pipeline.configure` |
| Incentive rules | `incentives.configureRules` |
| Targets | `targets.configure` |
| Performance rules | `performance.configure` |
| Automation | `automation.manage`, `automation.activate`, `automation.organization`, plus separation of duties |
| Communication templates | `communications.templates` |
| Manual joining creation | `joining.confirm` |
| Stage-library reorder | `pipeline.configure` |

## 5. Tests

| Area | Tests |
|---|---|
| `tests/Feature/Governance` | 77 tests across 11 files: audit foundation, master-data lifecycle, interviewer and snapshot, offer letter integrity, settings history, incentive pricing, targets and performance, pipeline, automation and templates, configuration fingerprint, governance audit |
| `tests/Feature/Security` | `PolicyCoverageTest`, `StrictAuthorizationTest` |

**Existing tests updated for approved behaviour changes**, each with a comment giving the decision:
- scheduling tests use listed interviewers (D8.6-009);
- the interviewer import no longer reactivates (D8.6-009);
- tests re-applying a pipeline or changing automation rules pass an authorised actor and a reason (D8.6-019 / 021);
- the configuration page save includes a reason (D8.6-012);
- a Word re-upload keeps the old file (D8.6-011);
- the SLA metric fingerprint is re-pinned for v2 (D8.6-012);
- a test changing a used rule simulates pre-8.6 data (D8.6-015);
- source fixtures carry their seeded codes (D8.6-007).

**Two pre-existing flaky tests fixed:**
- `StageHistoryEventTest`: order-dependent.
- `ProvisioningAndRehireTest`: a random joining date.

**Failure modes 1–10** from the implementation brief each have a regression test, and 23 mutations of the safeguards were all caught; see `phase-8-6-freeze.md`.

## 6. Known limitations (accepted, documented, not repaired)

| Area | Limitation |
|---|---|
| Offer letters | Offers released before 8.6 have no stored letter; a download regenerates it from current data and says so. |
| Settings | Changes made before 8.6 live only in the audit log. `valueAt()` uses the first recorded change's old value for earlier moments, so a target changed several times before 8.6 cannot be reproduced exactly. |
| Pipeline | Template versions before 8.6 are not reconstructed. A requisition's own pipeline snapshot was, and remains, exact. |
| Incentives | Calculations priced before 8.6 have no pricing snapshot; their stored amount is authoritative and the displayed band is marked "(current rule)". |
| Hiring snapshots | Snapshots before `/3` have no frozen names. |
| Database-level deletes | Foreign-key actions are unchanged (D8.6-006 deferred). A raw SQL delete of master data would still cascade; the application paths are closed. |
| Deletion guards | Model deletion guards do not stop query-builder bulk deletes. The only one is the existing automation cleanup of skipped runs. |
| SLA cache | The 8.5 metric cache (up to 600 s) can serve a result computed before a target change for the current period. Past periods are unaffected; tracked in 8.7 PF-1. |
| Freeze catch-up | Covers the last three months. Older unfrozen months are reported by `governance:audit`. |
| Offers on inactive master data | Creating an offer on a requisition whose designation or location has since been deactivated is refused (D8.6-005, "no new use"); reactivate or choose another. |
| Self-scheduling | Booking a slot owned by an interviewer who has since been removed from the list is refused (D8.6-009). |
| Separation of duties | Checks the latest version's author only. A rule edited by A and activated by B is valid even if B was the original creator. |
| Org attributes | Targets and incentive rules still resolve on the recruiter's current org attributes (P85-BACKLOG-002). |

## 7. Deployment and rollback

**Deploy:**
1. Back up the database.
2. Deploy the code; run `composer install --no-dev` and `npm ci && npm run build`.
3. Run `php artisan migrate --force` (eight additive migrations).
4. Run `php artisan optimize:clear` then `php artisan optimize`. The settings cache prefix changed, so no stale entry is read.
5. Run `php artisan queue:restart`.
6. Run `php artisan governance:audit`. Review WARNING and INFO (pre-8.6 damage is reported, not repaired). Resolve any ERROR (e.g. an orphan automation scope) through the UI before relying on the affected feature.
7. Smoke test:
   - archive and restore an unused master-data record;
   - save the configuration with a reason;
   - release a test offer and download its letter;
   - open a used incentive rule (locked).

**Rollback:**
1. Redeploy the previous code.
2. Optionally run `php artisan migrate:rollback --step=8`. Every down migration drops only the new tables and columns, and data written to them since the deploy is lost with them. Issued letter PDFs stay in `storage/app/private/offer-letters` (harmless).
3. Keeping the columns after a code rollback is safe: they are nullable and unused by older code.

### Production security hotfix (D8.6-030)

- **Branch:** `hotfix/filament-delete-authorization` @ `2fab3fd`, one commit on `main` @ `9cba8e3` (`main` unchanged since). Its suite passed 644/644 on its own worktree, re-verified in this phase.
- **Contents:**
  - `ForbidsDeletion` on the hiring-fact policies;
  - explicit bulk abilities on 15 policies plus EmployeePolicy;
  - `DeleteAuthorizationTest`;
  - `PolicyActionCoverageTest`.
- **Phase 8.6 includes equivalent and stronger protection:** the same explicit methods, plus strict mode in tests, a production fail-closed gate, and policy coverage for every shown model.
- **Additional gap found this phase, also present on `main`:** `CandidateJoiningPolicy::create` is missing, so any panel user (for example the employee role) can open the manual joining create page and create a pending joining for an application in their hierarchy (Medium; see security review SEC-86-I-01). **Recommended:** add the one-method fix (`create` → `joining.confirm`) to the hotfix before deploying it.
- **Procedure (manual release action; nothing was pushed or deployed):**
  1. `git checkout hotfix/filament-delete-authorization`, then add the joining `create()` fix and its test.
  2. Run the full suite.
  3. Review, then push the branch and merge it to `main` (or cherry-pick) through the normal review.
  4. Deploy `main`, then run `php artisan optimize` and `php artisan queue:restart`.
  5. **Verify:**
     - a recruiter cannot delete an offer, interview, joining or candidate;
     - a manager cannot force-delete a requisition;
     - the employee role gets 403 on `/admin/candidate-joinings/create`.
  6. Read-only check of production `audit_logs` for past `deleted` actions on Offer, Interview, CandidateJoining and Candidate, to learn whether the bypass was ever used.

## 8. Backlog

| ID | Item | Status |
|---|---|---|
| P86-BACKLOG-001 | Foreign-key action hardening for master data | open (deferred by D8.6-006) |
| P86-BACKLOG-002 | Skills and qualification taxonomy (with P7-BACKLOG-001) | open |
| P86-BACKLOG-003 | Scheduler and job request id | **superseded by D8.7-014** |
| P86-BACKLOG-004 | Audit retention and immutability guard | open (8.8, legal) |
| P86-BACKLOG-005 | Configuration maker-checker workflow | open |
| P86-BACKLOG-006 | Employee org history (= P85-BACKLOG-002) | open |
| P86-BACKLOG-007 | Deploy the SEC-1 hotfix, with the joining-create fix | **production action pending** |
| P86-BACKLOG-008 | Stage-library deactivation (`setActive`) is not re-validated against templates (only milestone and terminal are) | open, Low |
| P86-BACKLOG-009 | Issued offer letter storage growth and retention (PDFs are kept indefinitely) | open, with 8.8 |
| P86-BACKLOG-010 | Settings-sensitive metric cache key | open (8.7 PF-1) |
