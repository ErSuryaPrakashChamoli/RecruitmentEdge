# Phase 8.10 Discovery: Architectural Hardening & Enterprise Maturity

**For:** the project owner, Product, Security, Operations, Legal / Compliance, Finance / Payroll, and Engineering. It is written to support a decision on the Phase 8.10 scope.

**Status: DISCOVERY COMPLETE — AWAITING APPROVAL.**
- Nothing was implemented.
- No application code, configuration, migration, route, test, `.env` or database data was changed.
- Nothing was merged, pushed or deployed.
- The development offer-letter files were not touched.
- Phase 8.9 stays frozen and is treated as the baseline.

**Proposed phase title: 8.10 Enterprise Release Readiness & Integrity Hardening** (§15).

**Companion documents (discovery only):**
- `docs/phase-8-10-security-review.md`: the fresh security review (§5).
- `docs/phase-8-10-decision-register.md`: the review of earlier decisions and D8.10-001…019 (§14).
- `docs/phase-8-10-backlog-reconciliation.md`: 201 items reconciled (§13).

## 0. Baseline and method

| | |
|---|---|
| Branch | `feature/sep_25_hrm` (no upstream; never pushed) |
| HEAD | `5d522df`, the Phase 8.9 freeze record. The application code was last changed in `1acd789`. |
| Working tree | clean at the start of discovery. Only the four discovery documents were added. |
| Migrations / routes | 164 / 239 (unchanged) |
| Tests (established baseline, not re-run) | 2,064 passed, 21,555 assertions, parallel and serial |
| Browser (established baseline) | 202 / 202 |
| Concurrency (established baseline) | 10 valid two-process MySQL runs, each 8 / 8 |
| Production line | `main` / `production` @ `9cba8e3` (local refs) |
| Hotfix `2fab3fd` | local branch only; not merged, pushed or deployed |

**Document names.** The brief names `phase-8-9-decisions.md`, `phase-8-9-capacity.md` and `phase-8-9-runbooks.md`. The actual files are `phase-8-9-decision-record.md`, `phase-8-9-capacity-model.md` and `docs/runbooks/` (four runbooks). All were read.

**Method.**
- Seven independent read-only reviews were run in parallel:
  1. staff-side authorization;
  2. authentication and the external surface;
  3. the AI / EDGE Intelligence layer;
  4. data integrity of the lifecycle;
  5. product maturity;
  6. platform, operations, scalability and technical debt;
  7. backlog, decision and documentation reconciliation.
- Each review read the code directly.
- The discovery lead then re-traced every **High** finding in the code, plus the material carry-forward facts, before including it.
- No benchmark was re-run (brief §11). No test suite was re-run, because the code is identical to the frozen baseline.

**Labels.**
- **FACT:** read in code, configuration, git or documents.
- **INFERENCE:** reasoned about runtime, deployment or intent; not executed.
- **RECOMMENDATION:** a proposal only.
- Severities: Critical, High, Medium, Low, Informational. For product findings (P810-PM-*), severity means impact on enterprise maturity, not a security rating.

## 1. Executive summary

1. **The core hiring engine is mature; production release is not.**
   - Phases 4–8.9 built a governed lifecycle:
     - single writer services with row locks;
     - append-only histories;
     - versioned metrics;
     - an approval-gated AI layer;
     - an automation engine with separation of duties.
   - **The production release path is blocked by facts outside that core:**
     - the shipped Docker image cannot be built: PHP 8.3 against a lock that needs PHP 8.4.1 or later (**P810-OP-01, High**);
     - the first release would carry Phases 4–8.9 at once, including 89 unrehearsed migrations (**P810-OP-02, High**);
     - no backup exists (P89-OPS-001);
     - the Critical delete-authorization gap is still live on the production line (P89-OPS-012).
2. **Two new High security findings** (§5):
   - emailed password links can be pointed at an attacker's host through the `Host` header (**P810-SEC-001**);
   - an unscoped candidate picker lets a recruiter search every candidate and bring any of them into their own scope (**P810-SEC-004**).
   
   There is no new Critical finding.
3. **Three High data-integrity findings, all in incentive and joining paths** (§6). Each can create or keep incentive amounts, or hire facts, that do not correspond to a real accepted-offer hire:
   - manual incentive calculation without lifecycle preconditions (**DI-01**);
   - the referral bonus anchored on the stage rather than the joining record (**DI-02**);
   - manual joining creation that bypasses the offer chain (**DI-04**).
   
   Human verification and approval are the only compensating controls today.
4. **"AI recommends, humans decide" holds for model output, with qualifications** (§7):
   - the approval card does not show what is being approved (**AI-01, High**);
   - the Copilot can propose decision-stage moves (AI-05);
   - deterministic scores can drive automated holds and messages (AI-04).
5. **Enterprise product gaps** (§9). These are mostly decision-gated:
   - interview times mix UTC and local time (**PM-01**);
   - there is no hiring-manager or interviewer persona (**PM-02**);
   - offer governance is thin (**PM-06**);
   - there is no candidate offer self-service or e-signature (**PM-04**);
   - there is no API, outbound events or SSO (**PM-05**).
6. **Counts.**
   - **89 new findings:** 0 Critical, 13 High, 37 Medium, 30 Low, 9 Informational.
   - **19 new decisions** (D8.10-001…019).
   - **201 backlog items reconciled**; nothing closed.

## 2. Phase 8.9 carry-forward items

### 2.A P89-OPS-001: backup, restore and disaster recovery

| Question | Finding |
|---|---|
| Current backup capability | **None (FACT).** No backup package (`composer show --direct`), no backup command in `app/Console` or `routes/console.php`, and no backup service in `docker-compose.yml`. |
| Restore procedure | Documented only (`docs/runbooks/backup-restore.md` §3: stop the writers, restore the database and files from the same point, start on a compatible release, check, record). Never executed. |
| Restore verification | Documented checks only (`/up`, `/health/queue`, offer-letter SHA-256 spot check). No test restore has been done. |
| Database strategy | Documented: `mysqldump --single-transaction` plus an optional binlog for point-in-time recovery. **P810-OP-06:** MySQL 8.4 defaults to `log_bin=ON` with 30-day expiry, unmanaged and unarchived, so the volume grows unaccounted while point-in-time recovery stays unavailable. |
| Uploaded documents | Everything is on the local `storage-data` volume through the `local` / `public` disks (23 hard-coded `Storage::disk('local')` calls). The `s3` disk is configured, but `league/flysystem-aws-s3-v3` is **not installed** (P810-OP-11). Files and database must be backed up at the same point in time. |
| Encryption | The runbook says "encrypt the copies". No mechanism exists. `APP_KEY` must be kept apart from the backups; without it, encrypted columns cannot be read. |
| Retention | Not set. It waits for Legal (R-13, SEC-88-02). |
| Recovery testing | Never done. Cadence is decision D8.9-028. |
| RTO / RPO | **Undefined. Not invented here.** Decisions D8.9-007 / 008. |
| Operational dependencies | A secret store for `APP_KEY` and `.env`; an off-host store; the release image tag (no registry: P810-OP-09); compose volume names. |
| Failure scenarios | Host loss, volume corruption or operator error currently means data loss that cannot be bounded. A database restored without its files leaves broken document links; files without the database leave orphans. A restore onto an older release fails, because migrations are forward-only. |
| Production prerequisites | Owner decisions D8.9-007…010, 028; an implemented backup with a **tested restore**; and this restore capability is also the rollback plan for the first release (P810-OP-02). |

### 2.B P89-OPS-012: security hotfix `2fab3fd`

Full analysis in `phase-8-10-security-review.md` §5. All points below are FACT.

| Question | Finding |
|---|---|
| Exact vulnerability | Phase 8.6 SEC-1 (Critical). On the production line, Filament treats a **missing policy method as allowed**. Delete, ForceDelete, Restore and bulk-delete actions are therefore permitted to anyone who can reach those resources. |
| Affected resources | On `9cba8e3`: Candidate, CandidateApplication, Interview, Offer, CandidateJoining, RecruitmentRequisition, the five master-data models, Employee bulk actions, and ten more resources whose `deleteAny` was missing. |
| What the hotfix does | 25 files, +404 lines; policy methods and tests only (a `ForbidsDeletion` trait and explicit `*Any` rules); no schema or data change. |
| Still required? | **On the production line: yes.** **On this branch: no.** HEAD has the equivalent `dcff76e` plus the stronger `3e51819`: strict authorization and a fail-closed `Gate::before` for any missing policy ability. |
| Safe to merge? | Into this branch: unnecessary and redundant. Into the production line: low-risk by construction (policy methods and tests only, INFERENCE), but its tests must run on that line first. Not merged. |
| `CandidateJoiningPolicy::create()` | **Absent on the hotfix branch and on `9cba8e3`.** The production line has a `CreateCandidateJoining` page, so SEC-86-I-01 is live there and the hotfix does **not** close it. HEAD has `create()`, but it requires only `joining.confirm`, which recruiters hold; this residual is P810-DI-04. |
| Production affected? | Yes. `main` / `production` @ `9cba8e3` lack both fixes (per local refs; not re-fetched). |
| Release dependency | **D8.10-002** (supersedes D8.9-027): hotfix-first (with `create()` added and tested) or the full-branch release. The full release depends on D8.10-003 / 004 and on a backup. |

### 2.C The 287 offer-letter development artefacts

| Question | Finding |
|---|---|
| What exists (FACT) | `storage/app/private/offer-letters/1/` (272 files) and `/3/` (15): 283 PDF and 4 DOCX. By date: 09-27 123, 09-28 37, 10-01 71, 10-02 56. |
| Why they exist | **277** were written before `95b2f58` (2026-10-02 12:17, "tests: fake the local and public disks for every feature test", P89-DQ-016). Feature tests used to write real files for offers 1 and 3. **10** were written afterwards (14:00–18:00 on 10-02) by browser smoke runs, which run real servers against throwaway databases but share the developer's `storage/app` (INFERENCE from the timing). |
| Referenced? | No. The development database has no offer-letter rows pointing at them, and `storage:audit --list` reported all 287 as unreferenced (Phase 8.9 freeze verification). |
| Safe cleanup? | Technically yes on the development host: they are unreferenced and excluded from Docker builds (`.dockerignore`). **Deletion is an owner decision.** Nothing was deleted. |
| Retention policy missing? | Yes. Offer-letter retention is R-9 (P86-BACKLOG-009), deferred with SEC-88-02. There is no orphan cleanup (E-11 is half delivered: report only). |
| Production storage redesign? | Object storage needs a dependency and code changes (P810-OP-11; D8.9-017). Browser smokes should use an isolated storage root (P810-OP-08). |

