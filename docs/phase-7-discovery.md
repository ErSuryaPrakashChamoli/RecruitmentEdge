# Phase 7 — EDGE INTELLIGENCE™: Discovery

This is a discovery of the real repository on branch `feature/sep_25_hrm` before any Phase 7 code. At the time of discovery:

- the test suite stood at 1,022 passing;
- there were 217 routes and 115 migrations;
- Phases 4–6 were present and uncommitted.

Everything below was verified by reading the code, not assumed.

---

## 1. Executive summary

Recruitment Edge already has most of the building blocks EDGE INTELLIGENCE needs:

- **A single, provider-agnostic AI gateway** (`AiGateway`). It already offers schema-constrained output (`structured()`), usage and cost logging, a no-key fallback (`NullProvider`), and a tool registry with permission-gated tools.
- **Deterministic analytics** in `RecruitmentAnalyticsService`: funnel, sources, ageing, position health, interviews, offers, joining, communication and automation.
- **SLA** in `RecruitmentSlaService`, including a per-application breach check.
- **Hierarchy scoping** through a closure table (`HierarchyService`).
- **Phase 6 automation:** events, rules, the Action Center and deterministic Next Best Action.
- **Candidate evidence:** the unified candidate timeline and duplicate detection.
- **Audit:** `AuditLog`.

What does **not** exist:

- any persisted, versioned, evidence-backed intelligence;
- a structured role model beyond the requisition's own columns;
- per-requisition health metrics (analytics are period- and user-scoped);
- a risk lifecycle;
- a candidate-to-role match;
- a long-term memory of hiring outcomes.

There is no multi-tenant concept (no `organization_id` / tenant anywhere). The application is single-organisation, and the hierarchy is the access boundary.

This is a design fact, not a blocking conflict. Phase 7 respects it by scoping every intelligence query through the existing hierarchy/visibility rules. It does not introduce a partial tenancy column that the rest of the system would not honour.

## 2. Current architecture (relevant parts)

| Layer | Existing components |
| --- | --- |
| Domain services | `StageTransitionService`, `InterviewService`, `OfferService`, `CandidateJoiningService`, `RequisitionApprovalService` (with `moveTo`/`close`), `ReferralService`, `TalentPoolService`, `CareerApplicationService` |
| Analytics | `RecruitmentAnalyticsService`: 16 period/user-scoped reports incl. `vacancyAgeing`, `positionHealth`, `joiningRisks`, `candidateAging`, `conversionBreakdown` |
| SLA | `RecruitmentSlaService`: `stageTat`, `openBreaches`, `openPipelineStageBreaches`, `breachFor()` |
| Automation (Phase 6) | `AutomationEngine`, event / field / action registries, `EscalationService`, `RecruiterActionService`, `NextBestActionService`, `AutomationHealthService` |
| AI | `AiGateway` (`generate` / `stream` / `structured` / `embed` / `research`, all logged to `ai_usage_logs` with cost), `AiProviderManager` (Gemini / OpenAI / Null), `ModelRouter` (task categories → model), `AiOrchestrator` (Copilot), `ToolRegistry` + ~42 `AiTool`s, `ActionExecutor` + `ConfirmationGate` (human approval for write tools), RAG (`VectorSearch` over `ai_document_chunks`) |
| Evidence-like data | `CandidateTimelineService` (unified timeline), `candidate_stage_histories` (immutable), `offer_status_histories`, `recruitment_requisition_approvals` / status history, `InterviewFeedback` (1–5 ratings + recommendation), `AuditLog` |
| Scoping | `HierarchyService`, `CandidateResource::getEloquentQuery()` (candidate visibility), `RecruitmentRequisitionResource::getEloquentQuery()` (requisition visibility), `TalentPool::visibleTo`, `ScopesToHierarchy` (AI tools) |

## 3. Existing intelligence / AI / analytics capabilities

- **Copilot tools already reason over the data:**
  - `SummarizeCandidateTool`, `CompareCandidatesTool` (facts only, "does not judge fit");
  - `FindAtRiskRequisitionsTool` (uses `positionHealth`);
  - `AnalyzeFunnel` / `Sources` / `Offers`, `FindJoiningRisks`, `ForecastHiring`, `RecommendNextStep` (now delegates to `NextBestActionService`).
- **`RecruitmentInsightsService`** is the established facts-then-narrate pattern: deterministic facts are gathered and the model only narrates them, rendered as separate sections. Phase 7 follows the same pattern.
- **`AiGateway::structured()`** already provides JSON-schema-constrained output and records usage. Phase 7 AI generation uses it; no new provider code.
- **Missing:** no scoring of candidates against a role, and no persisted intelligence records, versions, evidence or confidence anywhere.

## 4. Evidence available per entity

