# Phase 8.10 Decision Register (Discovery)

**For:** the project owner, Product, Security, Operations, Legal / Compliance, Finance / Payroll, and Engineering.

**Status: discovery only.**
- No decision below has been taken.
- Engineering gives a **technical recommendation** only where the question is technical. Business, legal, financial and product questions carry no recommendation.
- Baseline: `feature/sep_25_hrm` @ `5d522df`. Phase 8.9 is frozen.
- Finding IDs (P810-…) refer to `phase-8-10-discovery.md` and `phase-8-10-security-review.md`.

## 1. Review of earlier decisions

### 1.1 Phase 8.9 (`phase-8-9-decision-record.md`)

| ID | Subject | Owner | Status at 8.9 freeze | Classification for 8.10 | Note |
|---|---|---|---|---|---|
| D8.9-001…006, 012, 025 | Supported scale, capacity, concurrency, SLOs, review cadence, support boundary | Product + Ops | open | needs business input and production information | Unchanged. No production volumes exist (P89-OPS-003). The Phase 8.7 scale line in `queue-operations.md:37` still stands (P810-DOC-03). |
| D8.9-007…010, 028 | RTO, RPO, backup policy, DR, DR testing | Ops (+ Legal) | open | **blocks production**; needs production information | Re-verified: no backup package, command or schedule exists (§2 of the discovery). |
| D8.9-011 | Load-testing policy | Ops + Security | open | needs production / infrastructure information | Benchmarks B1–B5 (discovery §11) need it. |
| D8.9-013 | Database scaling | Engineering + Ops | partly done; replica / partitioning open | still valid | — |
| D8.9-014 | Cache / session / queue stores in MySQL | Engineering + Ops | open | still valid; **reconsider together with D8.10-001** | P810-DI-14: moving the queue or cache off MySQL changes in-transaction side effects. |
| D8.9-015 | Search scaling | Product + Engineering | partly done | still valid | P810-OP-12: the command palette bypasses exact routing. |
| D8.9-016 | Analytics materialization | Product + Engineering | open | still valid; gates P85-BACKLOG-003 / 007 and P86-BACKLOG-010 | — |
| D8.9-017 | File storage scaling | Ops + Engineering (+ Legal) | partly done | still valid | P810-OP-11: object storage needs a dependency and code changes, not just configuration. |
| D8.9-018 | Worker scaling | Engineering + Ops | partly done | still valid | P810-OP-04: heartbeats are per queue list, not per process. |
| D8.9-019, 020, 021, 023, 030 | Observability, alerting, incident severity, zero downtime, log handling | Ops | open (030 partly done) | needs production / infrastructure information | The freeze record lists "D8.9-001…028" as open; D8.9-030 is also partly open (log retention, tooling). This is informational only. |
| D8.9-022 | Deployment strategy | Ops + Engineering | done in compose, not deployed | **needs reconsideration** | P810-OP-01: the shipped image cannot build. P810-OP-02: the first release is Phases 4–8.9 at once. See D8.10-003 and D8.10-004. |
| D8.9-024 | Audit and time-series growth | Engineering + Ops + Product | open | still valid | — |
| D8.9-026 | Provide production facts | Ops | open | **blocks** the verification of SEC-88-10, SEC-88-27, P89-SEC-006 and P810-SEC-001 exploitability | — |
| D8.9-027 | Production hotfix release | Security + Ops | open | **blocks production; reconsider** | Superseded by D8.10-002 for the choice between the hotfix and a full release. |
| D8.9-029 | Concurrency remediation scope | Engineering + Product | implemented (under the 8.9 brief) | still valid | New residuals: P810-DI-13 (interview scheduling has no lock) and DI-11. |
| D8.9-031 | Daily-target bulk-delete authorization | Security | implemented | still valid | — |

### 1.2 Phase 8.8 (`phase-8-8-decision-record.md`, export and retention packages)