### 2.D `.ai/rules/general.md`

| Question | Finding |
|---|---|
| Stale? | **Yes (FACT).** Line 13 describes three workers (`queue` = communications,notifications,default; `queue-background` = intelligence,integrations,default). `docker-compose.yml` has four: `queue` (communications,default), `queue-priority` (security,notifications,default), `queue-automation`, and `queue-background` (documents,intelligence,integrations,exports,default). **Also stale:** `.ai/rules/mail.md:9` omits `security`, `documents` and `exports`. |
| Could AI tooling be misled? | **Yes (INFERENCE).** The rule is scoped to `docker-compose.yml` and `composer.json`, so an agent editing the worker topology receives the old layout. It could move notifications back beside candidate messages, or treat `security`, `documents` and `exports` as unconsumed. `QueueTopologyTest` catches a queue with no worker, but not a queue on the wrong worker. The correct topology is in `.ai/rules/notifications-mail.md`. |
| Update in 8.10? | Recommended (P810-DOC-01, workstream H). The documentation audit found the problem is wider than this one file (P810-DOC-01, §13): 31 inaccuracies and 13 contradictions across `.ai/rules`, the runbooks and CLAUDE.md. |
| Runtime effect? | **None in production (FACT).** `.dockerignore` excludes `.ai` and `CLAUDE.md` from the image, which also installs `--no-dev`. The only reader is the development dependency `laravel/boost` (`RuleRepository` over `.ai/rules`), and only in local or debug mode, where it serves the rules to AI agents. In application code, `.ai` appears only in three comments. Updating the rule changes agent guidance, never runtime behaviour. |

## 3. Repository understanding (summary)

- **Size and stack.**
  - 1,173 PHP files in `app/` (about 96k lines): 101 models, 70 policies, 54 Filament resources, 20 pages, 317 service files, 13 jobs, 36 events, 13 listeners.
  - 164 migrations; 239 routes (191 admin, 23 portal, 5 careers, 2 webhooks; no API); 283 test files.
  - Laravel 13 / Filament 5 / Livewire 4 / PHP 8.5 / MySQL 8.4.
- **Architecture pattern.**
  - Lifecycle state is written by domain services: `StageTransitionService`, `OfferService`, `CandidateJoiningService`, `InterviewService`, `RequisitionApprovalService`, `IncentiveApprovalService`, `EmployeeConversionService`.
  - Writes use `RowLock` with the application locked first. History tables are append-only. Events fire after commit.
  - Authorization combines Spatie permissions with a reporting-hierarchy scope (`HierarchyService`), a fail-closed `Gate::before` and Filament strict mode.
  - **Single organisation by design**: no tenant boundary.
- **Subsystems reviewed:**
  - candidate portal and careers site;
  - interviews and self-scheduling with Google / Microsoft calendars and Zoom;
  - offers and letters (DomPDF, PhpWord → LibreOffice);
  - joining and employee conversion;
  - the Outcome Loop and Hiring Memory;
  - EDGE Intelligence: Role DNA, Talent Signal, Hiring Health, Risk Radar, Talent Rediscovery (all deterministic), plus Copilot and RAG (LLM);
  - automation (15 triggers, 8 sweeps, 9 actions);
  - communications (email, Twilio SMS, WhatsApp Cloud);
  - governed metrics (28);
  - identity and access lifecycle;
  - audit;
  - master data;
  - queues (4 workers, 9 queue names, database driver).

## 4. Enterprise maturity assessment (71 areas)

**Ratings:**
- **Strong:** mature and evidenced.
- **Adequate:** works, with known limits.
- **Partial:** important gaps.
- **Weak:** material risk.
- **Absent:** missing.
- **Decision:** blocked on an owner decision.

| # | Area | Rating | Evidence (FACT unless marked) | Refs |
|---|---|---|---|---|
| 1 | Production infrastructure readiness | **Weak** | The image cannot build. Single-host compose. No registry. Production topology unknown. | OP-01, OP-09, D8.9-026 |
| 2 | Backup and disaster recovery | **Absent** | No backup system; runbook only | §2.A |
| 3 | Deployment architecture | Partial | Ordered compose (migrate → app → workers → scheduler) with health checks and `DeploymentTopologyTest`. The drain step does not drain; database health-check race; no `stop_grace_period` on `db`. | OP-03, OP-15, OP-16 |
| 4 | Release safety | **Weak** | 89 migrations never rehearsed as an upgrade. Forward-only, so rollback means a restore, and no backup exists. No CI. | OP-02, OP-08 |
| 5 | Zero / low-downtime deployment | Absent / Decision | One `app` container; `file` maintenance driver | D8.9-023 |
| 6 | Environment configuration | Partial | `env()` used only in config; production checklist exists; `.env.example` ships development defaults | SEC-88-27, OP-14 |
| 7 | Secrets management | Weak | `env_file` gives every service every secret; `DB_PASSWORD` falls back to `secret`; tokens use encrypted casts | OP-13, OP-14 |
| 8 | Queue architecture | Adequate | Four workers with a priority split; contract and topology tests; database driver | OP-04, OP-20, D8.9-018 |
| 9 | Scheduler reliability | Adequate | `withoutOverlapping` / `onOneServer`, heartbeat. Contention at minute :00 (`dispatch-alerts` 139 s at 1M). | §11 |
| 10 | Observability / APM | Weak | Request ids, job log lines, heartbeats. No APM or log shipping; no slow-query hook; deprecations go to `null`. | OP-19, D8.9-019 |
| 11 | Error tracking | Absent | No tracker package | D8.9-019 |
| 12 | Alerting | Partial | In-app platform alerts only. They depend on the scheduler and `queue-priority`. No external channel. | D8.9-020 |
| 13 | Health checks | Adequate | Database-aware `/up`; `/health/queue` behind a token; container health checks. `/up` is not throttled; heartbeat is per queue list. | SEC-007, OP-04 |
| 14 | Database resilience | Weak | Single MySQL, untuned (128 MB buffer pool, 151 connections vs 150 Apache workers). Binlog unmanaged. No replica or backup. | OP-05, OP-06 |
| 15 | Cache / session / queue failure domains | Weak | All in MySQL, a single point of failure. In-transaction side effects depend on this. | D8.9-014, DI-14 |
| 16 | Document / object storage | Partial | Private disk, signed route, `storage:audit`. Local volume only; no S3 adapter; local-path code. | OP-11, D8.9-017 |
| 17 | Search architecture | Partial | Exact routing on candidate screens; `LIKE` scans elsewhere; the command palette bypasses exact routing | OP-12, D8.9-015 |
| 18 | Analytics architecture | Adequate (correctness) / Weak (scale) | Governed and versioned registry with parity. All metrics organisation-wide over 365 days take 490.7 s cold at 1M (benchmark, final code). | D8.9-016 |
| 19 | Reporting scalability | Partial | CSV / PDF reports; 10k export cap; no scheduled delivery | PM-17 |
| 20 | Audit scalability and retention | Partial | Indexed; written append-only by practice. Not immutable at model level; no partitioning or retention; PII in values. | P86-BACKLOG-004, D8.9-024, SEC-88-05 |
| 21 | Data lifecycle / retention | Absent / Decision | SEC-88-02 deferred (C); R-1…R-13 open | PM-03 |
| 22 | Security hardening | Adequate, with gaps | Strict authorization, MFA, private-file controls, log redaction. Two new High findings. | SEC review |
| 23 | Authentication and authorization | Adequate, with gaps | Session epochs, revocation, fail-closed gate. Scoping gaps in pickers; self-approval of incentives. | SEC-004, 006, 008, 009, 010 |
| 24 | Candidate privacy | Partial | Pseudonymised AI, signed files, portal isolation. Cross-team exposure; no data-subject rights. | SEC-004, SEC-006, PM-03 |
| 25 | Employee privacy | Partial | Hierarchy scoping. Directory exposed in pickers; photos on the public disk; PII in audit. | SEC-009, SEC-002 |
| 26 | AI privacy / egress | Adequate | Egress guard, projector, no payload logging. Typed names leak (known); image exfiltration channel. | AI-03, AI-15, P81-BACKLOG-001 |
| 27 | AI governance | Partial | Versioned deterministic rules, fairness filters, review queues. No prompt versioning; scores usable in automation. | AI-04, AI-09, AI-15 |
| 28 | AI action safety | Partial | Pending → approve with re-authorization, expiry and an atomic claim. Approval is uninformed; decision stages are reachable. | AI-01, AI-05 |
| 29 | AI auditability | Partial | Action and usage logs. No model or prompt provenance per proposal; domain audit shows the approver. | AI-09, SEC-88-28 |
| 30 | Candidate experience | Partial | Careers site, portal, self-scheduling. No offer view, withdrawal or reply. Timezone confusion. Stock welcome page at `/`. | PM-01, PM-04, PM-26 |
| 31 | Recruiter experience | Adequate | Pipeline, Action Center, InterviewWorkspace, command palette, Copilot. No digests. | PM-17 |
| 32 | Hiring-manager experience | **Absent** | No role or surface | PM-02 |
| 33 | CHRO / HR leadership experience | Adequate | Dashboards, governed reports, Outcome dashboard, access review. No executive view or scheduled reports. | PM-17 |
| 34 | Candidate portal | Partial | Secure and invite-only. Scope blocked by decisions. | D8.8-005…007 |
| 35 | Internal employee conversion | Adequate | Locked, idempotent, audited. No HRIS hand-off; no reversal. | PM-05 |
| 36 | Requisition lifecycle | Partial | Service-owned status and self-approval block. Edits unaudited; soft delete while in use. | DI-09, PM-08 |
| 37 | Application lifecycle | Adequate, with gaps | Single writer, locks, closure cascade | DI-03, 05, 11, 15 |
| 38 | Interview lifecycle | Partial | Locks, versioned feedback, calendar sync. Scheduling has no lock; one interviewer; bookings never released. | DI-13, PM-10, E-09 |
| 39 | Offer lifecycle | Adequate core / Weak governance | Immutable released terms, revisions, hashed letters. No maker-checker or band check. | PM-06, DI-16 |
| 40 | Joining lifecycle | Partial | Locked transitions. Manual creation bypass; `actual_doj` is the UTC click date. | DI-04, DI-06 |
| 41 | Outcome Loop | Adequate | Versioned outcomes, snapshots, reviewed insights. No post-hire quality inputs. | P82-BACKLOG-001 |
| 42 | Hiring Memory | Adequate | Immutable facts, superseding corrections. OutcomePattern unused. | PM-20, AI-14 |
| 43 | Role DNA | Adequate | Versioned, evidenced, human-confirmed. No taxonomy. | P7-BACKLOG-001 |
| 44 | Talent Signal | Adequate | Deterministic and explainable. No human override; usable in automation. | AI-04, P82-BACKLOG-008 |
| 45 | Hiring Health | Adequate | Deterministic with evidence. Snapshot growth; scan cost. | D8.9-024, P87-BACKLOG-004 |
| 46 | Hiring Risk Radar | Adequate | Deterministic; acknowledge, resolve and dismiss audited | — |
| 47 | Talent Rediscovery | Partial | Deterministic; literal tags; 2,000-candidate scan cap | PM-23 (known), AI-13 |
| 48 | Automation | **Strong** | Versioned rules, separation of duties, dry run, caps, loop guard. Late-funnel triggers missing; single process. | PM-16, P89-BACKLOG-008 |
| 49 | Communications | Adequate (outbound) / Absent (inbound) | Consent, circuit breaker, signed webhooks. No inbound messages; OTP mail retries have no backoff. | PM-15, OP-20 |
| 50 | Integrations | Partial | Real: calendars, Zoom, Twilio, WhatsApp. Job boards are stubs. | PM-07 |
| 51 | API readiness | **Absent** | No `routes/api.php`; token packages forbidden by an architecture test | PM-05, D8.10-015 |
| 52 | Webhook / event architecture | Partial (inbound) / Absent (outbound) | Signed inbound; no outbound; 12 events have no listener | PM-05, SEC-014 |
| 53 | Multi-tenant readiness | **Absent** (by design) | §8 | D8.10-001 |
| 54 | Enterprise configuration | Adequate | Typed, effective-dated settings; stage library; versioned pipeline templates | P86-BACKLOG-005 |
| 55 | Master-data governance | Adequate | Lifecycle service, audit, code immutability. No merge or hierarchy. | P86-BACKLOG-001 |
| 56 | Localization / time zones | **Weak** | App timezone UTC; mixed UTC and local interview times; `actual_doj` and letter dates in UTC | PM-01, DI-06, OP-18 |
| 57 | Accessibility | Partial | Filament defaults. Gaps in custom views. | PM-19 |
| 58 | Internationalization readiness | Weak | INR hard-coded; 10-digit phone numbers with +91; `last_name` required; no `lang/` | PM-18 |
| 59 | Compliance readiness | Weak / Decision | No retention, erasure or stored consent; PII in audit; Legal decisions blocked | PM-03, SEC-88-05 / 14 |
| 60 | Data portability / export | Partial | Governed exporters. No data-subject export. | PM-03, P88-BACKLOG-002 |
| 61 | Vendor / provider resilience | Partial | Communications circuit breaker; job retries. AI has no retry or fallback; embedding errors escape. | AI-07, P83-BACKLOG-009 |
| 62 | Operational runbooks | Adequate (documents) | Backup, incident, production environment, queue operations. The drain step is flawed; the scale line is stale. | OP-03, DOC-03 |
| 63 | Incident management | Partial / Decision | Runbook exists. Severity model and paging undecided. | D8.9-021 / 025 |
| 64 | Disaster recovery | **Absent** | As row 2 | §2.A |
| 65 | Capacity planning | Partial / Decision | Capacity model and benchmarks. No supported scale; untuned database. | D8.9-001, OP-05 |
| 66 | Cost / scaling model | Partial | Projections in the capacity model. AI spend uncapped. | AI-06 |
| 67 | Test architecture | **Strong** (in code) | 2,064 tests, architecture tests, MySQL race harness. The main suite runs on SQLite. | TD-15 |
| 68 | Browser-test reliability | Weak (reproducibility) | 202 / 202 run by hand; smokes not in the repository; they share development storage | OP-08 |
| 69 | CI/CD | **Absent** | No pipeline files | OP-08, D8.10-005 |
| 70 | Developer experience | Partial | Extensive `.ai/rules` and Boost. Stock README; no static analysis. | DOC-01, DOC-06, TD-16 |
| 71 | Documentation accuracy | Partial | Detailed phase documents. Stale and contradictory `.ai/rules`; stale backlog and runbook text; incomplete 8.9 reconciliation. | DOC-01…07 |

