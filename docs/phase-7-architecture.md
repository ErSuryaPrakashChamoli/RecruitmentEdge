# Phase 7 — EDGE INTELLIGENCE™: Architecture

This builds on the findings in `phase-7-discovery.md`.

The design is organised around one idea: **facts, signals, scores, recommendations and decisions are different things.** Deterministic services produce facts, metrics and signals. AI may suggest and explain, and every AI output is labelled as such. Humans decide. Every value the user sees can be traced to evidence.

---

## 1. Intelligence model

| Layer | Produced by | Example | Stored as |
| --- | --- | --- | --- |
| FACT | Database / configuration | "Requisition requires 3–6 years" | Evidence of type `database_fact` or `configuration` |
| SIGNAL | Deterministic calculator | "4 of 5 required skills matched" | Talent Signal component + evidence |
| SCORE / BAND | Versioned rules over signals | Band `strong` (rules `talent-signal/1`) | Snapshot columns + rule version |
| RECOMMENDATION | Risk Radar / Next Best Action / AI suggestion | "Source more candidates" | Risk, Action Center item, or unconfirmed Role DNA attribute |
| DECISION | Human only | Shortlist, reject, offer, confirm a Role DNA attribute | Existing domain services + audit |

**No layer ever writes to a hiring decision.** Rediscovery can *add a candidate to a requisition* (as a new Sourced application) or to a talent pool, but only when a person clicks it, through the existing services.

## 2. Evidence and provenance: `intelligence_evidence`

This is one reusable, append-only table. Each row explains one value of one intelligence record.

| Column | Meaning |
| --- | --- |
| `owner_type` / `owner_id` | The intelligence record this explains (Role DNA version, signal snapshot, health snapshot, risk, rediscovery result, memory record) |
| `subject_key` | Which component, metric or attribute within the owner (e.g. `skills`, `pipeline_depth`, `skill:laravel`) |
| `evidence_type` | `database_fact`, `calculated_metric`, `configuration`, `recruiter_input`, `interviewer_feedback`, `human_confirmation`, `ai_extraction`, `ai_inference` |
| `label`, `value`, `numeric_value` | Human-readable fact plus an optional number for sorting |
| `source_type` / `source_id` | The record the fact came from (candidate, application, interview, memory record…) |
| `observed_at` | When the underlying fact was true or recorded |
| `generator`, `generator_version` | e.g. `talent-signal`, `1`, or `ai:role-dna-suggestions`, `1` |
| `ai_model` | Set only for AI evidence |
| `confidence` | AI-only, nullable. Deterministic facts have none, because they are facts. |
| `explanation` | Why this matters |
| `verification_status` | `not_required` (deterministic), `unverified`, `verified` or `rejected` (AI and human-reviewable items) |
| `verified_by`, `verified_at` | Who reviewed it and when |

- **Rows are immutable.** Only the verification fields can change, and that change is audited. A correction is recorded as a new evidence row or a new version, never an edit.
- **`EvidenceRecorder` is the only writer.**

## 3. Modules

### 3.1 Role DNA™

- **Tables:** `role_dna_profiles` (one per requisition; current version, status draft/confirmed, AI status) and `role_dna_versions` (immutable JSON attributes, generator, rule version, AI model, change summary, created by).
- **Attribute fields:** `key`, `category`, `label`, `value`, `level`, `origin`.
  - `category`: identity, skill, experience, education, location, work_mode, compensation, responsibility, behavioral, constraint, sourcing, interview_dimension, historical_pattern.
  - `level`: required, preferred, informational.
  - `origin`: configured, inferred, historical, ai_suggestion, human_confirmed.
- **`RoleDnaBuilder` (deterministic)** produces:
  - *configured* attributes from requisition columns;
  - *inferred* attributes: interview dimensions from the rating criteria (excluding culture fit) and the pipeline's interview rounds;
  - *historical* patterns from Hiring Memory for the same designation. A historical pattern needs at least 3 comparable hires; otherwise it is emitted as `insufficient history`. History is never invented.
- **AI suggestions** (queued, optional) add `ai_suggestion` attributes. They are unconfirmed until a human confirms or rejects each one, and every such decision creates a new version.
- **Any change produces a new version.** Talent Signals and rediscovery runs record the version they used, so old analyses stay attributable.

### 3.2 Talent Signal™