| ID | Subject | Status | Classification for 8.10 | Linked 8.10 findings |
|---|---|---|---|---|
| D8.8-001, 036 | Candidate authentication model; containment of SEC-88-01 | approved, implemented | valid | P810-SEC-001 (Host-poisoned reset links) affects D8.8-001's link model |
| D8.8-037 | Recruiter visibility of portal links | approved | valid | — |
| D8.8-002, 003 | Session / token lifetime; account recovery | BLOCKED | business input | P810-SEC-001, P810-SEC-003 |
| D8.8-004 | Email / phone changes | BLOCKED | business input | SEC-88-04 remainder |
| D8.8-005, 006, 007 | Portal scope; status visibility; withdrawal | BLOCKED | business input; **blocks** candidate self-service (D8.10-016) | P810-PM-04 |
| D8.8-008…012, 031…033, RETENTION-001 (R-1…R-13) | Documents, retention, export of personal data, deletion, legal retention, audit retention, anonymization, portability | BLOCKED (R-14 decided: SEC-88-02 deferred) | Legal input; **blocks** data-subject rights (P810-PM-03) | — |
| D8.8-013, 014, 015 | Duplicate merge; import duplicate policy; import rollback | BLOCKED | business input | P810-PM-21 |
| D8.8-016, 017, 030, EXPORT-001 (X-1, 2, 6, 7, 10) | Export retention, bulk limits, download authorization | BLOCKED / partly approved | business input | — |
| D8.8-018, 019, 020, 021, 034 | Public API scope, API authentication, webhook model, webhook signing, API data minimization | BLOCKED | business input; **folded into D8.10-015** | P810-PM-05 |
| D8.8-022 | Rate limiting | BLOCKED | business input | SEC-88-21, P810-SEC-007 |
| D8.8-023, 024 | Scheduling link expiry; rescheduling rules | BLOCKED | business input | SEC-88-07, P810-PM-11 |
| D8.8-025, 026 | Consent model; transactional vs marketing | BLOCKED | Legal input | `privacy_consent` is discarded (P810-PM-03) |
| D8.8-027, 028 | Portal closure on conversion; employee / candidate identity | BLOCKED | business input | SEC-88-18 |
| D8.8-029 | Malware scanning | BLOCKED | Security input | — |
| D8.8-035 | Background import / export | BLOCKED | partly superseded (8.9 queued the interviewer import) | SEC-88-25 remainder |
| D8.8-038 | Candidate message timezone | BLOCKED (Product) | **needs reconsideration and widening** | P810-PM-01 shows the problem goes beyond messages, to calendars and manual scheduling. See D8.10-006. |

### 1.3 Phases 8.5–8.7

| ID | Subject | Status | Classification |
|---|---|---|---|
| D8.7-022 (c) | Automation priority groups | open | business input (P87-BACKLOG-002) |
| D8.7-025 (b) | Daily metrics → governed offer definition | open | business input (Product + payroll; P87-BACKLOG-001) |
| D8.7-027 (c) | Redis / horizontal scale | open | duplicate of D8.9-014 / 018 |
| D8.7-028 (b) | Ops alerting e-mail | open | duplicate of D8.9-020 |
| D8.6-006 | Foreign-key action hardening | deferred | still valid; **widen** to the financial and history tables (P810-DI-17) |
| D8.6-030 | Hotfix release | superseded by D8.9-027, then by D8.10-002 | — |
| 8.5 D15 | Stage-entry fact table | deferred | superseded by D8.9-016 |
| 8.5 D49 | "No target" incentive pricing | unchanged | business input (P85-BACKLOG-004) |

### 1.4 Contradictions and register inconsistencies

**Implemented decisions contradicted by the code (verified):**
- **D8.7-003 / 004 ("declare `tries`; backoff never zero").** Three queued mailables declare neither: `CandidatePortalLink`, `CandidateStepUpCode` (both on the `security` queue) and `AiCopilotEmail`. They inherit the worker's `--tries=3` with zero backoff. `QueueContractTest` scans only `app/Jobs` and `app/Listeners`. See P810-OP-20.
- **D8.7-027 (b) supported-scale statement vs D8.9-001 (no supported scale).** The Phase 8.7 line remains in `docs/runbooks/queue-operations.md:37`.
- **ED-08 vs the code.** ED-08 names `cache_locks`, but `PruneExpiredCache` prunes only the `cache` table.

**Needs Legal confirmation:** R-14 decided that no retention prune would be added. ED-08 (8.9) added technical pruning of `password_reset_tokens`, `job_batches` and expired cache rows (`routes/console.php:42-47`), classified as "Engineering (not retention)". Legal should confirm that classification.

**Register-level inconsistencies (documentation only):**
- The 8.9 freeze lists "D8.9-001…028" as open. D8.9-029…031 exist: 029 and 031 are implemented, and 030 is partly open.
- `phase-8-6-decision-record.md` and `phase-8-7-decision-record.md` still say "PROPOSED" in their headers, although their implementation documents record approval and implementation.
- **D8.8-003 (account recovery)** is shown as BLOCKED, but its session-invalidation part was delivered under D8.8-001. The other recovery channels are still undecided.
- **D8.8-035 (background import / export)** is shown as BLOCKED, but it was mostly delivered in 8.9: the `exports` queue, and `ImportInterviewersJob` on `documents`.
- **D8.9-022.** The decision record says it was implemented in compose; the freeze and operational documents treat deployment as open. Both are true for different parts. It needs reconsideration anyway: P810-OP-01 and OP-02.
- **SEC-88-05, 07 and 14** are "Deferred" in `backlog.md` but "accepted (B)" in the security reviews.

None of these changes runtime behaviour. They are proposed for documentation correction in Phase 8.10.

## 2. New decisions required (D8.10)

Each entry follows the same layout. "Tech rec." appears only when the question is technical.

### D8.10-001: Tenancy model
- **Decision required:** stay single-organisation, deploy one instance per customer, or move to shared-database multi-tenancy.
- **Why:** the product is single-organisation by design (`phase-8-10-discovery.md` §8). There is no tenant column on about 100 models. Roles, settings, master data, documents, the AI corpus, integration credentials, cache keys and audit are all global. API, webhook and integration work (D8.10-015) would hard-code whichever model is chosen.
- **Options:**
  - (a) single organisation, as today;
  - (b) one instance per customer;
  - (c) shared database, with a tenant key on every table.