## 5. Security deep review

See `phase-8-10-security-review.md`.

**New findings:**

| Severity | Count | Findings |
|---|---|---|
| High | 2 | SEC-001 Host-header poisoning of password links; SEC-004 unscoped candidate picker |
| Medium | 3 | SEC-002 SVG on the public disk without `nosniff`; SEC-006 application-picker label leak; SEC-008 self-approval of incentives |
| Low | 7 | SEC-003, 005, 007, 009, 010, 011, 014 |
| Informational | 2 | SEC-012, 013 |

Total: 14. No Critical.

**Cross-referenced and counted in their own sections:**
- DI-04 (High);
- AI-01 (High);
- AI-02, AI-03, AI-05 (Medium);
- AI-06, AI-11, OP-13, OP-14 (Low).

The carried-forward production Critical is covered in §2.B.

## 6. Data-integrity review

### 6.1 Transition map (FACT)

**Shared guarantees:**
- Every lifecycle model uses `GuardsLifecycleAttributes`: guarded attributes can change only inside `LifecycleGuard::allow`. Creation is not guarded.
- All `App\Events` dispatch after commit, which an architecture test enforces.
- Queue `after_commit=false`. In-transaction jobs are safe only because the database queue shares the transaction (DI-14).

| Transition | Authoritative writer | Lock | Audit / history | Bypass found |
|---|---|---|---|---|
| Candidate → Application | Filament create, `ReferralService`, `CareerApplicationService`, rediscovery | career keyed lock | stage history (application not Auditable) | unscoped candidate on create (SEC-004) |
| Stage moves | `StageTransitionService::advance` / `moveToStage` / `transitionTo` | `RowLock` (application) | `candidate_stage_histories`, override audit | manual moves into offer and joining milestones (DI-03) |
| Interview | `InterviewService` | application then interview, **except `schedule()`** | Auditable | DI-13 |
| Offer create / transitions / revisions | `OfferService` | application then offer | `offer_status_histories` (append-only), Auditable with compensation redacted | — |
| Acceptance → joining | `CandidateJoiningService::createForAcceptedOffer`, inside the acceptance transaction | inherited | Auditable | **manual joining create (DI-04)** |
| Joining transitions | `CandidateJoiningService` | application then joining | Auditable | — |
| Joined → incentive | `RecruiterIncentiveCalculator::calculateForJoining` | rule lock, calculation `FOR UPDATE` | approvals trail, `pricing_snapshot` | **manual Calculate (DI-01)**; referral by stage (DI-02) |
| Incentive approve / pay / adjust / reverse | `IncentiveApprovalService` | calculation lock | approvals, payments, adjustments | self-approval (SEC-008); no reconciliation (DI-07) |
| Joined → employee | `EmployeeConversionService` | candidate lock; unique `candidate_id` | `employee_converted` | — |
| Outcome | `OutcomeService` (versioned, deadlock retry) | `FOR UPDATE` on the dedupe key | corrections audited | — |
| Separation | `EmployeeLifecycleService` | locked | Auditable | — |
| Reject / dropout / reactivate | `StageTransitionService` + `ApplicationClosureCascade` | application lock | history + cascade audit | closure allowed after Joined (DI-05) |
| Requisition status | `RequisitionApprovalService` | none (DQ-013) | `statusHistory` (requisition not Auditable) | edit and soft delete through plain `EditRecord` (DI-09) |

### 6.2 Findings

| ID | Sev | Finding (component) | Evidence and failure scenario | Conf. | Status |
|---|---|---|---|---|---|
| **DI-01** | **High** | Manual "Calculate Incentives" prices with no lifecycle preconditions, and one-shot triggers duplicate across months (`ListRecruiterIncentiveCalculations.php:26-60`; `RecruiterIncentiveCalculator.php:83-110`). | FACT, re-verified:<br>• Selection and OfferAccepted rules are priced at `now()` for any visible application, with no Selected or Accepted check.<br>• The Joining rule is priced for a joining in any status, at `expected_doj`.<br>• The unique key includes the period, so a later-month "recalculate" creates a second calculation. | High | NEW |
| **DI-02** | **High** | Referral bonus anchored on the stage, not the joining record (`ReferralService::syncFromApplication:204-221`). | FACT, re-verified: Joined when `current_stage >= Joined`; `joining_date = actual_doj ?? now()`. A manual Joined move with no joining record prices the bonus; reject, reactivate and rejoin can price it again in another period. | High | NEW |
| DI-03 | Medium | Manual moves into offer, joining and post-join milestones are not refused. `LifecycleAuditor` claims Phase 8.3 prevents them. | `advance()` falls to the forward-only `transitionTo()` for pipeline-less applications. System stages are skippable. The Copilot tool accepts any stage. Hire metrics are joining-anchored and not inflated; referral, rediscovery and SLA consumers are stage-based. | High | NEW |
| **DI-04** | **High** | Manual joining creation followed by Mark Joined bypasses the offer chain (`CandidateJoiningForm.php:18-26`; `CandidateJoiningService::markJoined:81-98`). | FACT, re-verified:<br>• A recruiter (`joining.confirm`) creates a joining for any in-scope application, with `offer_id` chosen from every offer.<br>• Mark Joined then moves the application to Joined, prices their own incentive (still subject to approval) and dispatches `CandidateJoined`.<br>• A mis-linked offer flows into the snapshot and into conversion data. | High | NEW (residual of SEC-86-I-01) |
| DI-05 | Medium | Reject or dropout is allowed after Joined; the cascade leaves the hire, incentive and referral contradictory (`setTerminalStatus:258-294`). | The joining stays Joined, the incentive stays live, and the referral is marked Forfeited while its bonus is still Approved or Paid. `lifecycle:audit` has no check for this. | High | NEW |
| DI-06 | Medium | `actual_doj` is always the click time as a UTC date and cannot be corrected (`CandidateJoiningService.php:84`; the only caller passes no date). | Joins marked between 00:00 and 05:30 IST land on the previous day; a late click keeps the late date. Incentive periods and hire metrics shift (INFERENCE). | High | partly known (8.5 discovery note) |
| DI-07 | Medium | Incentive payments and later adjustments are not reconciled (`IncentiveApprovalService::pay:236-270`, `adjust:276-291`, `repriceSiblings:352-363`). | Any positive amount is recorded as Paid. Adjustments are allowed in any state, including Reversed. Top-ups to a Paid calculation cannot be paid. The dashboard "Paid" figure is the sum of effective amounts. | High | NEW |
| DI-08 | Medium | The retention hold is time-only (`releaseMatured:334-355`). | No check for separation, joining status or application closure at maturity. | High (code) | NEW |
| DI-09 | Medium | Requisitions are unaudited, editable after approval, and soft-deletable while in use. | `opening_date` edits silently change `TimeToFill`. A soft delete makes the Hires, TimeToFill and CostPerHire populations disagree, and a null requisition breaks `RecruiterIncentiveCalculator.php:153`. | High | NEW (absorbs SEC review AZ-07) |
| DI-10 | Low | Cost per hire includes Draft costs; amounts can be negative (`CostPerHire.php:64-77`; no `minValue`). | Matches the governed specification. A change needs D8.10-018. | High | NEW |
| DI-11 | Low | A same-stage `transitionTo` writes a duplicate entry and re-fires the event (round ≥ 4 at FinalInterview; a racing `confirm()`). | Resets the SLA clock; feeds automation again. | High | NEW |
| DI-12 | Low | State-machine dead ends: re-offer after Withdrawn or Expired; reactivation after a joining was closed; completing earlier rounds. | Applications stay Active but cannot progress. | High | NEW |
| DI-13 | Low | `InterviewService::schedule` neither locks nor re-checks the application (`:51-96`). | A concurrent reject can commit first, leaving a Scheduled interview on a Rejected application. | Medium | NEW |
| DI-14 | Info | In-transaction side effects depend on the database queue and cache: alerts inside `OfferService::moveTo`; `Cache::add` dedupe; letter files written in the transaction. | Moving to Redis would send alerts for rolled-back changes. | High | NEW |
| DI-15 | Low | `application_date` and other application fields are edited without audit. | Time to hire starts from it. | High | NEW |
| DI-16 | Low | Offer terms are not validated (negative or empty CTC; dates not ordered; inactive master data in revisions; a pending revision survives acceptance). | — | High | NEW |
| DI-17 | Info | Cascading foreign keys on financial and history tables (`recruitment_costs`, `interviews`, `candidate_stage_histories`, `offer_status_histories`, incentive approvals and adjustments). | Reachable only through raw SQL (the UI forbids force delete). | High | KNOWN-adjacent (P86-BACKLOG-001) |