- **Table:** `talent_signal_snapshots`, one per candidate × requisition. It records:
  - the Role DNA version used and the rules version;
  - band and summary columns: required-skill coverage, experience fit, completeness, evidence count;
  - a `components` JSON;
  - `is_current`.
- **`TalentSignalCalculator`** is pure, deterministic and shared with rediscovery. Its components:

  | Component | What it measures |
  | --- | --- |
  | `skills` | Required and preferred coverage, listing matched and missing skills |
  | `experience` | Within / below / above range, from stated years |
  | `relevant_experience` | Stated relevant years |
  | `education` | Exact contains-match against the required qualification, otherwise `unknown` |
  | `location` | Exact name match, otherwise `unknown` or `different` (only when both are known) |
  | `compensation` | Expected salary against the range |
  | `notice` | Notice period against the target joining date |
  | `history` | Previous applications and the furthest stage reached (context only) |
  | `interviews` | Past feedback averages, excluding culture fit (context only) |
  | `source` | Source context |
  | `engagement` | Days since last activity |

- **Band (rules `talent-signal/1`)** is decomposed and never opaque:

  | Band | Condition |
  | --- | --- |
  | `insufficient_evidence` | Data completeness below 40% |
  | `strong` | ≥ 80% required skills, experience not below range, no known hard mismatch |
  | `weak` | < 40% required skills, or experience more than 2 years below the minimum |
  | `moderate` | Otherwise |

- **Fairness:** no name, photo, age, gender, marital status, religion, caste or nationality (none exist in the schema, and none may be inferred). The culture-fit rating is excluded. Location is only compared with the configured requirement.
- **Staleness:** a snapshot is stale when the candidate or application changed after it was computed, or when the Role DNA version moved on.

### 3.3 Hiring Health™

- **`RecruitmentAnalyticsService::requisitionMetrics()`** (an extension, not a new engine) returns raw per-requisition numbers:
  - pipeline counts by stage and status;
  - new applications (last 14 days);
  - last shortlisted date;
  - average days from shortlist to interview;
  - pending feedback;
  - offers released / accepted / declined;
  - joining risks;
  - stalled candidates;
  - source distribution;
  - automation failures for this requisition.
- **`HiringHealthService`** applies thresholds (from the existing `RecruitmentSetting` keys, with defaults) and produces metrics, each with `key`, `label`, `value`, `threshold`, `status` (ok / watch / breach / unknown), `unit` and evidence.
- **Overall status:** `critical` if any critical metric breaches; `at_risk` if any breach; `watch` if any watch; `healthy`; or `insufficient_data` when completeness is too low.
- **Snapshots** go into `hiring_health_snapshots`, with the current one flagged. History is kept as a time series.
- **Metrics:**

  | Metric | Uses |
  | --- | --- |
  | Days open | `vacancy_ageing_alert_days`, `position_risk_max_days_open` |
  | Pipeline depth | `position_risk_min_pipeline_ratio` × remaining openings |
  | Sourcing velocity | Applications in the last 14 days |
  | Last qualified profile | Days since the last Shortlisted |
  | Interview velocity | Average days from shortlist to interview |
  | Feedback pending | Count |
  | SLA breaches | `RecruitmentSlaService::breachFor` |
  | Offer conversion | Offers released / accepted / declined |
  | Joining risk | Count of red joinings |
  | Stalled candidates | `candidate_stall_days` |
  | Source concentration | Top source share |
  | Automation failures | Failed automation runs |
  | Data completeness | Of the requisition's own fields |

### 3.4 Hiring Risk Radar™

- **Table:** `hiring_risks`. It holds:
  - type and severity (low / medium / high / critical);
  - status (open / acknowledged / resolved / dismissed);
  - subject (morph), requisition and application;
  - owner (employee), title, description, recommended action;
  - detector version, `first_detected_at`, `last_seen_at`, `resolved_at`, resolution;
  - a link to its Action Center item.
- **One open risk per type and subject:** `open_key` has a unique index and is set only while the risk is open.
- **`HiringRiskRadar`:**
  - derives requisition risks from the current health snapshot;
  - derives entity risks from existing services: joining risk (`riskLevel()`), offers awaiting or expiring, interviewer feedback backlog, recruiter SLA breaches (`breachFor`), automation rule failures (`AutomationHealthService`) and data quality;
  - upserts open risks (bumping `last_seen_at`) and **auto-resolves** risks that are no longer observed ("no longer detected").
