# Phase 8.6 Decision Record: Data Governance, Master Data & Configuration Integrity

**Status:** PROPOSED, awaiting approval. Nothing here is implemented.

**Baseline:**
- HEAD `dcff76e` on `feature/sep_25_hrm`.
- The evidence is in `docs/phase-8-6-discovery.md`; section references (§) point there.

**Legend:**
- **PA = YES**: needs product approval (business policy).
- **PA = NO**: an engineering-governance default that preserves existing semantics. It still takes effect only with the phase approval.

**Scope rules applied to every decision:**
- No decision changes Outcome, lifecycle, identity or incentive-formula semantics.
- No decision repairs history.
- Every migration is additive.

---

## A. Master data

### D8.6-001: Master-data lifecycle (archive vs delete)
- **Question:** How do departments, designations, locations, sources and reasons leave service?
- **Evidence:**
  - Soft delete **plus** force delete, both exposed in Filament to `settings.manage`.
  - Force delete cascades (costs, incentive rules and slabs, targets) and nulls outcome snapshots (§4).
- **Options:**
  - (a) keep as is;
  - (b) Active / Inactive / Archived; archive = soft delete; **force delete removed from UI and policy**;
  - (c) inactive only, with no archive.
- **Impact:** (b) removes irreversible destruction. Codes and labels remain resolvable.
- **Dependencies:** D8.6-002, D8.6-003, D8.6-004.
- **Proposed:** (b). Policy `forceDelete*` returns false (the `ForbidsDeletion` pattern). Archive is allowed only when D8.6-002 passes.
- **PA:** YES (removes a CHRO capability).

### D8.6-002: In-use guard
- **Question:** May a master-data item in use be archived?
- **Evidence:** No guard; some DB restricts produce raw errors (§3, §4).
- **Options:**
  - (a) block when in use;
  - (b) allow archive and block only new use;
  - (c) warn only.
- **Impact:** (b) matches how "inactive" is used in HR practice. Archived items keep their history but cannot be chosen.
- **Dependencies:** D8.6-001, D8.6-005.
- **Proposed:**
  - (b) for Inactive: always allowed, blocks new selection.
  - (a) for Archive: refused while *open* work references the item (open requisitions, active employees, active incentive rules or targets, active automation scope). The refusal message lists the counts.
- **PA:** YES.

### D8.6-003: Master-data audit and reason
- **Question:** Should master-data changes be audited, with a reason?
- **Evidence:** Not audited at all (AG-1); `audit_logs` has no reason column (AG-5).
- **Options:**
  - (a) `Auditable` only;
  - (b) `Auditable` plus a required reason on deactivate, archive and restore;
  - (c) a reason on every edit.
- **Proposed:** (b). Add `audit_logs.reason` (additive).
- **PA:** NO.

### D8.6-004: Historical labels for archived items
- **Question:** How do historical records show archived items?
- **Evidence:** No `withTrashed` on the relations, so labels go blank after soft delete (DR-15).
- **Proposed:** `withTrashed()` on display relations for master data, with an "(archived)" suffix in tables and infolists.
- **PA:** NO.

### D8.6-005: Inactive items enforced on the server
- **Question:** Where is "active only" enforced?
- **Evidence:** Some form selects filter `is_active`; services do not (DQ-6).
- **Proposed:**
  - A shared validation rule refuses inactive or archived master data on **create**, and on **change of that field**.
  - Unchanged historical values on edit remain valid.
- **PA:** NO.

### D8.6-006: Foreign-key action hardening
- **Question:** Change cascade / set-null FKs to restrict?
- **Evidence:** §4. SQLite and MySQL differences; the migrations would change live constraints.
- **Options:**
  - (a) now;
  - (b) defer; application-level guards (D8.6-001 and 002) remove the reachable paths.
- **Proposed:** (b) defer. P86-BACKLOG-001.
- **PA:** NO.

### D8.6-007: Code immutability and seeders
- **Question:** Can codes change? How do seeders behave with archived rows?
- **Evidence:** The code is editable; `firstOrCreate` by code collides with trashed rows (DQ-1).
- **Proposed:**
  - The code is immutable after creation, like `RecruitmentStage`.
  - Seeders use `withTrashed()->firstOrCreate` and never restore or overwrite.
  - Source lookups use the **code**, not the name (career site, referrals; DQ-2).