- **Technical consequences:**
  - (b) needs deployment automation (P810-OP-01, 08, 09) and configurable branding and timezone (P810-OP-18, PM-01).
  - (c) is a cross-cutting rewrite: every scope, unique index, cache key, command, `RowLock`, Spatie teams, and `HierarchyService` "view-all" made tenant-bound.
- **Security consequences:**
  - (c) brings cross-tenant leakage risk into every query, and duplicate detection and identity uniqueness must become per-tenant.
  - (b) isolates by infrastructure.
- **Operational consequences:** (b) multiplies deployments; (c) requires per-tenant fairness in queues.
- **Reversibility:** (a) → (b) is easy. (c) is hard to reverse.
- **Tech rec.:** none. This is a business and product decision. Engineering notes that (b) is the smallest step from the current architecture.

### D8.10-002: Production remediation path for the delete-authorization gap (supersedes D8.9-027)
- **Decision required:** close the Critical production gap (Phase 8.6 SEC-1) in one of two ways:
  - (a) release the hotfix line (`2fab3fd` plus the missing `CandidateJoiningPolicy::create()`, SEC-86-I-01) to `main` / `production`;
  - (b) wait for, and release, this branch, which carries a stronger, strict-authorization fix.
- **Why:** `2fab3fd` is local-only and unmerged. It still lacks SEC-86-I-01. The full branch release is large (D8.10-003).
- **Options:**
  - (a) hotfix first, then the branch;
  - (b) the branch only.
- **Technical consequences:**
  - (a) needs the SEC-86-I-01 addition and a small release on the `9cba8e3` line.
  - (b) depends on D8.10-003 / 004 and on the backup prerequisite.
- **Security consequences:** (b) leaves the gap live until the full release.
- **Operational consequences:** (a) means two releases.
- **Reversibility:** both are reversible through image tags.
- **Tech rec.:**
  - (a) closes the Critical exposure soonest, provided the hotfix is completed with `create()` and tested on the production line.
  - Security and Operations decide.

### D8.10-003: First-release strategy for Phases 4–8.9
- **Decision required:** how the branch reaches production.
- **Why:** P810-OP-02 — 154 commits, 941 changed application files, 89 new migrations (75 → 164) including data backfills. No upgrade from a `9cba8e3` schema has been rehearsed.
- **Options:**
  - (a) one planned migration release, after a rehearsal on a restored production copy;
  - (b) staged releases by phase.
- **Technical consequences:**
  - (a) needs benchmark B4 (migration timing), a restore-based rollback plan and a maintenance window.
  - (b) needs intermediate release branches and replaying 8.x history; this is costly.
- **Security consequences:** affects when D8.10-002 (b) can land.
- **Operational consequences:** a downtime window must be decided (D8.9-023).
- **Reversibility:** migrations are forward-only, so rollback means restoring a backup (P89-OPS-001).
- **Tech rec.:** (a), with a mandatory rehearsal and a tested restore first.

### D8.10-004: Production PHP runtime version
- **Decision required:** which PHP version the production image uses.
- **Why:** P810-OP-01. The Dockerfile pins PHP 8.3, but the lock file requires PHP 8.4.1 or later: `symfony/*` 8.1.x require `>=8.4.1`, `platform_check.php` enforces 80401, and `config/database.php` uses `Pdo\Mysql`. The test suite and development run on PHP 8.5.
- **Options:**
  - (a) 8.5, the version tested;
  - (b) 8.4.x, which would need re-verification of the full suite.
- **Technical consequences:** align the `Dockerfile` `PHP_VERSION`, `composer.json` `php`, and `config.platform.php`, and remove the `pdo_pgsql` / `pdo_sqlite` builds that have no `-dev` packages.
- **Security consequences:** none beyond staying on a supported version.
- **Operational consequences:** the hosting must offer that version.
- **Reversibility:** easy.
- **Tech rec.:** (a), PHP 8.5, which is where the 2,064-test baseline was verified.

### D8.10-005: CI/CD and verification tooling (dependency approval)
- **Decision required:** approve the CI platform and new development dependencies:
  - a static analyser (PHPStan / Larastan);
  - committing the browser smokes (Playwright) to the repository;
  - an image registry.
- **Why:**
  - No CI exists (P810-OP-08).
  - The browser regression (202 / 202) and the MySQL race suite cannot be reproduced from the repository.
  - The image-build defect (P810-OP-01) went unnoticed.
  - CLAUDE.md requires approval for new dependencies.
- **Options:** CI provider and scope; which tools to add.
- **Technical consequences:** pipeline stages:
  - `composer validate` plus the platform check;
  - Pint;
  - parallel Pest;
  - MySQL migrate-fresh plus the concurrency suite;
  - `docker build` and push.