- **People manage the lifecycle:** acknowledge, dismiss (reason required) and resolve. Every change is audited.
- **Integration:**
  - a newly opened high or critical risk creates one Action Center item for its owner (idempotent);
  - it also dispatches `HiringRiskDetected`, a new automation trigger `intelligence.risk_detected` with fields `risk.type` and `risk.severity`, so organisations can build notify and escalate rules;
  - Next Best Action includes open risks as evidence-backed recommendations.
- **A risk never changes a candidate or requisition.**

### 3.5 Talent Rediscovery™

- **Tables:** `rediscovery_runs` (requisition, Role DNA version, run by, rules version, scanned count, result count, status) and `rediscovery_results` (candidate, rank, band, coverage, summary, status, actioned by/at/note).
- **Candidate universe:**
  - candidates the user may see (the candidate visibility scope), **plus** members of talent pools the user may see;
  - **excluding** candidates already applied to this requisition and candidates already hired (an employee record, or a joined application).
- **Bounded:** at most `intelligence.rediscovery.max_scan` candidates (default 2,000), chunked, most recently active first.
- **Scoring:** each candidate is run through `TalentSignalCalculator` against the Role DNA version. The top N (default 25) are kept, excluding `weak` and `insufficient_evidence`.
- **Why this candidate?** Evidence rows cover matched skills, experience fit, prior furthest stage, positive past feedback, pool membership and referral. A "do not contact" flag is shown when every channel is opted out.
- **Human actions:**
  - *Add to requisition* creates a Sourced application the same way `CareerApplicationService` does (code sequence, Sourced stage, `origin_channel = rediscovery`), with a timeline entry. There is no shared application-creation service to call.
  - *Add to talent pool* uses `TalentPoolService`.
  - *Dismiss* requires a note.
  - Candidate records are never modified silently.

### 3.6 Hiring Memory™

- **Table:** `hiring_memory_records`. It holds:
  - type, subject, requisition, designation, department and application;
  - `facts` JSON: an immutable snapshot of deterministic facts at capture time;
  - a deterministic summary, `captured_at` and the source event;
  - `capture_key` (unique, for idempotency);
  - `version`, `supersedes_id`, `is_current` and a correction reason;
  - an optional AI summary with its status and model.
- **Types:**

  | Type | Captured when | Holds |
  | --- | --- | --- |
  | `hire` | A candidate joins | Skills, experience, source, recruiter, time-to-hire, stage durations |
  | `rejection` | An application is rejected | Stage and reason category |
  | `joining_outcome` | A no-show or dropout | The outcome and its reason |
  | `offer_outcome` | An offer is declined, expired or withdrawn | The outcome and its reason |
  | `requisition_outcome` | A requisition is closed or cancelled | Days open, hires, joins by source, bottleneck leg, automation activity |

- **Capture** runs as queued listeners on existing events: `CandidateStageChanged`, a new `RequisitionStatusChanged` (dispatched in `RequisitionApprovalService::moveTo`) and `OfferStatusHistory` creation.
- **Corrections** create a new version that supersedes the old one, which is kept.
- **Memory feeds Role DNA historical patterns.** Phase 8 attaches outcomes to memory records later, in a separate table.

## 4. AI integration

- **Single entry point:** `IntelligenceAiService`, which calls `AiGateway::structured()`. Category `generation` is used for Role DNA suggestions and `summarization` for memory summaries.
- **Prompts minimise data:** designation, department, skills, experience, qualification and public job-posting text only. There are no candidate names, contacts or salaries. Memory summaries use role-level facts only.
- **Validation before persistence:**
  - schema-constrained output;
  - whitelisted keys and levels;
  - at most 12 items per list, strings at most 80 characters;
  - no URLs;
  - a fairness filter drops any item that mentions age, gender, marital status, religion, caste, nationality, pregnancy, disability or health.
  - Rejected items are counted and reported.