- **PA:** NO.

### D8.6-008: Frozen dimension names in hiring snapshots
- **Question:** Should snapshots freeze department, designation, location and source names?
- **Evidence:** Snapshots store ids only (source name only); renames and set-null rewrite outcome reports (HR-9, HR-10).
- **Options:**
  - (a) add name columns in a new snapshot version, `hiring-snapshot/3` (new rows only);
  - (b) leave as is.
- **Impact:** Additive. Existing rows are unchanged (no repair). Outcome semantics are unchanged; only the display label source changes.
- **Proposed:** (a).
- **PA:** YES (touches the Outcome snapshot contract version).

### D8.6-009: Interviewer governance
- **Question:** How are interviewers removed and imported?
- **Evidence:** Hard delete; unaudited toggle and import; import silently reactivates; scheduling does not require membership (§3).
- **Proposed:**
  - Deactivate only (delete removed).
  - `Auditable`.
  - Import in a transaction, chunked, with an audited summary, reporting reactivations instead of silently applying them.
  - Scheduling requires an active listed interviewer **only for new interviews**.
- **PA:** YES (the scheduling rule changes a workflow).

---

## B. Offer letters

### D8.6-010: Store the issued offer letter
- **Question:** Should the letter be frozen when released?
- **Evidence:** It is re-rendered on every download from the current template and live merge values (HR-4).
- **Options:**
  - (a) render and store the PDF at release and at each revision release, with a sha256; downloads serve the stored file;
  - (b) store the merged body HTML only.
- **Impact:** Additive table `offer_letters`, private storage. Offers released before 8.6 have no stored letter; they keep today's behaviour and are labelled "regenerated" (no backfill).
- **Proposed:** (a).
- **PA:** YES.

### D8.6-011: Offer template versions and deletion
- **Question:** Should templates be versioned and deletion blocked while referenced?
- **Evidence:** Word re-upload deletes the old file; delete nulls a locked offer term; the default flip is unaudited (§6, SEC-6).
- **Proposed:**
  - `offer_letter_template_versions`: every body or file change creates a version, and old files are kept.
  - Delete is refused when referenced (deactivate instead).
  - The default change is audited.
- **PA:** NO.

---

## C. Settings

### D8.6-012: Settings history and effective dating
- **Question:** Should settings keep history, and should historical metrics use the value in force at the time?
- **Evidence:**
  - `sla.leg_compliance` and the TTH target read live values (HR-1, HR-2).
  - STOP condition 15 is reached: a config change can rewrite historical metrics.
- **Options:**
  - (a) history table only (audit and reproducibility report);
  - (b) history plus as-of resolution in `sla.leg_compliance` and the TTH target status, **as a new governed metric version** (the definition fingerprint changes, and it is documented);
  - (c) no change.
- **Impact:**
  - (b) changes the *reproduction* of past periods. It is visible and versioned, not silent.
  - The 8.5 metric semantics are otherwise untouched.
- **Dependencies:** 8.5 MetricDefinition (a direct dependency, documented here per the 8.5 regression rule).
- **Proposed:** (a) now; (b) only if the product owner approves the metric version change.
- **PA:** YES.

### D8.6-013: Raw settings CRUD
- **Question:** Keep the raw key/value resource?
- **Evidence:** It bypasses typed validation, allows key edits and deletion, and has no float type (SEC-3, DQ-7).
- **Proposed:**
  - Remove the create, edit and delete actions; the resource becomes read-only (list and view).
  - All edits go through the typed page, with cross-field validation and a required reason.
  - Fix the cache: never cache the caller's default forever (DQ-8).
- **PA:** NO.

---

## D. Incentives, targets, performance

### D8.6-014: Incentive pricing snapshot
- **Question:** Should each calculation store the rule and slab parameters it was priced with?
- **Evidence:** No snapshot; the view and statement show live parameters; pending rows are re-priced unaudited (§10).
- **Proposed:**
  - A nullable `pricing_snapshot` JSON is written on each calculation and recalculation; the view prefers it.
  - Historical rows stay null, and the view says "parameters at the time not recorded".
  - A re-price of a pending calculation is audited (old and new amount).
  - **Approved or paid amounts are never touched.**