**Verified sound:**
- lock order everywhere (application first);
- offer creation re-checked under lock;
- released terms immutable;
- acceptance atomic;
- the closure cascade idempotent and audited;
- every incentive write locked, with Approved-or-later never re-priced;
- rules and slabs locked once used;
- outcome versioning;
- one hiring snapshot per joining;
- conversion idempotent;
- recruiter activities only through their service;
- hiring facts cannot be deleted;
- automation limited to early stages;
- Word conversion completed under a lock.

**No formula and no historical data was changed or repaired. P83-BACKLOG-002 still needs an approved plan.**

## 7. AI / EDGE Intelligence review

### 7.1 Inventory (FACT)

- **Shared pipeline.** All provider traffic flows Copilot page → `AiOrchestrator` → `ConversationContextBuilder` → `AiGateway`.
  - The gateway runs `AiEgressGuard` (payload sanitiser, PII pattern scrubber, sensitive-value registry) on generate, stream, structured, embed and research.
  - Providers: Gemini (default), OpenAI, or a null provider.
  - Every call writes an `ai_usage_logs` row, without payloads.
- **Tools.** 49 registered tools.
  - `AiProjector` emits reference codes, never names, contacts or pay.
  - `AiReferenceResolver` turns codes back into names at render time, only for records the viewer may see.
- **Deterministic capabilities** (no LLM in the decision):
  - Role DNA (`RoleDnaBuilder` v1; AI suggestions stay unconfirmed until a person confirms them);
  - Talent Signal (`talent-signal/1`);
  - Hiring Health (`hiring-health/1`);
  - Risk Radar (`risk-radar/1`);
  - Talent Rediscovery (`rediscovery/1`);
  - Hiring Memory facts;
  - Outcome Loop (`hiring-snapshot/3`).
- **LLM capabilities:**
  - Copilot answers and tool selection;
  - RAG embeddings (brute-force cosine);
  - optional narratives (memory, insights, dashboard), screened by `FAIRNESS_PATTERN` / `CAUSAL_PATTERN`, except Hiring Memory (AI-14).

### 7.2 Verdict on "AI recommends, humans decide"

**It holds for model output (FACT).**
- All seven write, external and high-impact tools are created as Pending.
- `ActionExecutor::approve` re-checks, at approval time:
  - the permission, the feature flag and the rate limit;
  - that the approver is the requester;
  - the 30-minute expiry;
  - the authority fingerprint;
  - an atomic Pending→Approved claim.
- Each tool re-scopes its targets to the executing user. Domain services then enforce lifecycle rules under lock.
- **No AI path reaches** offers, joining, compensation, conversion, incentives or requisition approval (grep: no references from `app/Services/AI`, Intelligence or AI jobs).
- Automation never rejects and never targets decision stages.

**Qualifications:**
- AI-01: approval is not fully informed.
- AI-05: decision-stage moves can be proposed.
- AI-04: deterministic scores can drive automated holds, messages and moves to Screened.
- The requester approves their own proposal; there is no second approver (P84-BACKLOG-006).

### 7.3 Findings

| ID | Sev | Finding | Evidence | Status |
|---|---|---|---|---|
| **AI-01** | **High** | The approval card hides the action parameters | `AiCopilot::approvalPreview` (`:315-355`) lists only candidates, applications, interviewer or assignee, recipient and subject. The blade never renders `arguments`, so the stage, rejection reason, remarks, schedule and **email body** go unseen. One-click approve, including HighImpact bulk reject of up to 50 (re-verified). | NEW |
| AI-02 | Medium | Indirect prompt injection through candidate-editable fields | `current_designation`, `current_city` and `location` are projected verbatim; the defence is the system-prompt line only; no adversarial tests | NEW (8.8-U1 untracked) |
| AI-03 | Medium | Markdown output renders auto-loading `https://` images | `Str::markdown` keeps `https` images; the panel has no CSP (`img-src`); a possible zero-click exfiltration channel | NEW |
| AI-04 | Medium | Intelligence scores usable as automation conditions for adverse or screening actions | `AutomationFieldRegistry.php:185`; the validator has no restriction; no staleness check | NEW |
| AI-05 | Medium | The stage-move tool accepts any stage | `MoveCandidatesStageTool:57`; `advance()` falls through to `transitionTo()` (re-verified) | NEW (overlaps P83-BACKLOG-007) |
| AI-06 | Low | Unbounded fan-out, cost and duration per turn; synchronous | Cap counts loop iterations, not calls; no turn deadline; no input limit | NEW (budgets KNOWN P83-BACKLOG-009) |
| AI-07 | Low | Provider resilience | No retry, 429 handling or fallback. `embed()` `ConnectionException` escapes `VectorSearch`. `embed` and `research` failures skip usage logging. | KNOWN + NEW detail |
| AI-08 | Low | Knowledge-base indexing retries never fire; articles go stale silently | `DocumentIngestionService` swallows exceptions; `batch_size` unused | NEW |
| AI-09 | Low | No model or prompt provenance on proposals; domain audit records the human only | No provider, model or prompt-version columns on messages or tool calls; no `request_id` on action logs | NEW (audit part KNOWN SEC-88-28) |
| AI-10 | Low | RAG excerpts sent as system-role content | `ConversationContextBuilder.php:44` | NEW |
| AI-11 | Low | `compare_candidates` leaks an out-of-hierarchy application's stage and compensation fit | `CompareCandidatesTool.php:54-61` | NEW |
| AI-13 | Info | Read and Recommend tools write intelligence state, contrary to the `AiRiskLevel` docblock | `rediscover_talent`, `currentVersionFor` | NEW |
| AI-14 | Info | The Hiring Memory AI summary is not screened | `summarizeMemory` | NEW |
| AI-15 | Info | Provider data-handling terms undocumented; preview models are the defaults | `config/ai.php:57-67` | NEW |

AI-12 (silent truncation in bulk tools) is KNOWN as SEC-88-26 and is not counted.

### 7.4 Maturity opportunities (not implemented)

1. Informed-approval UX with a preview-completeness contract test.
2. Defence in depth against prompt injection:
   - quarantine and length-cap untrusted fields;
   - strip images and links from rendered output;
   - add an adversarial suite to `ai:evaluate`.
3. A prompt registry and provenance (version hashes on messages, tool calls and generated intelligence).
4. Turn deadlines, call caps, retries with jitter, a circuit breaker, enforced budgets from `ai_usage_logs.cost`, and a queued or streamed turn.
5. An audited human "disagree" on Talent Signal and Hiring Health, feeding the Outcome Loop; adverse-impact monitoring.
6. An optional second approver for HighImpact and External actions.
7. Knowledge-base governance: per-document access, chunk purge, a person-data scan on upload.
8. Provider governance: contract terms, generally available models only.
9. Grounding checks: cited codes and figures must appear in tool outputs.

## 8. Enterprise / multi-tenant architecture

**CURRENT ARCHITECTURE (FACT).**
- Single organisation by design: `VectorSearch.php:17` says so, and the backlog records multi-tenancy as a non-goal.
- No `tenant_id`, `organization_id` or `company_id` column in any migration or model.
- No tenant global scope; no Filament tenancy.

| Dimension | Current | Future multi-tenant requirement |
|---|---|---|
| Data rows | No tenant column on about 100 models; global uniques (`candidate_code`, `departments.code`, `users.email`, `code_sequences.key`) | Tenant key everywhere, composite uniques, a global scope, and fail-closed checks in `RowLock` (which uses `withoutGlobalScopes`) and in raw queries |
| Roles / permissions | Spatie `teams => false`; global roles | Spatie teams or per-tenant role sets; tenant-aware registrar resets in workers |
| Hierarchy | One `reports_to` tree. `hierarchy.view-all` returns `null`, meaning unrestricted over the whole database (`HierarchyService.php:28`). | "View-all" bounded to the tenant; memo and fingerprint keys include the tenant |
| Configuration | One global `recruitment_settings` set; one business timezone; company name from env | Per-tenant settings, timezone, branding, letter identity |
| Master data | Global | Per-tenant, or shared catalogues with overrides |
| Documents | Paths with no tenant prefix on one local disk | Tenant prefix or bucket; per-tenant retention |
| AI | Global corpus, prompts, usage and key | Per-tenant corpus isolation, cost attribution, possibly per-tenant keys |
| Integrations | Env-level singleton credentials; webhooks with no tenant routing | Per-tenant encrypted credentials; tenant-resolvable webhooks |
| Queues / scheduler | Ids-only payloads with no tenant context; global iteration; global `onOneServer` locks | Tenant context middleware; per-tenant fairness |
| Analytics | Cache key = metric, version, viewer fingerprint, period | Tenant in every key and scan |
| Audit | No tenant column | Tenant column; tenant-scoped UI and exports |
| Identity / portal | Global email uniqueness and global duplicate detection | Per-tenant duplicate detection, to avoid cross-tenant leakage or merges |
| Billing / feature flags | None / env booleans | Billing boundary; per-tenant flags |