| Entity | Deterministic evidence available |
| --- | --- |
| Candidate | `skills` (JSON array), `total_experience`, `relevant_experience`, `qualification`, `current_city`/`location`, `current_company`/`designation`, `current_salary`/`expected_salary`, `notice_period_days`, `source_id`, referral link, talent-pool memberships, communication preferences, timeline |
| Application | stage, status, recruiter, `application_date`, `last_activity_at`, configured pipeline stage, stage history (immutable, timestamped), rejection / dropout reasons (categorised), origin channel, campaign |
| Requisition | designation, department, location, openings, employment type, `experience_min`/`max`, `qualification`, `skills` (JSON array), `shift`, `salary_min`/`max`, priority, dates, status + history, recruiters, managers, pipeline template, job posting |
| Interview | round, interviewer, scheduled time, status, result, feedback (ratings: technical, communication, problem-solving, culture fit; overall score; recommendation) |
| Offer | status + history, CTC components, dates, expiry, expected joining |
| Joining | expected / actual date, status, risk level (computed), no-show / dropout reasons |
| Source | `CandidateSource`, `sourceAnalytics`, recruitment costs, campaigns |
| Automation | executions with condition traces and action outcomes, Action Center item resolution times |

## 5. Automation / Next Best Action / audit / hierarchy / permissions

- **Phase 6 automation** extends through registries:
  - new triggers go in `AutomationEventRegistry` plus a listener method;
  - new condition facts go in `AutomationFieldRegistry`;
  - new actions go in `AutomationActionRegistry`.
- **Next Best Action** (`NextBestActionService`) returns a `NextBestAction` data object that was designed to be extended with confidence and evidence.
- **Action Center:** `RecruiterActionService` handles idempotent create, ownership and audit.
- **Audit:** `AuditLog::record()` for explicit events, and the `Auditable` trait on models without their own history table.
- **Permissions:** Spatie, with per-phase role grant constants and additive grant migrations. `hierarchy.view-all` is the existing "see everything" permission.

## 6. Data quality limitations

- `skills` are free-text tags; there is no skill taxonomy or synonyms. Matching is case-insensitive exact-tag matching, and the UI must say so.
- Location is free text (candidate) against a `Location` record (requisition), so only an exact name match counts as a fact.
- Salary fields may be empty, and currency is implicit.
- There is no education taxonomy: `qualification` is free text.
- Interview feedback exists only where interviewers recorded it.
- Only 6 candidates, 5 applications and 6 requisitions exist in the dev database. Historical patterns will be "insufficient evidence" until real data accumulates, and the design must say this rather than invent patterns.
- There is no holiday calendar (Phase 6 limitation).

## 7. Missing Phase 7 data

- a versioned role definition, with origins (configured / inferred / historical / AI / human-confirmed);
- persisted, versioned candidate-to-role signals with evidence;
- per-requisition health snapshots over time;
- a risk register with lifecycle and ownership;
- rediscovery runs, results and human decisions on them;
- long-term memory records of hiring events;
- a shared provenance (evidence) record.

## 8. Duplicate-functionality risks

| Risk | Mitigation |
| --- | --- |
| A second analytics engine for health | Health composes `RecruitmentAnalyticsService` / `RecruitmentSlaService` / `AutomationHealthService`. Per-requisition metrics are added as methods on the analytics service, not in a new engine. |
| A second recommendation engine | Risks and signals feed the existing `NextBestActionService` and Action Center. |
| A second AI provider path | All AI goes through `AiGateway` (`structured` / `generate`); Copilot access is through new `AiTool`s in `ToolRegistrar`. |
| A second candidate search / matcher | Rediscovery reuses the Talent Signal calculator, the candidate visibility scope and talent pool visibility. |
| A second "at-risk requisition" logic | Risk Radar reuses `positionHealth` thresholds and settings (`position_risk_*`, `vacancy_ageing_alert_days`, `candidate_stall_days`). |
| Duplicated requisition scope | The requisition visibility rule is currently copied in 3 places (`RecruitmentRequisitionResource`, `CostPerHireService`, `EmployeeReferral`). Phase 7 extracts it to `RecruitmentRequisition::scopeVisibleTo()` and makes the resource use it rather than adding a 4th copy. |
| A second timeline / audit | Memory records point to their sources; they do not copy the timeline. Auditing uses `AuditLog`. |

## 9. Reuse map

| Phase 7 need | Reused component |
| --- | --- |
| AI generation / explanation | `AiGateway::structured()` / `generate()`, `ModelRouter` categories, `ai_usage_logs`, `CallsLanguageModel`, `ToolRegistrar` |
| Scoping | `HierarchyService`, candidate scope (`CandidateResource`), requisition scope (extracted), `TalentPool::visibleTo`, `ScopesToHierarchy` |
| Health metrics | `positionHealth`, `vacancyAgeing`, `RecruitmentSlaService::breachFor/openPipelineStageBreaches`, `AutomationHealthService`, `CandidateJoining::riskLevel()`, settings |
| Recommendations | `NextBestActionService` (extended with evidence), `RecruiterActionService`, Action Center |
| Automation | `AutomationEventRegistry` (new trigger), `AutomationFieldRegistry` (new facts) |
| Rediscovery actions | `TalentPoolService::addCandidates`, existing application creation path |
| Audit | `AuditLog::record`, `Auditable` |
| Candidate privacy | `CommunicationPreferenceService` (do-not-contact flag), `CandidatePolicy` |