- **PA:** NO (no formula or pay change).

### D8.6-015: Lock incentive rules and slabs after use
- **Question:** Can the pricing fields of a rule or slab used by any calculation be edited?
- **Evidence:** Freely editable (§10). STOP condition 14 applies to displayed provenance only.
- **Options:**
  - (a) lock the pricing fields once used; a change means ending the rule (`effective_to`) and creating a new one;
  - (b) allow edits and rely on the snapshot.
- **Proposed:** (a). Slabs are `Auditable`; a slab in use cannot be deleted; a rule in use gets a graceful refusal instead of a raw FK error.
- **PA:** YES (changes how HR maintains rules).

### D8.6-016: Effective-range validation
- **Question:** Validate `effective_from` ≤ `effective_to`, and overlap, for incentive rules, targets and performance rules?
- **Evidence:** Not validated; overlapping performance rules double-weight (§11).
- **Proposed:**
  - Order is validated everywhere.
  - An overlap of the same scope, metric and tier is refused for targets and performance rules.
  - Incentive rules warn only (resolution already picks one).
- **PA:** YES (the refusal policy).

### D8.6-017: Performance-month freeze catch-up
- **Question:** What happens if the day-1 freeze does not run?
- **Evidence:** The month stays unfrozen, and live values drift (HR-6).
- **Proposed:** The daily schedule freezes any completed, unfrozen month in the last 3 months (idempotent). No recompute of frozen months.
- **PA:** NO.

---

## E. Pipeline

### D8.6-018: Pipeline template versions
- **Question:** Store each template version's definition?
- **Evidence:** Only an integer is stored; the snapshot records a version that cannot be resolved (HR-7).
- **Proposed:** An additive `recruitment_pipeline_template_versions` row per version. Versions before 8.6 are not reconstructed.
- **PA:** NO.

### D8.6-019: Template re-apply governance
- **Question:** Who may re-apply a template to a requisition, and how is the remap recorded?
- **Evidence:** `requisitions.update`, no status guard, a quiet remap with no history (SEC-2, HR-8).
- **Proposed:**
  - First apply stays at `requisitions.update`. **Re-apply** requires `pipeline.configure` and an open requisition (not Closed, Filled or Cancelled).
  - Each moved application gets a `pipeline_remapped` stage-history event, which is **not** a stage entry: it is excluded by the 8.5 `pipelineStageEntries()` and `milestoneEntries()` scopes, and a test proves this. There is also one audit summary.
  - Library-stage edits re-validate the templates that use them.
- **PA:** YES (changes a permission requirement for managers).

---

## F. Automation and communication

### D8.6-020: Automation separation of duties
- **Question:** Should the author of a rule change be allowed to activate it?
- **Evidence:** Managers hold both `automation.manage` and `automation.activate` (SEC-4).
- **Options:**
  - (a) the activator must differ from the last editor, except CHRO and VP HR;
  - (b) keep as is;
  - (c) organisation-scope rules only.
- **Proposed:** (a), using existing permissions.
- **PA:** YES.

### D8.6-021: Automation version completeness and guards
- **Question:** Version priority, owner and description? Guard DB-level deletes?
- **Evidence:** They are unversioned; versions and executions have no deleting guard (§12).
- **Proposed:**
  - Priority and owner go into the version snapshot (the description stays unversioned).
  - `deleting` guards on versions, executions and escalations.
  - A required change reason on edit, activate, pause and archive, replacing "Edited".
  - Archive cancellations are audited per rule, with counts.
- **PA:** NO.

### D8.6-022: Template dependency check
- **Question:** Can a communication template be archived while an active rule or a built-in flow uses its key?
- **Evidence:** No check; sends are silently skipped (§13).
- **Proposed:** Archive is refused while an active or paused automation rule references the key; a warning is shown for built-in flow keys.
- **PA:** NO.

### D8.6-023: Communication template versioning completeness
- **Question:** Version `provider_template` and status? Link messages to the version row?
- **Evidence:** Wording is versioned only; messages store an integer version (§13).
- **Proposed:** A `provider_template` change creates a version. A nullable `communication_template_version_id` is added on new messages (historical rows keep the integer).
- **PA:** NO.