- **Queued jobs** on the `intelligence` queue, 2 tries: `GenerateRoleDnaSuggestionsJob` and `SummarizeHiringMemoryJob`.
- **Status is always one of** `not_requested`, `processing`, `available`, `failed` or `unavailable` (no provider). A failed result is never shown as intelligence.
- **Copilot tools** (in `ToolRegistrar`, hierarchy-scoped, reading persisted intelligence):

  | Tool | Behaviour |
  | --- | --- |
  | `get_role_dna` | Read |
  | `explain_talent_signal` | Read |
  | `get_hiring_health` | Read |
  | `list_hiring_risks` | Read |
  | `rediscover_talent` | Recommend — runs a deterministic rediscovery |
  | `get_hiring_memory` | Read |

- **No AI during page render.** Pages show persisted intelligence only.

## 5. Scheduling and freshness

- `intelligence:refresh` runs hourly, bounded, with options `--requisition=`, `--limit=`, `--dry-run`. It:
  - takes health snapshots for open requisitions whose snapshot is stale (older than 6 hours);
  - runs Risk Radar;
  - refreshes stale Talent Signals for active applications on open requisitions.
- **On-demand refresh** is available in the UI.
- **Idempotency:** a fresh snapshot is not recomputed within its window unless forced.

## 6. Permissions (additive grants)

| Permission | Grants |
| --- | --- |
| `intelligence.view` | Intelligence pages, within hierarchy scope |
| `intelligence.role-dna.manage` | Rebuild, edit and confirm Role DNA; request AI suggestions (together with `ai.query`) |
| `intelligence.rediscover` | Run rediscovery and act on results |
| `intelligence.risks.manage` | Acknowledge, dismiss and resolve risks |
| `intelligence.memory.view` | View Hiring Memory |
| `intelligence.memory.manage` | Correct memory; request AI summaries (together with `ai.query`) |

- **Organisation-wide views** use the existing `hierarchy.view-all`. There is no tenant model (see discovery §1).
- **Grants by role:**
  - recruiter: `view`, `rediscover`;
  - assistant manager: adds `risks.manage` and `memory.view`;
  - manager: adds `role-dna.manage`;
  - VP HR and CHRO: all.

## 7. Audit

`AuditLog` entries are written for:

- Role DNA: version created, attribute confirmed or rejected, DNA confirmed, AI suggestion requested / received / failed;
- Talent Signals: signal refresh (once per batch);
- risks: opened, acknowledged, dismissed, resolved, auto-resolved;
- rediscovery: run, result actioned;
- memory: captured, corrected, AI summary;
- evidence: verification.

No secrets or contact details appear in audit entries.

## 8. Testing matrix

| Area | Tests |
| --- | --- |
| Evidence | Immutability; verification audited |
| Role DNA | Configured, inferred and historical attributes (including insufficient history); versioning; AI suggestion validation and fairness filter; confirm/reject creates versions; AI unavailable / failed |
| Talent Signal | Each component; band rules; exclusions (culture fit); staleness; evidence rows; hierarchy |
| Health | Each metric threshold; overall status; snapshot history; stale refresh idempotency |
| Risks | Each detector; upsert; auto-resolve; lifecycle and permissions; Action Center item; automation trigger |
| Rediscovery | Scope (hidden candidates excluded; shared pool members included); exclusions; ranking; evidence; actions via existing services; bounded scan |
| Memory | Capture per event; idempotency; correction chain; feeds Role DNA |
| AI | Fake gateway; schema validation; no PII in prompts; usage logged via gateway |
| Security | Recruiter vs manager vs `view-all` visibility on every page and tool |
| Performance | Query counts constant with data size; bounded scans |
| Browser | Every module, evidence drill-down, permissions, dark mode, 8 themes |

---

## 9. As built

This section records what was implemented, and where it differs from the plan above.

### Components

| Area | Files |
| --- | --- |
| Evidence | `IntelligenceEvidence`, `EvidenceRecorder`, `EvidenceLookup` (authorised read), `Data/EvidenceItem`, the shared "Why?" slide-over (`ShowsIntelligenceEvidence`, `filament/intelligence/evidence`) |
| Role DNA | `RoleDnaProfile`, `RoleDnaVersion` (JSON column `dna`), `RoleDnaBuilder`, `RoleDnaService` |
| Talent Signal | `TalentSignalSnapshot`, `TalentSignalCalculator` (`talent-signal/1`), `TalentSignalService` |
| Hiring Health | `HiringHealthSnapshot`, `HiringHealthService` (`hiring-health/1`), `RecruitmentAnalyticsService::requisitionMetrics()` |
| Risk Radar | `HiringRisk`, `HiringRiskRadar` (`risk-radar/1`), `HiringRiskDetected` event |
| Rediscovery | `RediscoveryRun`, `RediscoveryResult`, `TalentRediscoveryService` (`rediscovery/1`) |
| Hiring Memory | `HiringMemoryRecord`, `HiringMemoryService`, `CaptureHiringMemory` listener (queued on `intelligence`); new events `OfferStatusChanged`, `RequisitionStatusChanged` |
| AI | `IntelligenceAiService`, `GenerateRoleDnaSuggestionsJob`, `SummarizeHiringMemoryJob` |
| Copilot tools | `get_role_dna`, `explain_talent_signal`, `get_hiring_health`, `list_hiring_risks`, `rediscover_talent`, `get_hiring_memory` |