## 10. Proposed Phase 7 architecture (summary; detail in `phase-7-architecture.md`)

```
Requisition ──► Role DNA (versioned) ──► Talent Signal (per candidate × DNA version) ──► human decision
     │                    ▲                          │
     ▼                    │                          ▼
Hiring Health ◄── metrics ── analytics/SLA     Talent Rediscovery (signal calculator over visible talent)
     │
     ▼
Risk Radar (risk register, lifecycle) ──► Action Center / NBA / automation trigger
                         ▼
Hiring Memory (immutable, versioned facts captured from real events) ──► feeds Role DNA history
All outputs ──► Evidence (provenance rows) ──► Explainability UI
```

## 11. Proposed database changes (additive only)

| Table | Holds |
| --- | --- |
| `intelligence_evidence` | Shared provenance rows |
| `role_dna_profiles` | One per requisition |
| `role_dna_versions` | Immutable snapshots |
| `talent_signal_snapshots` | Candidate signal against a Role DNA version |
| `hiring_health_snapshots` | Requisition health over time |
| `hiring_risks` | The risk register |
| `rediscovery_runs` | One per rediscovery run |
| `rediscovery_results` | Ranked candidates per run |
| `hiring_memory_records` | Immutable memory, with corrections linking to what they supersede |

Plus permission grants (additive), and indexes on every reporting and filter column.

## 12. Service boundaries (new, each with its reason)

| Service | Why it is needed |
| --- | --- |
| `EvidenceRecorder` | Provenance is new; nothing records it today |
| `RoleDnaService` / `RoleDnaBuilder` | No role model exists |
| `TalentSignalCalculator` / `TalentSignalService` | No candidate-to-role signal exists. The calculator is shared with rediscovery. |
| `HiringHealthService` | Composes existing analytics per requisition; not a new analytics engine |
| `HiringRiskRadar` | New risk register lifecycle; detection reuses health metrics and existing services |
| `TalentRediscoveryService` | Orchestrates scope + calculator + existing pool and application services |
| `HiringMemoryService` | Captures immutable memory from existing events |
| `IntelligenceAiService` | Builds prompts, calls `AiGateway::structured()`, validates output; the only Phase 7 AI entry point |

## 13. AI boundaries

- AI may:
  - suggest Role DNA attributes (unconfirmed until a human confirms them);
  - explain signals, health and memory using only persisted evidence.
- AI never computes metrics, never changes scores or bands, never decides, and never writes to hiring records.
- AI output is schema-constrained, then validated and whitelisted before it is persisted.
- AI failure or absence leaves every deterministic feature working. AI status is always shown as available, processing, failed or unavailable.

## 14. Security / privacy

- Every query is hierarchy-scoped, using the existing candidate, requisition and pool visibility rules.
- Organisation-wide views need `hierarchy.view-all`, the existing explicit permission.
- Text sent to AI carries no names, emails, phone numbers or salary figures, only role and skills data and aggregate counts.
- No protected attributes exist in the schema. Phase 7 must not add or infer them, and must not use proxies. Excluded:
  - name;
  - photo;
  - age derived from experience;
  - the `culture_fit` rating, which is excluded from signals as a known bias proxy.

## 15. Explainability

- Every intelligence value has evidence rows, each carrying:
  - type (fact / metric / recruiter or interviewer input / configuration / AI);
  - source record;
  - observed-at time;
  - generator and version;
  - confidence (AI only);
  - explanation;
  - verification status.
- The UI always shows "why" next to "what".

## 16. Performance

- Intelligence is computed deterministically and persisted, never on page render.
- AI runs only in queued jobs.
- Work is bounded: candidate scans are chunked and capped, and snapshots are refreshed on a schedule plus on demand.
- A snapshot is idempotent within its freshness window, and stale snapshots are marked stale.

## 17. Testing

Unit and feature tests per module, security and hierarchy tests, AI validation with a fake gateway, performance (query-count) tests, and a Playwright browser pass over every module in dark mode and all 8 themes.

## 18. Phase 8 extension points

- Hiring Memory records are keyed by type, subject and requisition, and carry a `version` / `supersedes_id` chain.
- A future `hiring_outcomes` table can reference memory records (e.g. a 90-day outcome for a hire memory) without changing them.
- Role DNA historical signals read memory through one method, so outcome-weighted learning can be added there later.

## 19. Risks

- **Thin data:** most historical signals will be "insufficient evidence" at first. This is correct, and it is surfaced honestly.
- **Skill-tag matching is literal:** synonyms are missed. This is documented, and AI suggestions for skills are marked unconfirmed.
- **Evidence volume grows with signals:** mitigated by bounded refresh, superseding snapshots and a retention window.
- **Free-text location/qualification:** only exact matches are treated as facts; everything else is "unknown", never "mismatch".

## 20. Explicit non-goals

- no 30/60/90/180-day outcomes;
- no Quality of Hire;
- no Hiring Replay;
- no Outcome Loop;
- no learning from employee performance;
- no autonomous reject / select / offer / join decisions;
- no opaque single AI score;
- no multi-tenancy retrofit;
- no new AI provider or gateway.