---

## G. Audit and security

### D8.6-024: Audit model improvements
- **Question:** What minimum audit improvements belong in 8.6?
- **Evidence:** AG-5, AG-6, AG-10, AG-11.
- **Proposed:**
  - A `reason` column.
  - Distinct `restored` and `force_deleted` actions.
  - Redaction applied to explicit `record()` payloads.
  - Hidden-attribute changes recorded as `"[changed]"`.
  - Audit UI: date-range and actor filters, and a request id column and filter.
  - Export is **not** in scope (it depends on 8.8 privacy and retention).
- **PA:** NO.

### D8.6-025: Audit the remaining deletable operational records
- **Question:** Audit follow-ups, manual activities and AI knowledge articles?
- **Evidence:** AG-4.
- **Proposed:** `Auditable` on `RecruitmentFollowup`, `RecruitmentManualActivity` and `AiKnowledgeArticle`, with redacted bodies where they are free text.
- **PA:** NO.

### D8.6-026: Configuration approval workflow
- **Question:** Should configuration changes need a second approver?
- **Options:**
  - (a) none (audit plus reason);
  - (b) separation of duties for automation only (D8.6-020);
  - (c) a maker–checker for settings, incentive rules and pipeline.
- **Proposed:** (b). (c) goes to the backlog as P86-BACKLOG-005.
- **PA:** YES.

### D8.6-027: Filament strict authorization mode
- **Question:** Enable strict authorization so a missing policy method throws instead of allowing?
- **Evidence:** SEC-1 root cause; `PolicyActionCoverageTest` now covers the known actions.
- **Options:**
  - (a) strict in all environments;
  - (b) strict in local and testing only;
  - (c) coverage test only.
- **Proposed:** (b) now (catches regressions in tests without a production failure risk); (a) after one release with no strict-mode failures.
- **PA:** NO.

### D8.6-028: Configuration-in-code fingerprint
- **Question:** Must a change to history-affecting config values (outcomes, metrics, intelligence) bump a version?
- **Evidence:** VG-11, HR-13.
- **Proposed:** An architecture test pins a hash of those config values to the declared rule version. Changing a value without bumping the version fails CI. **No semantic change.**
- **PA:** NO.

### D8.6-029: Governance audit report (no repair)
- **Question:** How is existing damage surfaced?
- **Evidence:** Nulled snapshot dimensions, orphaned automation scopes, calculations with null slabs, released offers without a stored letter.
- **Proposed:** A read-only `governance:audit` command that reports counts and ids. **No writes.** Any repair needs a separate approved decision.
- **PA:** NO.

### D8.6-030: Deploy the SEC-1 hotfix
- **Question:** When should `hotfix/filament-delete-authorization` (`2fab3fd`, from `main`) be deployed?
- **Evidence:**
  - Before containment, any recruiter could permanently delete offers, interviews, joinings and candidates.
  - The branch passes the `main` suite (644).
  - It is not pushed.
- **Options:**
  - (a) push and deploy now, independent of 8.6;
  - (b) ship with 8.6.
- **Proposed:** (a). This also requires a check of production audit logs for past `deleted` actions on those models (read-only).
- **PA:** YES. Push or deploy only on explicit instruction.

---

## Stop conditions reached (documented, not solved)

| # | Condition | Where | Handling |
|---|---|---|---|
| 14 | A config change could alter approved incentives | §10 | Amounts are protected; displayed parameters drift → D8.6-014 and 015 |
| 15 | A config change could rewrite historical metrics | §7 | SLA and TTH targets → D8.6-012 (product decision) |
| 16 | A production security issue needs containment | §17 SEC-1 | Contained on the branch and on the hotfix branch with approval; deployment → D8.6-030 |
| 13 | Legal retention unknown | AG-12 | Deferred to 8.8 |

## Approval summary

| Needs product approval (12) | Engineering defaults (18) |
|---|---|
| 001, 002, 008, 009, 010, 012, 015, 016, 019, 020, 026, 030 | 003, 004, 005, 006, 007, 011, 013, 014, 017, 018, 021, 022, 023, 024, 025, 027, 028, 029 |