### Refactors (duplication removed)

- `RecruitmentRequisition::scopeVisibleTo()` and `Candidate::visibleTo()` are now the single definitions of requisition and candidate visibility; the Filament resources call them.
- `RecommendNextStepTool` already delegated to `NextBestActionService` (Phase 6). Phase 7 adds risks as evidence-backed recommendations there.

### Integrations with earlier phases

- **Next Best Action:** open risks become recommendations with evidence lines and the affected metric.
- **Automation:** new triggers `requisition.status_changed` and `intelligence.risk_detected`; new condition facts `risk.type`, `risk.severity`, `requisition.health_status`, `application.talent_signal` and `event.new_requisition_status`.
- **Action Center:** a new high or critical risk creates one item for its owner.

### UI (navigation group "EDGE Intelligence")

- Intelligence Overview.
- Hiring Risk Radar (the register, with lifecycle actions).
- Hiring Memory (list, view, correction, AI summary).
- A per-requisition **EDGE Intelligence** page (Requisitions → View → "EDGE Intelligence") with Health, Risks, Role DNA, Talent Signals, Rediscovery and Memory.
- A **Talent Signal** slide-over on the candidate application page.

### Operations

- **Queue worker:**

  ```
  php artisan queue:work --queue=automation,communications,integrations,intelligence,default
  ```

- **Scheduled refresh:** `intelligence:refresh` runs hourly, with options `--requisition=`, `--limit=`, `--dry-run`. It is deterministic and never calls AI.
- **Configuration:** `config/intelligence.php` holds the queue, rediscovery limits, refresh bounds and AI output limits.
- **Permissions** are granted by `2026_09_26_000003_grant_phase_seven_permissions` (additive).

### AI provider behaviour discovered during testing

- The configured Gemini provider intermittently returns **HTTP 503 (overloaded)**.
- The existing `GeminiProvider`/`OpenAiProvider::structured()` used to turn that into an empty result, logged as **success**. It now throws `AiProviderUnavailableException`, so it is logged as an error.
- `GenerateRoleDnaSuggestionsJob` retries once, then marks the request Failed with an honest message.
- Live runs produced 16 job-relevant, unconfirmed suggestions (0 rejected by the fairness filter), and on another occasion an honest "Failed".

### Tests

- 68 new tests: 65 across the nine Phase 7 feature files, 2 provider-failure cases and 1 permission migration.
- A 20-check Playwright run against a throwaway MySQL database: every module, evidence drill-down, AI (live provider), permissions, dark mode, 8 themes and phone width.

### Known limitations

- Skill matching is literal tag matching; there is no synonym taxonomy. Redundant AI skill suggestions (e.g. "PHP Programming" when "PHP" exists) are dropped.
- Free-text location and qualification are compared by exact phrase only; anything else is "unknown".
- The fairness filter is a conservative keyword filter. It may drop legitimate items (e.g. "single sign-on"); a person can always add an attribute manually.
- The Hiring Health SLA metric checks at most 200 active applications per requisition, using the SLA engine per application.
- Historical Role DNA patterns need at least 3 comparable hires, so most roles show "insufficient history" until data accumulates.
- There is no multi-tenancy. The hierarchy is the access boundary (see discovery §1).

### Phase 8 extension points (not implemented)

- Hiring Memory records (type `hire`) keyed by application, requisition and designation are where 30/60/90/180-day outcomes will attach, in a separate table.
- `RoleDnaBuilder::historicalAttributes()` is the single place where outcome-weighted learning will enter.
- `NextBestAction->confidence` stays reserved for AI-derived recommendations.