- **Security consequences:** CI secrets handling.
- **Operational consequences:** immutable images for rollback (P810-OP-09).
- **Reversibility:** easy.
- **Tech rec.:** adopt CI with at least build, Pint, Pest and a MySQL job before production.

### D8.10-006: Time-zone semantics for interviews and candidate-facing times (widens D8.8-038)
- **Decision required:** the canonical display and entry timezone: business, location, interviewer or candidate.
- **Why:** P810-PM-01 / DQ-88-14:
  - The app timezone is UTC.
  - Manually entered interview times are taken as UTC wall-clock.
  - Self-scheduling slots are stored as true UTC.
  - Messages and the portal print `scheduled_at` with no zone.
  - Calendar sync sends UTC.
  - Related: P810-DI-06 (`actual_doj` is the UTC click date) and P810-OP-18 (offer-letter date).
- **Options:**
  - (a) one business timezone everywhere;
  - (b) a timezone per location;
  - (c) a timezone per user and candidate.
- **Technical consequences:**
  - a Filament display timezone;
  - conversion in `TemplateRenderer`, the portal views and the calendar providers;
  - a data audit of existing interview rows. **Historical repair needs separate approval.**
- **Security consequences:** none.
- **Operational consequences:** candidate-facing correctness.
- **Reversibility:** display choices are reversible. A data correction of stored times is not.
- **Tech rec.:** store UTC everywhere and display with an explicit zone label. Which zone is a Product choice.

### D8.10-007: Hiring-manager and interviewer participation model
- **Decision required:** whether the product gets a requisition-scoped hiring-manager / interviewer role and surface, and what it may see and do.
- **Why:** P810-PM-02:
  - No such role exists, and the `employee` role holds only `referrals.submit`.
  - Application visibility follows the recruiter hierarchy, not requisition ownership.
  - `InterviewFeedbackService::canSubmit` allows the assigned interviewer, but no UI is reachable without `interviews.manage`.
- **Options:**
  - (a) keep HR-only operation;
  - (b) a scoped role with a minimal UI (my requisitions, candidates, interviews and feedback; approve or decline);
  - (c) external reviewer links.
- **Technical consequences:**
  - a new visibility dimension (requisition ownership) in policies, scopes, the AI projector and the metrics scope;
  - widespread tests are needed.
- **Security consequences:** a new authorization path; compensation and PII exposure must be decided.
- **Operational consequences:** user provisioning for line managers.
- **Reversibility:** moderate.
- **Tech rec.:** none (product). If (b), engineering recommends deriving visibility from explicit requisition assignment, never from hierarchy alone.

### D8.10-008: Offer governance
- **Decision required:**
  - whether the offer releaser must differ from its creator (maker-checker);
  - whether offers are validated against the requisition salary band;
  - whether an approval chain by amount, band or grade is needed.
- **Why:** P810-PM-06:
  - `offers.release` alone lets one person create and release an out-of-band offer.
  - There is no band check.
  - The Compensation section of `OfferForm` is not gated by `compensation.view` (security part: P810-SEC-005).
- **Options:**
  - (a) as today;
  - (b) maker-checker only;
  - (c) band validation with an override reason;
  - (d) a configurable approval chain.
- **Technical consequences:** new rules in `OfferService`. Chain configuration may need a table and therefore a migration.
- **Security consequences:** stronger separation of duties.
- **Operational consequences:** approval latency.
- **Reversibility:** moderate.
- **Tech rec.:** none (HR / Finance policy).

### D8.10-009: Incentive eligibility and payment integrity rules
- **Decision required:** the business rules for:
  - (a) manual incentive calculation preconditions and event dates (P810-DI-01);
  - (b) whether a one-shot trigger may be priced more than once across periods (DI-01);
  - (c) anchoring the referral bonus on the joining record rather than the stage (DI-02);
  - (d) reconciling payments with the effective amount, adjustments after Paid / Reversed, and supplementary payments for top-ups (DI-07);
  - (e) whether the retention hold checks separation and closure (DI-08).
- **Why:** these findings can produce or keep incentive amounts for non-hires, duplicates or early leavers. Human verification and approval are the only compensating controls today.
- **Options:** per item, enforce in the calculator and service, or accept with documented manual controls.
- **Technical consequences:**
  - eligibility guards in `RecruiterIncentiveCalculator` and `ReferralService`;
  - possibly a unique index per (rule, application) for one-shot triggers, which is a migration;
  - payment-reconciliation fields;
  - **no formula change**.
- **Security consequences:** fraud resistance.
- **Operational consequences:** existing calculations are not repaired without separate approval.
- **Reversibility:** guards are reversible; a unique index needs a data check first.
- **Tech rec.:** none on the rules (Finance / Payroll). Engineering recommends that whatever is decided be enforced in the services, not only in the UI.

### D8.10-010: Ownership of lifecycle milestones and the post-hire closure policy
- **Decision required:**
  - whether offer, joining and post-join stages may be entered only through their domain services;
  - whether an application may be rejected or dropped after Joined, or must use separation.