**INFERENCE.**
- Shared-database tenancy is a cross-cutting rewrite.
- One instance per customer is the smallest step. It needs deployment automation (OP-01, 08, 09) and configurable branding and timezone.
- **No multi-tenant readiness is claimed.** The model must be decided (D8.10-001) before API and integration work (D8.10-015) hard-codes one.

## 9. Product maturity review

### 9.1 Stage summary

| Stage | Implementation (FACT) | Authority / audit | Automation / AI | Key gaps |
|---|---|---|---|---|
| Requisition | `RecruitmentRequisition`; `RequisitionApprovalService` (7 statuses); intelligence page | `requisitions.create` / `approve`; self-approval blocked; status history; **fields not audited** | status trigger, ageing sweep; JD tools, Hiring Health, Risk Radar | headcount and budget, multi-level approval, reopen, auto-close (PM-08); DI-09 |
| Role definition | `JobPosting` (Auditable); `Designation`; Role DNA versions | `jobs.publish`; `intelligence.role-dna.manage` | AI suggestions confirmed by people | taxonomy, posting approval, JD templates |
| Sourcing | sources, campaigns (UTM), referrals, talent pools, rediscovery; career site and XML feed | per-permission | referral triggers | **job boards are stubs (PM-07)**; no import, agency, merge or nurture (PM-21) |
| Candidate | `Candidate` (Auditable), duplicate detector (never merges), invite-only portal | hierarchy scope; justified duplicate override | projected AI tools | merge, erasure, consent ledger (PM-03); SEC-004 |
| Application | `StageTransitionService` (18 stages, configured pipelines); assignment service; closure cascade | `pipeline.transition` / `override` (audited) | stage triggers; automation up to Screened; AI move and reject (gated) | stage correction, withdrawal (PM-14); DI-03 / 05 |
| Screening | Screened and Shortlisted milestones; Talent Signal (advisory) | — | SLA alerts | questionnaires, knock-outs, assessments, parsing (PM-09) |
| Interview | `InterviewService`, scheduling slots, versioned feedback, calendars, Zoom | `interviews.manage` | 6 triggers, reminders; plan and question tools | one interviewer, no duration (PM-10); timezone (PM-01); bookings (E-09); feedback-pending counter always 0 (PM-26) |
| Selection | a stage plus `selectCandidate` | `pipeline.transition` | selected-without-offer alert | decision record and sign-off (PM-12) |
| Offer | `OfferService`, revisions, versioned templates, hashed letters, queued conversion | `offers.manage` / `release`; append-only history | released / accepted triggers | **maker-checker, band check, approval chain (PM-06)**; DI-16 |
| Acceptance | recorded by staff; joining created atomically | `offers.manage` | `offer.accepted` | candidate self-service, e-signature, evidence (PM-04) |
| Joining | `CandidateJoiningService` | `joining.confirm` | reminders, risk alerts | DI-04, DI-06; background verification; pre-boarding |
| Employee conversion | `EmployeeConversionService` | `employees.convert` | — | HRIS hand-off, reversal, onboarding |
| Outcome | `OutcomeService`, snapshots, insights | `outcomes.*` | narratives (screened) | quality-of-hire inputs (P82-BACKLOG-001) |
| Hiring Memory | immutable facts | `intelligence.memory.*` | summaries | OutcomePattern unused (PM-20) |
| Next hire | Role DNA history, Talent Signal context, rediscovery, NextBestAction | — | — | source-pattern feedback; silver-medallist pooling |

### 9.2 Personas (FACT, with INFERENCE where noted)

- **Candidate.**
  - Has: careers site, invite-only portal, self-scheduling.
  - Missing: offer view, accept or decline; withdrawal; replies; data download.
  - Shown interview times without a timezone (PM-01).
  - `/` is the stock Laravel page (PM-26).
- **Recruiter.**
  - Has: Pipeline, Action Center, InterviewWorkspace, command palette, Copilot.
  - Alerts are in-app only (PM-17).
- **Hiring manager / interviewer.**
  - No role exists (seeded roles: chro, vp_hr, manager, assistant_manager, recruiter, employee; re-verified).
  - Application visibility follows the recruiter hierarchy, not requisition ownership.
  - INFERENCE: interviewers outside HR cannot reach feedback (PM-02).
- **CHRO.**
  - Has: governed dashboards and reports, Outcome and Intelligence dashboards, access review.
  - Missing: an executive view, scheduled reports, diversity analytics (by design), a metric catalogue page (P85-BACKLOG-010).

### 9.3 Findings (severity = enterprise-maturity impact)

| ID | Sev | Finding | Evidence |
|---|---|---|---|
| **PM-01** | **High** | Interview times mix UTC wall-clock and true UTC; candidate-facing times carry no zone | `config/app.php:82` `UTC`. Manual entry is taken as UTC wall-clock (no picker timezone). Slots are converted to true UTC (`InterviewSchedulingService.php:84`). `TemplateRenderer.php:133-134` and `portal/applications/show.blade.php:22` print `scheduled_at` raw; calendar and Zoom send UTC. All re-verified. INFERENCE: wrong times are shown or synced (e.g. a 10:00 IST slot shown as 04:30). Broadens DQ-88-14 / D8.8-038. |
| **PM-02** | **High** | No hiring-manager or interviewer participation model | Roles re-verified; application scope by recruiter hierarchy; interview UI needs `interviews.manage` |
| **PM-04** | **High** | No candidate offer self-service or e-signature | No offer routes in `routes/portal.php` (0 references); staff record acceptance; no e-signature integration (0 references) |
| **PM-05** | **High** | No API, no outbound events, no SSO | No `routes/api.php`; no token package (forbidden by `IdentityArchitectureTest`, P84-BACKLOG-001); no SAML, OIDC or SCIM (the only `openid` is the Google OAuth scope) |
| **PM-06** | **High** | Thin offer governance | Release checks only `offers.release` (`OfferService.php:156`); no creator ≠ releaser check; no salary-band comparison (re-verified); compensation section ungated (SEC-005) |
| PM-07 | Medium | External job boards are stubs | 5 connectors extend `UnavailableJobBoardConnector` |
| PM-08 | Medium | Requisition governance: no headcount, budget or position control; single-step approval; no reopen; no auto-close | model and migrations |
| PM-09 | Medium | No screening or assessment capability | `StageType::Assessment` unused; no question or assessment models |
| PM-10 | Medium | Interview model limits: one interviewer, no duration, 4 fixed criteria | `interviews` schema; `InterviewFeedback.php:41-48` |
| PM-12 | Medium | No selection decision record | stage only |
| PM-14 | Medium | Lifecycle recovery: `StageCorrected` never written; reactivation restores nothing; no conversion reversal; letter re-issue only through a revision | `StageTransitionService.php:239-256` |
| PM-15 | Medium | One-way communications; no email delivery tracking; no quiet hours | `CommunicationService.php:130` writes outbound only |
| PM-16 | Medium | Automation does not cover offer expired, withdrawn or revised, CandidateJoined, conversion, outcomes, or pending-approval ageing | `AutomationEventRegistry.php:92-116` |
| PM-17 | Medium | Staff alerts are in-app only; no scheduled or emailed reports | `StaffDatabaseNotification`; no report command |
| PM-18 | Medium | Internationalization: INR hard-coded, last-10-digit phones with +91, `last_name` required, no translation layer | 21 `money('INR')`; `CandidateIdentityNormalizer.php:17-26` |
| PM-19 | Medium | Accessibility gaps in custom views | 10 unlabelled controls, no skip link, tables without `scope`, colour-only status |
| PM-20 | Medium | Post-hire quality data missing; OutcomePattern memory unused | `OutcomeType::UNAVAILABLE`; `RoleDnaBuilder.php:165` |
| PM-21 | Medium | Sourcing breadth: no import, agency or vendor model, merge or nurture | grep |
| PM-26 | Low | Polish | Stock welcome page at `/`; dead AI communication code (P87-BACKLOG-007); Zoom `cancelMeeting` never called; InterviewWorkspace feedback-pending counter always 0; delete buttons rendered although denied |

**Not counted:**
- PM-03 (data-subject rights) is KNOWN: SEC-88-02 / 14.
- PM-11 (bookings never released) is KNOWN: E-09.
- PM-13 (returning applicants held) is a consequence of the approved D8.8-036.
- PM-22 (single tenant) is §8.
- PM-23 (literal matching) is KNOWN: P7-BACKLOG-001.
- PM-24 (= DI-10).
- PM-25 (= AI-13).

## 10. Technical-debt register