- **Why:**
  - P810-DI-03: manual moves to OfferAccepted or Joined are allowed for applications without a pipeline and through configured rules. `LifecycleAuditor` claims Phase 8.3 prevents this.
  - P810-AI-05: the Copilot can propose any stage.
  - P810-DI-05: closing after Joined leaves the hire, the incentive and the referral contradictory.
- **Options:**
  - (a) reserve the milestones to the services;
  - (b) allow with an audited override.
- **Technical consequences:** guards in `StageTransitionService::advance` / `moveToStage`; restrict the AI tool's stages.
- **Security consequences:** closes an AI-proposal path.
- **Operational consequences:** existing anomalies are reported by `lifecycle:audit`; **no historical repair**.
- **Reversibility:** easy.
- **Tech rec.:** (a). This restores the invariant that Phase 8.3 documented.

### D8.10-011: Manual joining-record creation (residual of SEC-86-I-01)
- **Decision required:** whether staff may create a joining record without an accepted offer.
- **Why:** P810-DI-04. The plain `CreateRecord` with `joining.confirm` (the recruiter role holds it), followed by Mark Joined:
  - moves the application to Joined;
  - prices the incentive;
  - counts a hire.
  - The `offer_id` select lists every offer, not only those of the application.
- **Options:**
  - (a) forbid;
  - (b) allow with an audited reason and a second approver;
  - (c) as today.
- **Technical consequences:** route creation through `CandidateJoiningService`; filter the offer select.
- **Security consequences:** closes a privilege path from recruiter to incentive.
- **Reversibility:** easy.
- **Tech rec.:** (a) or (b). (c) is not recommended.

### D8.10-012: AI action approval UX and separation of duties
- **Decision required:**
  - whether High-Impact and External AI actions need a full parameter preview with edit before approval (P810-AI-01);
  - whether they need a second approver who is not the requester (P84-BACKLOG-006).
- **Why:** the approval card hides the stage, rejection reason, schedule and email body. Approval is one click, and the requester approves their own proposal.
- **Options:**
  - (a) a complete preview only;
  - (b) a preview plus a typed confirmation for High-Impact actions;
  - (c) (b) plus a second approver.
- **Technical consequences:** preview builders per tool, plus a completeness contract test.
- **Security consequences:** informed human decisions, as the "AI recommends, humans decide" principle intends.
- **Operational consequences:** friction for bulk actions.
- **Reversibility:** easy.
- **Tech rec.:** at least (a). The completeness test is an engineering safeguard regardless of the option chosen.

### D8.10-013: Use of intelligence scores in automation
- **Decision required:** whether Talent Signal bands, Hiring Health statuses and Risk Radar outputs may drive automated candidate-affecting actions (hold, message, move to Screened).
- **Why:** P810-AI-04. The registry exposes these fields as conditions, and the validator does not restrict their combination with adverse actions. This touches automated decision-making rules in hiring.
- **Options:**
  - (a) allow, as today;
  - (b) allow only for non-adverse actions;
  - (c) forbid, and route to a recruiter action item instead.
- **Technical consequences:** validator rules; a staleness check on the band.
- **Security / legal consequences:** exposure under automated-decision regulation.
- **Reversibility:** easy.
- **Tech rec.:** none (Product + Legal).

### D8.10-014: AI provider governance
- **Decision required:**
  - the documented data-processing terms, region, tier and training-use position for the AI provider;
  - whether preview models may be used in production.
- **Why:** P810-AI-15. `config/ai.php` notes development on a free-tier key. The `advanced` and `planning` categories default to a preview model.
- **Options:** provider contract tier; GA-only models.
- **Technical consequences:** configuration only.
- **Security consequences:** data-protection posture of pseudonymised prompts.
- **Reversibility:** easy.
- **Tech rec.:** GA models in production. The contract is Security / Legal.

### D8.10-015: API, outbound events and SSO strategy (absorbs D8.8-018 / 019 / 020 / 021 / 034)
- **Decision required:**
  - whether and when to offer a versioned API, which needs a token mechanism; P84-BACKLOG-001 currently forbids token packages by architecture test;
  - outbound webhooks / an event outbox;
  - SSO (SAML / OIDC / SCIM).
- **Why:** P810-PM-05. No API, no outbound events and no SSO exist, which blocks HRIS, payroll, BI and IdP integration.
- **Options:** each component independently.
- **Technical consequences:**
  - token revocation must hook into `StaffAccessService`;
  - an event outbox needs a table;
  - SSO touches the MFA policy.
- **Security consequences:** a new authentication surface, data minimization (D8.8-034), webhook signing.
- **Operational consequences:** API support and versioning.
- **Reversibility:** an API, once published, is hard to withdraw.
- **Tech rec.:** none on whether to build. Decide D8.10-001 first.

### D8.10-016: Candidate offer self-service and e-signature
- **Decision required:**
  - whether candidates view, accept or decline offers in the portal;
  - whether acceptance evidence (timestamp, IP, signed document) is captured;
  - which e-signature provider, if any.
- **Why:** P810-PM-04. Acceptance is recorded by staff, with no candidate evidence.
- **Dependencies:** D8.8-005 / 006 / 007 (portal scope, visibility, withdrawal).
- **Technical consequences:** portal routes, policies and audit; a provider adapter.
- **Security consequences:** offer documents exposed to the portal identity.
- **Reversibility:** moderate.
- **Tech rec.:** none.

### D8.10-017: Requisition governance
- **Decision required:**
  - whether approved terms (openings, salary band, opening date) are locked or need re-approval when edited;
  - whether a requisition with applications, hires or costs may be soft-deleted;
  - whether requisitions can be reopened and auto-closed when filled.
- **Why:**
  - P810-DI-09 / PM-08: requisition edits are unaudited.
  - Soft deletion makes the Hires, TimeToFill and CostPerHire populations disagree.
  - It can also crash `markJoined` (null requisition).
- **Options:** per item.
- **Technical consequences:** field-level audit of the requisition (needs the rule revision below); add guards.
- **Reversibility:** easy.
- **Tech rec.:**
  - Audit requisition field edits, and refuse deletion while dependants exist (integrity).
  - Locking terms and reopening are Product decisions.
- **Rule conflict.** `.ai/rules/concerns-models.md` says not to add `Auditable` to RecruitmentRequisition or CandidateApplication, because they have status or approval histories. Those histories do not record field edits. Approving field-level audit means revising that rule.

### D8.10-018: Cost-per-hire cost population (metric definition)
- **Decision required:** whether Draft (unapproved) recruitment costs count in `cost.cost_per_hire`.
- **Why:** P810-DI-10. The governed spec sums every `recruitment_costs.amount`. The status lifecycle (Draft / Approved / Paid) is ignored, and negative amounts are accepted.
- **Options:**
  - (a) all costs, as today;
  - (b) Approved and Paid only;
  - (c) Paid only.
- **Technical consequences:** a versioned metric change (new metric version); input validation.
- **Reversibility:** versioned, so reversible.
- **Tech rec.:** none (metric owner). **No metric definition is changed without this decision.**

### D8.10-019: Localization, internationalization and accessibility targets
- **Decision required:**
  - the target markets: currency, phone format, names, languages;
  - the accessibility standard, for example WCAG 2.1 AA.
- **Why:**
  - P810-PM-18: INR hard-coded; phone numbers normalised to the last 10 digits with +91; employee `last_name` required; no translation layer.
  - P810-PM-19: unlabelled controls and colour-only status in custom views.
- **Options:** India-only; multi-country; specific locales.
- **Technical consequences:** currency columns (migrations), E.164 phone handling, a translation layer, an accessibility pass.
- **Reversibility:** moderate.
- **Tech rec.:** none (markets). The accessibility fixes on the custom views are recommended regardless.

### D8.10-020: Security dependency updates (added during implementation, 2026-10-03)
- **Decision required:** approve patch-level updates of `league/commonmark` (2.10.0 → ≥ 2.10.2; 2.10.3 is available) and `laravel/framework` (v13.29.0 → ≥ v13.30.0; v13.34.0 is available), with `--with-dependencies` limited to what these need.
- **Why:** P810-SEC-015. `composer audit` reports a High denial of service and a Medium raw-HTML-filter bypass in `league/commonmark`, plus a Low debug-page XSS in Laravel. CLAUDE.md requires approval for dependency changes.
- **Options:**
  - (a) update both now;
  - (b) update `league/commonmark` only;
  - (c) defer and accept the risk, with a documented exposure assessment.
- **Technical consequences:** lock changes only within the current majors. The full suite, browser smokes and a build re-run are required after the update.
- **Security consequences:** closes the advisories. Deferring leaves a denial-of-service path through rendered model output.
- **Operational consequences:** none beyond re-verification.
- **Reversibility:** easy (lock revert).
- **Tech rec.:** (a). Patch and minor updates within the same major, then full verification.

### Status updates during implementation
- **D8.10-020 (dependency security).** **APPROVED by the owner and IMPLEMENTED** (`ae48029`):
  - `laravel/framework` v13.29.0 → v13.30.1;
  - `league/commonmark` 2.10.0 → 2.10.3;
  - no other change; `composer audit` clean.
  
  The production line keeps the old versions (see D8.10-002).
- **D8.10-004 (PHP runtime).** Implemented in code per the technical recommendation: PHP 8.5, image `php:8.5.11-*-trixie` pinned by digest (`bd32662`). The owner may still override. The test suite was verified on 8.5.4 locally and must also run in the built image.
- **D8.10-005 (CI / tooling).** **BLOCKED: Docker-capable runner unavailable.** The image from `bd32662` / `ae48029` has never been built. **P810-OP-01 cannot be closed** until it is built and tested on such a runner. No container tooling was installed (not authorised).
- **D8.10-002 (production remediation path).** Exact patch identified and tested (`phase-8-10-release-readiness.md` §2).
  - **Patch:** `9cba8e3` + `2fab3fd` + `599f0c5` (local branch `hotfix/p810-production-authorization`). Policies and tests only, no migration; 646 production-line tests pass.
  - **Technical recommendation:** hotfix first, as its own release.
  - **Still to decide (Security + Operations):**
    - (a) release it or not;
    - (b) whether to bring the D8.10-020 dependency updates to the production line in the same release (the hotfix branch keeps `laravel/framework` 13.29.0 and `league/commonmark` 2.10.0);
    - (c) how the production line is built, since its Dockerfile has the same PHP 8.3 defect.
  - Not merged, not deployed.