| ID | P | Item (finding) | Component | Risk / impact | Phase | Dependency | Before production |
|---|---|---|---|---|---|---|---|
| TD-01 | **P0** | Image PHP 8.3 vs lock ≥ 8.4.1 (OP-01) | `Dockerfile:3`, `composer.json` | cannot build or boot | 8.10 | D8.10-004 | **yes** |
| TD-02 | **P0** | Unrehearsed release of Phases 4–8.9 (OP-02) | release | unknown downtime; no rollback | 8.10 | TD-03, D8.10-003 | **yes** |
| TD-03 | **P0** | No backup or restore (P89-OPS-001) | infrastructure | data loss cannot be bounded | Ops | D8.9-007…010 | **yes** |
| TD-04 | **P0** | Production delete-authorization gap (P89-OPS-012) | `main` / `production` | Critical authorization gap | Security / Ops | D8.10-002 | **yes** |
| TD-05 | **P0** | Unscoped candidate picker (SEC-004); Host-header links (SEC-001) | forms, URL root | cross-team data exposure; account takeover | 8.10 | — (SEC-001 needs the production host name) | **yes** |
| TD-06 | **P0** | Incentive and joining integrity (DI-01, 02, 04) | incentive, referral, joining | payment for non-hires or duplicates | 8.10 | D8.10-009 / 011 | **yes** |
| TD-07 | P1 | Drain does not drain; no expand/contract rule (OP-03) | runbook, compose | old code on a new schema | 8.10 | — | yes |
| TD-08 | P1 | Heartbeat per process (OP-04) | `WorkerHeartbeat` | false healthy or unhealthy | 8.10 | — | before scaling |
| TD-09 | P1 | MySQL, Apache and container limits; binlog; log rotation (OP-05 / 06 / 07 / 16) | compose, checklist | connection exhaustion, OOM, disk fill | 8.10 + Ops | D8.9-003 | yes |
| TD-10 | P1 | CI, registry, pinned images (OP-08 / 09) | missing | silent regressions | 8.10 | D8.10-005 | yes (minimum) |
| TD-11 | P1 | Secrets fail-open default and breadth (OP-14) | compose | weak credentials | 8.10 | — | yes |
| TD-12 | P1 | AI approval transparency (AI-01) | Copilot | uninformed approvals | 8.10 | D8.10-012 | yes |
| TD-13 | P1 | Interview timezone semantics (PM-01, DI-06, OP-18) | templates, portal, calendar | wrong times communicated | 8.10 | D8.10-006 | yes |
| TD-14 | P1 | Lifecycle milestone ownership (DI-03, 05, AI-05) | `StageTransitionService` | contradictory hire facts | 8.10 | D8.10-010 | recommended |
| TD-15 | P2 | Main suite on SQLite vs production on MySQL | `phpunit.xml` | MySQL-only defects | 8.10 | CI with MySQL | no |
| TD-16 | P2 | No static analysis | tooling | type and dead-code drift | 8.10 | D8.10-005 | no |
| TD-17 | P2 | Large classes (`RecruitmentAnalyticsService` 1,036 lines; `AutomationEngine` 735; `DispatchRecruitmentAlerts` 585) | services | change risk | opportunistic | — | no |
| TD-18 | P2 | Browser smokes not versioned; they share development storage | repository | evidence not reproducible | 8.10 | D8.10-005 | no |
| TD-19 | P2 | Object-storage abstraction (OP-11) | 23 call sites | blocks S3 and multiple hosts | 8.11+ | D8.9-017 | no |
| TD-20 | P2 | Palette exact routing (OP-12); stateless webhooks and health (OP-10) | `CommandPalette`, `routes/web.php` | scans; session writes | 8.10 / 8.11 | — | no |
| TD-21 | P2 | MySQL as cache, session and queue store (D8.9-014) | config | single point of failure | later | owner | no |
| TD-22 | P3 | Dead AI communication layer (P87-BACKLOG-007) | `app/Services/AI/Communication` | confusion | 8.10 | — | no |
| TD-23 | P2 | Stale and contradictory rules, runbooks, README, composer metadata (DOC-01, 06, 07) | `.ai/rules`, docs | agents and staff misled | 8.10 | — | no |
| TD-24 | P3 | Queue names kept in 4 places (compose, config, `QueueHealthService`, runbook) | config | drift (test-guarded) | any | — | no |
| TD-25 | P3 | `APP_KEY` rotation side effects (OP-13); offer-letter company name and date (OP-18) | portal services, renderer | mass sign-out; wrong letter content | 8.10 | Product confirms OP-18 | confirm |
| TD-26 | P3 | Two stage models (P83-BACKLOG-007); Pint finding in `MetricCachingTest` | — | maintainability | later / 8.10 | — | no |

There are no TODO, FIXME or HACK markers in `app`, `config`, `routes`, `database`, views or tests (FACT).

## 11. Performance and scaling (Phase 8.9 not repeated)

### 11.1 Remaining limits

Figures are Phase 8.9 BENCHMARK unless marked PROJECTED (128 MB buffer pool).

1. **Organisation-wide governed metrics:** 490.7 s cold at 1M on the final code; `time_in_stage` memory grows linearly. Needs **materialization** (D8.9-016).
2. **Dashboard deferred bundle:** `positionHealth` takes 8.5 s at 1M. Needs **caching or materialization**.
3. **Action Center, manager scope:** 3.8 s at 1M. No longer tracked by any backlog item. Needs an **index or rewrite**.
4. **Substring search:** 2.1 s at 1M for CHRO, plus the palette fan-out (OP-12). Needs **search infrastructure** (D8.9-015).
5. **Queue throughput:** one process per group; a 100k burst takes 6.5–29 h (PROJECTED, provider-bound). Automation is limited to one process (unlocked limit check). Needs **separate workers and replicas, and the Redis decision** (D8.9-014 / 018).
6. **Scheduler minute :00:** `dispatch-alerts` (139 s at 1M) delays the sweeps that share the minute. **Background it.**
7. **Intelligence refresh:** capped by a 2,700 s budget. Needs **incremental refresh**.
8. **Unbounded tables:** Hiring Health (about 24 GB a year per 1,000 open requisitions, PROJECTED), audit, notifications, timeline, AI logs. Need **partitioning or archival**, after the retention decision.
9. **MySQL write amplification:** sessions on every request, including webhooks and health checks (OP-10), cache, locks, heartbeats, binlog.
10. **Web tier:** one container. Synchronous Copilot turns hold workers (AI-06). Needs a **load balancer, trusted proxies, shared storage and a cache maintenance driver**.
11. **Files:** about 1 TB at 1M candidates (PROJECTED). Needs **object storage** (OP-11).
12. **RAG:** a full scan per query (P89-PERF-018). Needs **indexed vectors**.

### 11.2 Proposed targeted benchmarks

Each answers a specific open question. None is approved. All would run on throwaway databases only.

| ID | Benchmark | Question it answers |
|---|---|---|
| B1 | Re-run the 1M all-metrics and `positionHealth` runs with `innodb_buffer_pool_size` sized to the working set, against 128 MB | Is materialization required at the owner's chosen tier, or would provisioned MySQL plus a scheduled warm-up be enough? |
| B2 | Exact email and mobile lookup at 500k and 1M, with rows written through the application normalizer | Does the exact path find records at scale? The 8.9 runs matched 0 rows, and the cause was never established. |
| B3 | Database-queue throughput with 1, 2 and 4 replicas per group, fake providers, concurrent session and cache load | Where do `jobs` (SKIP LOCKED) and the primary saturate? Is Redis required for the D8.9-005 targets? |
| B4 | Upgrade rehearsal 75 → 164 migrations on a restored production copy (timing, not load) | What downtime window and lock times does OP-02 need? |
| B5 | Concurrency envelope: staff sessions with dashboard bundles, 30 s polling and palette search | Do prefork, `max_connections` and memory hold at the D8.9-003 target? |

## 12. Operational readiness

The three kinds of readiness are kept separate.

| Area | CODE readiness | INFRASTRUCTURE readiness | PRODUCTION RELEASE readiness |
|---|---|---|---|
| Build / runtime image | Partial | **Not ready** (OP-01) | **Not ready** |
| Deployment orchestration | Ready (topology test) | Unknown (never started; production topology unknown) | **Not ready** (OP-02, OP-03) |
| Rollback | Partial (image tags; forward-only migrations) | Not ready (no registry, no backup) | **Not ready** |
| Migration safety | Ready for Phase 8.9's 4 migrations (fresh, rollback, re-migrate verified) | — | **Not ready** for the 89-migration upgrade (unrehearsed) |
| Worker and scheduler deployment | Ready (contracts, uniqueness, `retry_after` 330 > timeouts) | Partial (one process per group) | Partial |
| Queue draining | Documented | Not effective as written (OP-03) | Not ready |
| Health / readiness / liveness | Ready (`/up`, `/health/queue`, container health checks) | Partial (heartbeat semantics, OP-04) | Partial |
| Observability / error tracking / uptime | Partial | Not ready (no APM, no external monitor) | Not ready (D8.9-019 / 020) |
| Alerting | Partial (in-app only) | Not ready | Not ready |
| Backup / restore / DR | Runbook only | **Not ready** | **Not ready** (P89-OPS-001) |
| Incident response | Runbook | Severity model undecided | Partial |
| Log retention / rotation | Daily files, retention 0 (keep everything); redaction | Container stdout unbounded (OP-07) | Partial (R-13) |
| Capacity, disk, database and queue monitoring | `storage:audit`, queue health | No table-size, disk or database monitoring | Not ready |
| Failed jobs | Pruned after 720 h; retry from QueueHealth | — | Ready |
| Provider and integration failures | Communications circuit breaker; job retries | AI: no retry or fallback (AI-07) | Partial |
| Security posture | 2 new High findings open | Proxy and host configuration unknown | **Not ready** (SEC-001, SEC-004, production hotfix) |

**Platform findings (P810-OP, full detail in the platform review summary).**

| ID | Sev | Finding |
|---|---|---|
| **OP-01** | **High** | Image cannot build or boot. `Dockerfile` PHP 8.3; lock needs ≥ 8.4.1 (`symfony/console` 8.1.5); `platform_check.php` enforces 80401; `Pdo\Mysql` needs 8.4. Re-verified. |
| **OP-02** | **High** | First release = Phases 4–8.9 together: 154 commits, 941 application files, 75 → 164 migrations (6 backfills, 2 unique drops), never rehearsed as an upgrade. Re-verified. |
| OP-03 | Medium | The drain step does not drain (workers restart on the old image; scheduler never stopped; `file` maintenance driver) |
| OP-04 | Medium | Heartbeat keyed by queue list, not process, and written only while looping: hung replicas masked; long jobs flagged |
| OP-05 | Medium | Untuned capacity envelope: 150 Apache workers vs 151 MySQL connections; no container memory limits |
| OP-06 | Medium | MySQL binlog on by default, unmanaged |
| OP-07 | Medium | Container stdout logs unbounded |
| OP-08 | Medium | No CI/CD; browser and concurrency evidence not reproducible from the repository |
| OP-09 | Medium | Rollback depends on locally built images; floating base tags |
| OP-11 | Medium | Object storage needs a dependency plus local-path code changes |
| OP-12 | Medium | Command palette search bypasses exact routing (Applications and Offers `LIKE` via `whereHas`) |
| OP-20 | Medium | Queued security mailables (`CandidatePortalLink`, `CandidateStepUpCode`) and `AiCopilotEmail` have no `tries` / `backoff`, so 3 immediate retries during an SMTP outage; `QueueContractTest` does not scan `app/Mail` (re-verified) |
| OP-10 | Low | Webhook and health routes create sessions |
| OP-13 | Low | `APP_KEY` rotation silently ends portal sessions and links (HMACs use the current key only) |
| OP-14 | Low | `DB_PASSWORD` falls back to `secret`; every service holds every secret |
| OP-15 | Low | `db` health check can pass on the init server before the user exists |
| OP-16 | Low | No `stop_grace_period` on `db` |
| OP-18 | Low | Offer-letter `company_name` from `app.name`; `today` in UTC |
| OP-19 | Low | No slow-query hook; deprecations go to `null`; `storage:audit` not scheduled |

## 13. Backlog reconciliation

See `phase-8-10-backlog-reconciliation.md`. 201 items:

| Category | Count |
|---|---|
| Closed and documented (re-verified) | 15 |
| 1 Still open | 49 |
| 2 Completed but undocumented (partial) | 6 |
| 3 Deferred | 38 |
| 4 Accepted risk | 24 |
| 5 Duplicate | 27 |
| 6 Superseded | 4 |
| 7 Unknown / requires decision | 38 |

**Nothing was closed.**

**Notable (FACT):**
- **Phase 8.9's "backlog reconciled" (P89-DQ-018) is incomplete.** Eight items that `phase-8-9-discovery.md` §10.2 says were given a home are absent from `backlog.md` (P810-DOC-02).
- Several backlog statuses are stale (P810-DOC-04).
- DQ-88-06 (resume) is a false positive.

**Documentation findings:**

| ID | Sev | Finding |
|---|---|---|
| DOC-01 | Medium | `.ai/rules` contains stale and contradictory guidance: 31 inaccuracies and 13 contradictions found by the documentation audit. Spot-verified:<br>• `general.md` and `mail.md`: stale queue topology;<br>• `app-services-services.md:9` prescribes `lockForUpdate` + refresh, against the code's `RowLock` and `app-services.md:35`;<br>• `concerns-models.md` says not to make Offer Auditable, but `Offer` uses `Auditable`;<br>• `pdf.md:9` names a method, `OfferLetterRenderer::pdfViewFor`, that does not exist;<br>• CLAUDE.md:75 points to `.ai/rules/boost`, which does not exist.<br>Agents follow these rules, so they can be steered into wrong code. No runtime effect. |
| DOC-02 | Low | Incomplete Phase 8.9 backlog reconciliation (P89-DQ-018 recorded as Done) |
| DOC-03 | — | Phase 8.7 scale line in `queue-operations.md:37` (KNOWN, freeze §14; not counted) |
| DOC-04 | Low | Stale `backlog.md` statuses: TD-001, P83-BACKLOG-003, the access bullet, the P89-BACKLOG-017 count, P88-BACKLOG-003 / 005; DQ-88-06 false positive |
| DOC-05 | Info | Decision-register inconsistencies: 8.6 and 8.7 records say PROPOSED; D8.8-003 / 035 statuses; the "D8.9-001…028" range. Historical deploy procedures still say `optimize:clear`. Policy docblocks wrongly claim per-record bulk re-checks. |
| DOC-06 | Info | `README.md` is the stock Laravel README; `composer.json` is still named `laravel/laravel` with `php: ^8.3` |
| DOC-07 | Low | Runbook inaccuracies:<br>• `queue-operations.md:20` says the 8.9 migrations only add indexes (one creates a table);<br>• `QueueTopologyTest` is described as catching any new queue, but it does not inspect exporter queues;<br>• runbook `docker compose exec app php artisan …` runs as root and can create root-owned logs;<br>• the backup volume name assumes a project name that compose does not set (hedged in the text). |

## 14. Decision register

See `phase-8-10-decision-register.md`.

**19 new decisions:**

| ID | Decision |
|---|---|
| D8.10-001 | Tenancy model |
| D8.10-002 | Production remediation path (supersedes D8.9-027) |
| D8.10-003 | First-release strategy |
| D8.10-004 | PHP runtime |
| D8.10-005 | CI and tooling dependencies |
| D8.10-006 | Timezone semantics |
| D8.10-007 | Hiring-manager / interviewer model |
| D8.10-008 | Offer governance |
| D8.10-009 | Incentive integrity rules |
| D8.10-010 | Milestone ownership |
| D8.10-011 | Manual joining creation |
| D8.10-012 | AI approval UX and separation of duties |
| D8.10-013 | Scores in automation |
| D8.10-014 | AI provider governance |
| D8.10-015 | API, events, SSO |
| D8.10-016 | Candidate offer self-service and e-signature |
| D8.10-017 | Requisition governance |
| D8.10-018 | Cost-per-hire population |
| D8.10-019 | Localization, i18n, accessibility targets |

**Blocking production:** D8.9-007…010, 028 (backup and DR), D8.9-026 (production facts), D8.10-002, 003, 004.

**Engineering gives technical recommendations only** (D8.10-002 to 005, 006 storage rule, 010, 011, 012, 014 models, 017 audit and deletion guard). No business decision is pre-empted.

## 15. Phase 8.10 scope proposal

**Principle.** Make the frozen code releasable and close the integrity and security gaps that would carry into production. Product expansion stays decision-gated.

Each item lists the problem (with evidence), solution, dependencies, risk, benefit, test strategy, migration impact and production impact.

### A. Mandatory before production

| ID | Problem (evidence) | Proposed solution | Dependencies | Risk | Benefit | Test strategy | Migration | Production impact |
|---|---|---|---|---|---|---|---|---|
| 810-A1 | Image cannot build (OP-01) | Align `Dockerfile` PHP, `composer.json`, platform config; fix extension builds | D8.10-004 | Low | A buildable, bootable artefact | `docker build` and boot in CI; full suite on the chosen PHP | none | enables any release |
| 810-A2 | Unrehearsed 89-migration release (OP-02) | Upgrade rehearsal on a restored production copy (B4); timed runbook; restore-based rollback plan | D8.10-003, A3, production copy access | Medium | Known downtime window; safe rollback | Rehearsal with data verification; `lifecycle:audit`, `storage:audit` and metric parity before and after | none new | defines the release window |
| 810-A3 | No backup or restore (P89-OPS-001) | Engineering support for the Operations-chosen backup: consistent DB + file snapshot procedure, verification checklist, a tested restore into a throwaway environment | D8.9-007…010, 028 | Low | Bounded data loss; rollback capability | Restore test with documented checks | none | prerequisite |
| 810-A4 | Production delete-authorization gap (P89-OPS-012) | If D8.10-002 chooses hotfix-first: complete `2fab3fd` with `CandidateJoiningPolicy::create()` and tests on the production line | D8.10-002 | Low | Closes a Critical gap soonest | Hotfix tests on `9cba8e3` + `DeleteAuthorizationTest` | none | a small release on the production line |
| 810-A5 | Host-header poisoning (SEC-001) | Trusted hosts and fixed URL root for mailed links; vhost `ServerName` | production host name (D8.9-026) | Low | Closes account-takeover vector | Regression tests with a foreign `Host` for portal and staff reset | none | config + code |
| 810-A6 | Deploy procedure and runtime limits (OP-03, 05, 06, 07, 14, 15, 16) | Stop writers or use a cache maintenance driver before `migrate`; expand/contract rule; size MySQL, Apache and memory limits; binlog policy; log rotation; `${DB_PASSWORD:?}`; health-check and grace fixes | D8.9-003 / 008 / 009 (sizing, binlog) | Low–Medium | Predictable deploys; no silent exhaustion | `DeploymentTopologyTest` extensions; a compose smoke in CI | none | compose / runbook |
| 810-A7 | No CI (OP-08) | CI: composer platform check, Pint, parallel Pest, MySQL migrate-fresh + concurrency suite, `docker build` | D8.10-005 | Low | Reproducible evidence | Pipeline itself | none | none |

### B. Enterprise reliability

| ID | Problem | Solution | Dependencies | Risk | Benefit | Tests | Migration | Production |
|---|---|---|---|---|---|---|---|---|
| 810-B1 | Heartbeat semantics (OP-04) | Per-process key; beat on job start and finish; `--max-time` above the timeout | — | Low | Correct health signals | Unit tests on heartbeat aggregation | none | worker restart |
| 810-B2 | Mailable retries (OP-20) | `tries` / `backoff` on queued mailables; extend `QueueContractTest` to `app/Mail` and `app/Notifications` | — | Low | OTP and reset delivery survives short outages | Contract test | none | none |
| 810-B3 | In-transaction side effects (DI-14) | `DB::afterCommit` around alerts; letter file written after commit or cleaned on rollback | — | Low | Safe for a future Redis move | Rollback tests | none | none |
| 810-B4 | Stateless machine routes (OP-10) | Webhooks and `health/queue` outside the session middleware | — | Low | Fewer session writes | Route middleware tests | none | none |
| 810-B5 | AI provider resilience (AI-07, AI-08) | Map transport errors to unavailable; usage logged in `finally`; rethrow indexing failures while retries remain; batch embeddings | — | Low | No failed turns on timeouts; working retries | Fake-provider tests | none | none |
| 810-B6 | Observability specifics (OP-19) | Slow-query hook; deprecation channel; scheduled `storage:audit` report | tooling choice D8.9-019 for shipping | Low | Earlier detection | Config tests | none | logging |

### C. Security

| ID | Problem | Solution | Dependencies | Risk | Benefit | Tests | Migration | Production |
|---|---|---|---|---|---|---|---|---|
| 810-C1 | Unscoped candidate picker (SEC-004) | Scope the picker; server re-check of candidate and recruiter on create; record who created the application (`created_by` or an audit row, subject to the `concerns-models.md` rule review in 810-D5) | — | Low | Closes cross-team exposure | Recruiter-role tests (list, create, tamper) | possibly `created_by` (additive) | — |
| 810-C2 | Picker label leak (SEC-006) | Scoped label resolution; audit all `getOptionLabelUsing` closures | — | Low | Closes enumeration | Livewire tests calling `getOptionLabel` | none | — |
| 810-C3 | Incentive self-approval (SEC-008) | Beneficiary ≠ actor in policy and service; bounded adjustments | D8.10-009 (bounds) | Low | Separation of duties | Policy and service tests | none | — |
| 810-C4 | Public-disk uploads (SEC-002) | Raster-only types; `nosniff` / CSP on `/storage` or controller-served photos | — | Low | Closes stored-XSS vector | Upload validation tests; header tests | none | vhost / headers |
| 810-C5 | Scope and role gaps (SEC-009, SEC-010, SEC-012) | `recruitersFor()` pickers; holder-only rule in revoke, suspend and separation; explicit transition rule on interview completion | SEC-012 needs a product rule | Low | Consistent authority | Role tests | none | — |
| 810-C6 | Small hardening (SEC-003, 005, 007, 011, 013, 014; SEC-88-22, 25 remainder, 26) | Compensation gate on offer form; Auditable `CandidateDocument`; `/up` throttle; MFA on calendar OAuth; webhook tolerance window; request-id handling; import row cap, formulas off, MIME check; truncation flag on AI bulk tools; candidate password rule (if approved) | D8.8-002 / 022 for policy choices | Low | Closes residuals | Per-item tests | none (Auditable uses existing `audit_logs`) | — |

### D. Data integrity