- **D8.10-003 (first-release strategy).** The rehearsal evidence now exists (`phase-8-10-release-readiness.md` §4): 89 / 89 migrations in 32.75 s on 91k synthetic rows; schema identical to a fresh install; restore-based rollback verified; `migrate:rollback` is not a data rollback. **PRODUCTION BASELINE NOT VERIFIED.** The decision is still open.
- **D8.9-007…010, 028 (backup).** Still OPEN. The engineering capability is tested on the development host (`phase-8-10-release-readiness.md` §3). No production backup exists.

### D8.10-021: Build route for Release A (production authorization patch), added 2026-10-03
- **Decision required:** how Release A (`9cba8e3` + `2fab3fd` + `599f0c5`) is built into a deployable artefact.
- **Why (FACT):** the production line's Dockerfile pins PHP 8.3 while its lock needs PHP ≥ 8.4.1 (the same defect as P810-OP-01). It cannot build from its own Dockerfile. How production is built today is unknown (D8.9-026).
- **Options:**
  - (a) use production's existing build mechanism, once known;
  - (b) add the Dockerfile correction (`bd32662`'s Dockerfile hunk only) to Release A as a separate, explicit commit;
  - (c) combine Releases A and B. This must be recorded explicitly with the exact commit range, and is not inferred.
- **Technical consequences:** (b) adds a build-only change to an otherwise policy-only release. Every option needs a Docker-capable runner to build and test (D8.10-005).
- **Security consequences:** the Critical production gap stays open until Release A is built and deployed.
- **Operational consequences:** depends on the production deploy mechanism.
- **Reversibility:** easy.
- **Tech rec.:** (a) if production's build works today. Otherwise (b), kept as its own commit so the release content stays auditable.

### Exact remaining owner decisions for backup (P89-OPS-001), 2026-10-03

D8.9-009 is split into its four parts so that nothing is implied:

| ID | Decision | Owner | Status |
|---|---|---|---|
| D8.9-007 | RTO | Operations + Product | OPEN |
| D8.9-008 | RPO, including binlog / point-in-time recovery (P810-OP-06) | Operations + Product | OPEN |
| D8.9-009a | Backup frequency | Operations | OPEN |
| D8.9-009b | Backup retention | Operations + Legal (R-13) | OPEN |
| D8.9-009c | Backup storage location (off-host) | Operations | OPEN |
| D8.9-009d | Encryption key custody and rotation | Operations + Security | OPEN |
| D8.9-010 | DR strategy (site, standby) | Operations | OPEN |
| D8.9-028 | Restore-test cadence | Operations | OPEN |

Engineering does not set any of these values. The tested procedure is in `phase-8-10-release-readiness.md` §3.

### Status at 2026-10-03 (continuation)
- **D8.10-020:** COMPLETE (`ae48029`).
- **D8.10-002:** **APPROVED IN PRINCIPLE** by the owner, to prepare an isolated production authorization release (Release A = `9cba8e3` + `2fab3fd` + `599f0c5`).
  - Prepared and verified: 26 files, policies and tests only, +454 / −0. The four hotfix tests fail 4 / 4 on unpatched `9cba8e3` and pass on `599f0c5`.
  - **Deployment not approved:** it is gated by D8.10-021, D8.10-005 and the release gates.
  - Release B (the Phase 8.10 dependency and runtime release) is kept separate. A combined release needs its own explicit decision.
- **D8.10-005:** **BLOCKED.** Docker-capable runner unavailable. Minimum runner requirements are in `phase-8-10-release-readiness.md` §7.
- **D8.10-003:** OPEN. The rehearsal is accepted as "successful, production baseline not verified". The owner must accept that limited rehearsal or provide production facts.
- **D8.9-026:** **OWNER / INFRASTRUCTURE INPUT REQUIRED.** The exact facts to collect are in `phase-8-10-release-readiness.md` §6.

### Status at 2026-10-03 (Workstreams C and D)

The authorization "Phase 8.10 — Parallel Security & Data Integrity Hardening" settled these parts. Each is implemented on the branch only.

- **D8.10-009 (incentive eligibility):**
  - **(a) Preconditions: DECIDED and IMPLEMENTED** (`2404763`). Selection needs Selected, Offer accepted needs an Accepted offer, Joining needs a Joined joining. **Event dates: still OPEN.** Manual Selection and Offer-accepted pricing still uses the day of the run; changing it moves incentives between periods, so it is a business rule.
  - **(b) One calculation per occurrence: DECIDED and IMPLEMENTED** under the rule lock. The DB index is D8.10-022.
  - **(c) Referral bonus anchored on the joining record: DECIDED and IMPLEMENTED** (`1a40e6c`). No historical recalculation.
  - **(d) payment reconciliation and (e) retention checks: OPEN** (DI-07, DI-08).
- **D8.10-011 (manual joining): option (a) IMPLEMENTED** (`612c7a9`). No joining without an accepted offer.
  - The recovery for an accepted offer whose joining is missing stays, through `CandidateJoiningService::createForApplication()`.
  - No offer-less emergency path exists. If the business needs one, option (b) is required: explicit permission, reason, audit, provenance, a hierarchy restriction and a second approver.
  - Pending joinings created by hand before this change can no longer be marked Joined until an offer is accepted through `OfferService`.

### D8.10-022: Database uniqueness for one incentive per occurrence (added 2026-10-03)
- **Decision required:** whether to add a unique index on `recruiter_incentive_calculations (incentive_rule_id, candidate_application_id)`.
- **Why:** P810-DI-01 now enforces one calculation per rule and application in the service, under the rule's row lock. This is proven on MySQL, and every pricing path goes through that lock. A DB constraint would also bind any future path that skips the service.
- **Blocker (STOP condition):**
  - Existing data may already hold cross-month duplicates (the DI-01 defect).
  - The index needs a duplicate report first, and a decision on each duplicate (none may be altered without approval: historical incentive records).
- **Migration if approved:**
  - the exact index;
  - a pre-check query listing duplicate (rule, application) pairs, with their status and amount;
  - a cleanup strategy decided by Finance (no automatic merge);
  - rollback: drop the index.
- **Production impact:** an online index build on a small table; unknown until the production row count is known (D8.9-026).
- **Tech rec.:** run the duplicate report on production data first. Add the index only if it is empty, or after Finance resolves each pair.

### D8.10-023: Production trusted-origin configuration (added 2026-10-03)
- **Decision required:** the production values that P810-SEC-001 depends on. They are environment-specific and are not hard-coded.
  - `APP_URL`: the exact public origin, including any base path.
  - `APP_TRUSTED_HOSTS`: the exact host names, plus `localhost` for the compose health check. It is optional and refuses other Hosts with 400.
  - Apache: a `ServerName` and a default virtual host that rejects unknown hosts (the shipped vhost answers any Host).
  - Whether TLS terminates in front of Apache. If it does, trusted proxies are required (SEC-88-10), or links stay `http://` and a Host-rewriting proxy breaks signed links.
- **Why:** since `6ead373`, links use APP_URL's host in every non-local environment, so a wrong APP_URL breaks every emailed link. The scheme still follows the request.
- **Owner input:** the production front end (part of D8.9-026).
- **Tech rec.:** set APP_URL and APP_TRUSTED_HOSTS. Add the vhost rule once the production hostname is known, and re-open SEC-88-10 if a proxy exists.

### Status at 2026-10-03 (final release readiness)
- **D8.10-012:** the decision-independent floor is IMPLEMENTED (`30f252d`): the approval card shows every argument. The choice of extra friction (typed confirmation, second approver) is **still OPEN** and post-RMS.
- **D8.10-006: OPEN, now an owner release action.** Until it is decided, schedule interviews manually and keep calendar and Zoom sync unconfigured (P810-PM-01, `rms-final-release-candidate.md` §6).
- **D8.10-009:** unchanged: (a) event dates, (d) and (e) are OPEN. SEC-008 (`049799c`) adds separation of duties on approvals, adjustments and payments; it decides none of (a), (d) or (e).
- **D8.10-022, D8.10-023:** unchanged, OPEN; no values invented.
- **D8.10-010, 013, 014:** OPEN; their findings are dispositioned (D, and C for AI-15) in `rms-final-release-candidate.md` §4.

### Status at 2026-10-04 (Phase 8.11)
- **D8.10-002 / 003 / 021:** the strategy recommendation (deploy release candidate `226bc7d`, which contains Release A's protection, verified) is recorded in `phase-8-11-production-release.md` §2. **Awaiting release-owner approval.**
- **D8.10-005, D8.9-007…010, 026, 028, D8.10-006, 014, 023:** unchanged. **OWNER ACTION REQUIRED.** Phase 8.11 is blocked on them (§8 there).

## 3. Summary

| Group | Count |
|---|---|
| New Phase 8.10 decisions (D8.10-001…019 at discovery; D8.10-020…023 added during implementation) | **19 + 4** |
| Earlier decisions still open that block production | D8.9-007…010, 028, 026, 027 → D8.10-002 |
| Earlier decisions that block parts of the proposed 8.10 scope | D8.9-016, D8.9-015, D8.9-014 / 018, D8.8-005…007, D8.8-RETENTION-001, D8.8-EXPORT-001 |
| Earlier decisions needing reconsideration | D8.9-022 (image defect, first-release size), D8.9-027 (→ D8.10-002), D8.8-038 (→ D8.10-006), D8.6-006 (widen) |