| ID | Problem | Solution | Dependencies | Risk | Benefit | Tests | Migration | Production |
|---|---|---|---|---|---|---|---|---|
| 810-D1 | Incentive eligibility (DI-01, DI-02) | Enforce trigger preconditions and event dates in the calculator and `ReferralService`; one-shot uniqueness | D8.10-009 | Medium (changes eligibility, **not formulas**) | No pricing for non-hires or duplicates | Calculator tests per trigger; MySQL race test | possibly unique index (after a data check) | existing rows reported, **not repaired** without approval |
| 810-D2 | Manual joining bypass (DI-04) | Route creation through `CandidateJoiningService` with an accepted-offer requirement or audited exception; scope `offer_id`; `markJoined` precondition | D8.10-011 | Low | Restores the offer → joining chain | Policy and service tests | none | — |
| 810-D3 | Milestone ownership and post-hire closure (DI-03, DI-05, AI-05) | Reserve offer, joining and post-join stages to services; refuse closure at or after Joined; restrict AI stage tool | D8.10-010 | Low | Coherent hire facts | Transition tests; AI tool tests; `lifecycle:audit` wording | none | anomalies reported only |
| 810-D4 | Payment integrity (DI-07, DI-08) | Validate payment vs locked effective amount; refuse adjustments on terminal states; supplementary payment path; separation check at retention maturity | D8.10-009 | Medium | Reconciled payouts | Service tests | possibly additive fields | — |
| 810-D5 | Requisition governance (DI-09) | Field-level audit of requisitions; refuse deletion with dependants; lock or re-approve terms (if decided). **Conflicts with a project rule:** `.ai/rules/concerns-models.md` currently says not to add `Auditable` to RecruitmentRequisition or CandidateApplication, because status histories exist. Those histories do not capture field edits, so the rule must be revisited as part of approval. | D8.10-017 (locking) | Low | Traceable changes; consistent metrics | Audit and deletion tests; metric population tests | none | — |
| 810-D6 | Smaller integrity items (DI-11, 13, 15, 16) | Same-stage no-op; lock in `schedule()`; audit application field edits (same rule conflict as 810-D5); offer term validation | — | Low | Fewer anomalies | MySQL race test for DI-13; unit tests | none | — |
| 810-D7 | `actual_doj` entry (DI-06) | Accept the actual date (bounded) in the business timezone; audited correction | D8.10-006 | Low | Correct periods and metrics | Time-of-day tests (E-12) | none | historical rows not repaired |

### E. AI / intelligence maturity

| ID | Problem | Solution | Dependencies | Risk | Benefit | Tests | Migration | Production |
|---|---|---|---|---|---|---|---|---|
| 810-E1 | Uninformed approvals (AI-01) | Full server-built parameter preview (stage, reason, schedule, rendered email body); High-Impact friction per D8.10-012 | D8.10-012 | Low | Informed human decisions | Preview-completeness contract test over every write tool | none | — |
| 810-E2 | Prompt injection and exfiltration (AI-02, AI-03, AI-10) | Tagged, length-capped untrusted fields; strip images and links from rendered output; panel CSP `img-src 'self'`; RAG in a non-system message | — | Low–Medium (CSP may affect assets) | Defence in depth | Adversarial `ai:evaluate` cases; rendering tests | none | CSP header |
| 810-E3 | Turn bounds (AI-06) | Per-response and per-turn call caps; deadline; input limit | — | Low | Cost and DoS bound | Orchestrator tests | none | — |
| 810-E4 | Provenance (AI-09, SEC-88-28) | Provider, model, prompt hash on messages and tool calls; `request_id` on action logs; AI-proposed actor context | — | Low | Auditability | Schema and audit tests | additive columns | — |
| 810-E5 | Scope and classification fixes (AI-11, AI-13, AI-14) | Scope `compare_candidates`; correct risk-level classification or docblock; screen memory summaries | — | Low | Consistency | Tool tests | none | — |
| 810-E6 | Scores in automation (AI-04) | Validator rules as decided; band staleness check | D8.10-013 | Low | Policy compliance | Validator tests | none | — |

### F. Product maturity (decision-gated)

| ID | Problem | Solution | Dependencies | Risk | Benefit | Tests | Migration | Production |
|---|---|---|---|---|---|---|---|---|
| 810-F1 | Interview time zones (PM-01, OP-18) | Display and entry timezone per D8.10-006; labelled candidate-facing times; calendar payloads with zone | D8.10-006 | Medium (candidate-facing) | Correct times | Renderer, portal and calendar tests; time-of-day tests | none (UTC storage kept) | existing interview rows audited, not repaired |
| 810-F2 | Offer governance (PM-06) | Maker-checker and/or band validation per D8.10-008 | D8.10-008 | Low–Medium | Spend control | Service tests | possibly config table | — |
| 810-F3 | Hiring-manager / interviewer participation (PM-02) | Requisition-scoped role and minimal surface, if decided | D8.10-007 | Medium (new authorization path) | Line-manager participation | Full policy and scope tests; AI projector and metric scope tests | likely (assignments) | — |
| 810-F4 | Late-funnel automation (PM-16) | Map existing events (offer expired, withdrawn, revised; CandidateJoined; conversion) and an approval-ageing sweep | — | Low | Automation coverage | Automation engine tests | none | — |
| 810-F5 | Staff digests and scheduled reports (PM-17) | Email digest of in-app alerts; scheduled report delivery | D8.9-020 for channels | Low | Reach | Notification tests | none | mail volume |
| 810-F6 | Accessibility pass (PM-19) and polish (PM-26) | Labels, skip link, table scope, non-colour status; branded `/`; Zoom cancel and update; counter fix; hide denied delete buttons | D8.10-019 (target) | Low | Usability and compliance | Browser checks; feature tests | none | — |

### G. Infrastructure (owner-led; engineering supports)

- External monitor and alert channel (D8.9-020).
- Secret store; image registry with pinned base images (OP-09).
- MySQL sizing and binlog policy (OP-05 / 06).
- Container log rotation (OP-07).
- Production facts (D8.9-026).
- Load-testing policy (D8.9-011) with benchmarks B1–B5.
- Object storage (D8.9-017).
- Redis (D8.9-014).

### H. Developer / platform architecture

| ID | Item | Notes |
|---|---|---|
| 810-H1 | Version the browser smokes under the repository, with isolated storage (OP-08, TD-18) | Needs dependency approval (D8.10-005) |
| 810-H2 | Static analysis (TD-16) | Needs dependency approval |
| 810-H3 | MySQL job in CI for the main suite (TD-15) | — |
| 810-H4 | Documentation corrections (DOC-01, 02, 04, 05, 06, 07), including the `.ai/rules` contradictions and the CLAUDE.md `.ai/rules/boost` reference, and the approved backlog edits | `phase-8-10-backlog-reconciliation.md` §5. No runtime effect. |
| 810-H5 | Remove dead code (P87-BACKLOG-007); the Pint finding; `PruneExpiredCache` vs ED-08 | — |

### I. Deferred / later phases (recorded, not proposed for 8.10)

| Item | Gated by |
|---|---|
| Tenancy | D8.10-001 |
| API, outbound events, SSO (PM-05) | D8.10-015, after D8.10-001 |
| Candidate offer self-service and e-signature (PM-04) | D8.10-016, D8.8-005…007 |
| Job-board integrations (PM-07) | — |
| Screening and assessment (PM-09) | — |
| Panel interviews and scorecards (PM-10) | — |
| Selection decision record (PM-12) | — |
| Lifecycle recovery tools (PM-14) | — |
| Inbound communications (PM-15) | D8.8-025 / 026 |
| i18n (PM-18) | D8.10-019 |
| Post-hire quality data (PM-20) | HRMS |
| Sourcing breadth (PM-21) | D8.8-013…015 |
| Retention and erasure (PM-03) | Legal, R-1…R-13 |
| Materialization | D8.9-016 |
| Search engine | D8.9-015 |
| Object-storage migration (OP-11) | D8.9-017 |
| Redis | D8.9-014 |
| Partitioning and archival | D8.9-024 |
| FK hardening (DI-17) | D8.6-006 |
| Two stage models (P83-BACKLOG-007) | — |
| Large-class refactors (TD-17) | — |
| Metric catalogue page | 8.12+, tag preserved |

**Proposed workstreams:**
- A: Production release readiness (mandatory)
- B: Enterprise reliability
- C: Security hardening
- D: Data integrity
- E: AI safety & intelligence maturity
- F: Product maturity (decision-gated)
- G: Infrastructure (owner-led)
- H: Developer platform & documentation
- I: Deferred / later phases

## 16. Discovery quality gate

- **Evidence.** Every finding names its component (file, class or method, route, table) with evidence and impact. Every finding carries a confidence and a FACT / INFERENCE label in its source review.
- **Re-verification.** Every **High** finding was re-traced in code by the discovery lead:
  - SEC-001, SEC-004;
  - DI-01, DI-02, DI-04;
  - AI-01;
  - OP-01, OP-02;
  - PM-01, PM-02, PM-04, PM-05, PM-06.
  
  So were AI-05, OP-20, the hotfix facts and the artefact provenance.
- **No exploit was executed.** No assumption is presented as fact. Runtime and deployment consequences are marked INFERENCE.
- **Severity counts** (new findings only; known items listed separately):

| Area | Total | Critical | High | Medium | Low | Info |
|---|---|---|---|---|---|---|
| Security (SEC) | 14 | 0 | 2 | 3 | 7 | 2 |
| Data integrity (DI) | 17 | 0 | 3 | 6 | 6 | 2 |
| AI (AI) | 14 | 0 | 1 | 4 | 6 | 3 |
| Platform / operations (OP) | 19 | 0 | 2 | 10 | 7 | 0 |
| Product maturity (PM) | 19 | 0 | 5 | 13 | 1 | 0 |
| Documentation (DOC) | 6 | 0 | 0 | 1 | 3 | 2 |
| **Total** | **89** | **0** | **13** | **37** | **30** | **9** |

**Limits of this discovery:**
- **No production access.** All production statements are about the repository or are marked unknown.
- **No tests or benchmarks were run**, because the code is unchanged from the frozen baseline.
- **The `.ai/rules` audit** checked every back-ticked path automatically. A content audit by review 7 found 31 inaccuracies and 13 contradictions; six of the most consequential were re-verified by the discovery lead (DOC-01). The rest are taken from that review and should be re-checked when fixed.
- **Some decision-review statements** from the reconciliation review were taken from the documents and not each re-verified in code. They are marked in the decision register where they matter.

## 17. Status

- **Phase 8.10 implementation: NOT STARTED.**
- **Production: NOT DEPLOYED / NOT CHANGED.**
- **Push: NOT DONE.**
- Next step: explicit approval of the Phase 8.10 scope (§15) and the decisions it depends on (§14).
