# Phase 8.1: AI Data Boundary & Privacy Hardening

Engineering reference for the AI privacy boundary added in Phase 8.1, on top of the Phase 7 release (`4fc57e5`). Security findings and their verification are in `docs/phase-8-1-security-review.md`.

## 1. Purpose

Recruitment Edge holds sensitive recruitment data, and the AI Copilot and EDGE Intelligence send data to an external model provider (Gemini by default, OpenAI supported). Phase 8.1 makes it architecturally difficult for anything but the minimum job-relevant representation to reach that provider.

The provider receives **reference codes**, never:
- names
- contact details
- individual pay figures
- private remarks
- raw records

The application stays the system of record and shows names only to authorized people. AI recommends; humans decide.

## 2. Architecture

```
Human UI ── authorization ── AiReferenceResolver (render time: CAND-2026-000123 → name, if visible)
                                   │
AI services (Copilot tools, IntelligenceAiService, RecruitmentInsightsService, RAG ingestion)
                                   │
Layer 1  AiProjector ─────────────── allowlisted, code-based representations; registers every person touched
                                   │
Layer 2  ActionExecutor sanitizer ── every tool result cleaned before it is persisted, replayed or returned
                                   │
         ConversationContextBuilder ─ codes-only system prompt, re-authorized page context, history re-sanitized
                                   │
Layer 3  AiGateway → AiEgressGuard ─ every provider call: generate, stream, structured, embed, research
                                   │
                         Gemini / OpenAI / Null
```

Defense in depth assumes a developer will eventually make a mistake. The layers are:
1. the projection;
2. tool-output sanitization;
3. the egress guard;
4. contract tests on real provider payloads;
5. architecture/source scans.

A mutation check during Phase 8.1 confirmed that reintroducing the old `get_candidate` leak fails the tests at more than one layer.

### Classes (`app/Services/AI/Privacy/`)

| Class | Role |
|---|---|
| `AiReference` | Stable references: business codes where they exist (`CAND-`, `APP-`, `EMP-`, `REQ-`, `OFR-`), `INT-{id}` / `JOIN-{id}` / `FUP-{id}` / `RISK-{id}` otherwise. `PATTERN` matches all of them. |
| `AiProjector` | The only producer of AI-facing records. Each method is an explicit allowlist. It registers the name, email and phone of every person it touches. |
| `AiSensitiveValues` | Request-scoped (`scoped` binding) register of personal values. Exact matches (whole words, case-insensitive) are removed from any provider-bound text. This is how names that no pattern could catch are still stopped. |
| `PiiPatternScrubber` | Patterns: email, phone (incl. Indian formats), PAN, Aadhaar, currency amounts (₹/Rs/INR, lakh, crore, LPA). |
| `AiFieldPolicy` | Prohibited keys and suffixes (identity/contact, compensation, remarks/private text, links, secrets) and the CONDITIONAL fields with their purpose. |
| `AiPayloadSanitizer` | Drops prohibited keys at any depth, then scrubs registered values and patterns from every string. `sanitizeStrings()` keeps keys, for model-written tool arguments. |
| `AiEgressGuard` | Called by `AiGateway` for every operation. `redact` (production default) or `block` (tests). There is **no off mode**: any other configured value behaves as `redact`. Logs kinds and counts, never values. |
| `AiReferenceResolver` | Render time only. Batch-resolves references for the viewer through the normal visibility scopes. Unresolvable codes stay bare. Output is never stored or sent. |
| `AiConversationVisibility` | Who may review whose AI conversations and action logs (§11). |

Tools get the projector through `Tools/Concerns/ProjectsForAi`, which resolves it at call time. Tools live in the singleton `ToolRegistry`, while the value register is request-scoped, so a constructor dependency would pin the first request's register in a long-running worker.

## 3. Candidate and employee representation

The standard AI view of a candidate (`AiProjector::candidateProfile()`):

```json
{
  "id": 42, "candidate_ref": "CAND-2026-000123",
  "skills": ["Laravel", "PHP"], "total_experience": 5.0, "relevant_experience": 4.0,
  "qualification": "B.Tech", "current_designation": "Developer", "notice_period_days": 30,
  "current_city": "Pune", "source": "LinkedIn", "has_email": true, "has_mobile": true,
  "applications": [{ "application_ref": "APP-2026-035253", "requisition_ref": "REQ-2026-166683",
                     "role": "Senior Laravel Developer", "stage": "Interview 1", "status": "Active",
                     "recruiter_ref": "EMP-000014", "compensation_fit": "within_budget" }]
}
```

- **Never sent:**
  - full name, email, mobile, alternate mobile, free-text location
  - current company
  - salaries
  - remarks, source details, resume path
- **Conditional:** `current_city` (location fit only) and `compensation_fit` (a category).
- **Numeric `id`s** are kept so existing tool inputs work unchanged. They identify nothing outside the application.
- **Employees** (recruiters, interviewers, managers) are `EMP-` references.
- **Interviewers in feedback summaries** are pseudonymised as "Interviewer 1..n".

## 4. Compensation policy

The provider never receives an individual pay figure: current, expected or offered CTC, salary breakdowns, or requisition budget figures. `AiProjector::compensationFit()` derives `within_budget`, `above_budget`, `below_budget` or `unknown` by comparing the candidate's expectation with the requisition budget, inside the application.

- `get_role_dna` describes the budget attribute as "configured on the requisition".
- `search_offers` no longer returns CTC.
- The egress guard also removes currency-formatted amounts from any text.

## 5. Name resolution

The AI writes codes. The human sees names only where authorized:

- **AI output:** "Candidate CAND-2026-000123 appears suitable…"
- **What the user sees:** "Candidate CAND-2026-000123 — Rahul Sharma appears suitable…"

Resolution happens in these places:
- `AiCopilot::visibleMessages()`: assistant text (Markdown-escaped), tool summaries and outputs;
- the approval preview;
- the conversation review transcript, using the *reviewer's* own visibility.

Resolved names are never written back or included in later prompts.

## 6. Communication flow

- **Drafting:** `draft_candidate_email` sends the provider the candidate *reference*. The model addresses the candidate as `{{candidate.first_name}}`.
- **Sending:** `send_candidate_email` takes `candidate_id` only. `CommunicationService` resolves the recipient address and renders the placeholders server-side at send time (preferences and consent unchanged).
- **Approving:** the pending-action card shows the approver the candidate name and recipient address, resolved for them. The AI never receives either.
- **Output:** tool results and summaries never contain addresses.
- **SMS and WhatsApp:** no AI tool sends these today. Any future messaging tool must take an entity reference, never a destination.

## 7. Conversation handling

- **System prompt:** carries the user's roles (not their name) and instructs the model to use reference codes.
- **Page context:** `context_type` / `context_id` from the URL is authorized in `AiCopilot::mount()`. A context the user cannot see is dropped silently: the user just gets a general chat, and the denial is logged with user id and type only. The properties are `#[Locked]` against Livewire tampering. `ConversationContextBuilder` re-authorizes the context against the conversation owner on every turn.
- **History replay:** each stored message is re-sanitized when replayed, so no row can be re-sent raw.
- **Legacy conversations** (created before Phase 8.1; `ai_conversations.privacy_version` is NULL):
  - kept intact and viewable;
  - read-only, never continued (so never replayed);
  - never rewritten.
  - `AiOrchestrator` refuses new turns on them and the Copilot shows a notice.

## 8. RAG boundary

- **Upload:** a knowledge-base document can be uploaded only with an explicit "contains no candidate or employee personal data" declaration. The declaration is stored (`privacy_declared_at` / `privacy_declared_by`) and audited (`ai_document_privacy_declared`).
- **Legacy documents:** documents uploaded before 8.1 are not retrieved until an administrator declares them, using a table action that re-indexes.
- **Ingestion:** chunks are pattern-scrubbed before embedding and storage. Only a count is kept (`pii_redactions`).
- **Retrieval:** retrieved chunks are scrubbed again at prompt assembly, and query embeddings pass the egress guard.
- **Out of scope:** candidate documents and resumes still never reach AI (no parsing, OCR or embedding).
- **Limitations:** retrieval is organisation-wide, and names inside documents can't be removed automatically (P81-BACKLOG-003).

## 9. AI tool inventory (48 tools)

Every tool is subject to all of the following:
- **Authorization:** its permission (`ToolRegistry::isOfferedTo`) and hierarchy scoping inside `handle()`.
- **Sanitization:** `ActionExecutor` sanitizes every result, and `AiEgressGuard` checks every provider call.
- **Approval:** Write, External and HighImpact tools still stop for human approval (`ConfirmationGate` unchanged).

The table lists what each tool sends after 8.1 and what it sent before.

| Tool | Permission / risk | Models | Provider-bound output (8.1) | Projection | Before 8.1 |
|---|---|---|---|---|---|
| search_candidates | candidates.viewAny / Read | Candidate | candidate refs, designation, city, experience, skills | `candidateListItem` | names, company, echoed query |
| get_candidate | candidates.viewAny / Read | Candidate, applications, requisition, recruiter | profile + visible applications, compensation_fit | `candidateProfile` | **full record incl. contacts, salaries, remarks, recruiter contacts** |
| list_talent_pools | talent-pools.viewAny / Read | TalentPool, Candidate | pools; member refs | `candidateListItem` | names, city |
| compare_candidates | candidates.viewAny / Read | Candidate, application | comparable facts, compensation_fit | `candidateComparable` | names, company, **expected salary** |
| summarize_candidate | candidates.viewAny / Read | Candidate, applications, interviews, feedback, history | own prompt: summary facts (no text/links/pay); result: ref + narrative | `candidateSummaryFacts` | **whole record graph, twice** |
| find_stuck_candidates | candidates.viewAny / Read | CandidateApplication | application/candidate/recruiter refs, stage, days | refs | names |
| find_duplicate_candidates | candidates.viewAny / Read | Candidate | candidate refs, match type, field names | refs | names |
| get_candidate_timeline | candidates.viewAny / Read | timeline sources | type, structural event, time | `timelineEvent` | **remarks, feedback text, notes, email content, names** |
| list_overdue_followups | followups.manage / Read | RecruitmentFollowup | refs, type, due, days overdue | `followup` | names, **remarks** |
| recommend_next_step | candidates.viewAny / Recommend | application + NBA | refs, scrubbed action/reason | refs + sanitizer | name (incl. via Risk Radar titles) |
| search_requisitions | requisitions.viewAny / Read | Requisition | codes, designation, counts | none needed | none |
| get_requisition | requisitions.viewAny / Read | Requisition, employees | job-relevant detail, employee refs | `requisitionDetail` | **salary band, remarks, employee email/mobile** |
| get_requisition_pipeline | requisitions.viewAny / Read | Requisition, applications | counts per stage | none needed | none |
| find_at_risk_requisitions | requisitions.viewAny / Read | Requisition | codes, ageing | none needed | none |
| generate_jd | requisitions.create / Recommend | none | user-supplied role facts → own prompt (amounts scrubbed) | guard | user-supplied salary text |
| improve_jd | requisitions.update / Recommend | none | user-pasted JD → own prompt (scrubbed) | guard | user text |
| analyze_funnel / analyze_sources / time_to_hire / forecast_hiring | performance.view | analytics | aggregates | none needed | none |
| generate_dashboard_insights | ai.query / Read | analytics | aggregates + caller's own metrics | none needed | none |
| get_recruiter_performance / compare_recruiters / find_inactive_recruiters | performance.view / Read | Employee, metrics | employee refs, scores/metrics (CONDITIONAL) | `employeeRef` | employee names |
| generate_interview_questions | interviews.manage / Recommend | Candidate (optional) | role facts; candidate experience/skills/designation | registers candidate | **current company** |
| generate_interview_plan | interviews.manage / Recommend | none | role facts | none needed | none |
| search_interviews | interviews.manage / Read | Interview | interview/application/candidate/interviewer refs, status | `interview` | candidate & interviewer names |
| summarize_interview_feedback | interviews.manage / Read | interviews, feedback | own prompt: pseudonymised, scrubbed excerpts; result: scores, recommendations, narrative | `feedbackForSummary` | **raw feedback text, interviewer & candidate names, twice** |
| analyze_offers / analyze_joining_conversion | offers.manage / joining.confirm | Offer / Joining | aggregates | none needed | none |
| search_offers | offers.manage / Read | Offer | offer/application/candidate refs, status, dates | `offer` | names + **offered CTC** |
| find_joining_risks | joining.confirm / Read | CandidateJoining | refs, status, expected DOJ, risk (max 50) | `joining` | names, unbounded |
| search_knowledge_base | ai.query / Read | RAG | declared, scrubbed chunks | ingestion scrubber + guard | chunk text as uploaded |
| web_research | ai.query / Read | none | market-level query only (≤120 chars, no references/PII), no echo | query guard | free-text query, echoed |
| build_recruitment_plan | requisitions.create / Recommend | analytics | aggregates, role/location | none needed | none |
| assign_candidates_to_recruiter | candidates.reassign / Write | applications, Employee | ids, recruiter ref | `employeeRef` | recruiter name |
| move_candidates_stage / reject_candidates / create_followup | pipeline.transition / followups.manage | applications | ids, counts, labels | none needed | none |
| schedule_interview | interviews.manage / External | application, Employee | ids; summary with candidate ref | `candidateRef` | candidate name; interviewer id unscoped |
| draft_candidate_email | candidates.update / Recommend | Candidate | own prompt: candidate ref + placeholder; result: ref, subject, body | `candidateRef` | name, **email address** |
| send_candidate_email | candidates.update / External | Candidate | ids; "queued to CAND-… (address on file)" | `candidateRef` | name + **email address** |
| get_role_dna | intelligence.view / Read | Role DNA | role attributes; budget described, not quoted | tool-level | **budget figures** |
| explain_talent_signal | intelligence.view / Read | snapshot, evidence | band, components, evidence (other applications only if visible) | registers candidate | history evidence unscoped |
| get_hiring_health | intelligence.view / Read | snapshot | metrics | none needed | none |
| list_hiring_risks | intelligence.view / Read | HiringRisk | projected title "{type} — {ref}", scrubbed description/evidence | `hiringRisk` | **titles naming candidates / interviewers** |
| rediscover_talent | intelligence.rediscover / Recommend | rediscovery results | candidate refs, band, why | `candidateRef` | codes (Phase 7) |
| get_hiring_memory | intelligence.memory.view / Read | HiringMemoryRecord | summaries (no names) | none needed | none |

The authoritative, executable version of this table is `tests/Feature/Ai/Privacy/ToolPayloadContractTest.php`. It has one case per registered tool and fails if a new tool has none.

## 10. Other provider paths

- **`IntelligenceAiService::roleDnaMessages`:** role-level data only, unchanged. Verified by contract.
- **`IntelligenceAiService::summarizeMemory`:** an explicit per-type fact allowlist (`SUMMARY_FACTS`) replaces the previous denylist, so a future fact key can't leak by default.
- **`RecruitmentInsightsService`:** aggregates only. Covered by the guard.
- **`ai:test-provider`:** a diagnostic that calls a provider directly with fixed prompts and no application data. It is the one allowed exception (P81-BACKLOG-004).

## 11. Permissions

| Permission | Grants |
|---|---|
| `ai.query` | Copilot, own conversations |
| `ai.actions.execute` | approving AI actions (unchanged) |
| `ai.manage` | knowledge base, documents, usage, evaluations. **No longer** grants reading other users' conversations |
| **`ai.conversations.view`** (new) | reviewing other users' AI conversations and action logs, **limited to the reviewer's hierarchy** unless they hold `hierarchy.view-all`. Granted to `vp_hr` (and `chro` via `*`) by an additive migration. |
| `audit.view` | AuditLog only. Grants nothing in AI. |

- **Out-of-scope conversations:** a record outside the reviewer's scope returns 404, without confirming it exists.
- **Deletion:** only the owner may delete a conversation.
- **Auditing:** permission changes to roles are audited by the existing role-permission audit.

## 12. AI jobs and logging

- **Job payloads:** all AI jobs carry ids only. `GenerateRoleDnaSuggestionsJob` and `SummarizeHiringMemoryJob` are unchanged (tries 2, `uniqueFor` 3600).
- **Indexing jobs:** `IndexAiDocumentJob` and `ReindexKnowledgeArticleJob` now retry with backoff, are `ShouldBeUnique` with `uniqueFor` 3600, and record failure.
- **Stale requests:** the Phase 7 stale-request expiry is unchanged.
- **What logs carry:**
  - Provider, gateway and tool failures: status, the provider's error code, the exception class and a scrubbed message only. Never request or response bodies (closes TD-003).
  - The egress guard: counts by kind.
  - Denied page contexts: user id and type.
- **What persistence stores:** `ai_tool_results`, `ai_action_logs` and Tool `ai_messages` store only the sanitized payload, so persistence is part of the boundary.

## 13. Database changes (all additive)

| Table | Column | Type | Purpose | Rollback |
|---|---|---|---|---|
| `ai_conversations` | `privacy_version` | unsigned tinyint, nullable | NULL = legacy (read-only); 1 = Phase 8.1 boundary | `dropColumn` |
| `ai_documents` | `privacy_declared_at` | timestamp, nullable | no-personal-data declaration | `dropColumn` |
| `ai_documents` | `privacy_declared_by` | FK users, nullable, nullOnDelete | who declared | `dropConstrainedForeignId` |
| `ai_documents` | `pii_redactions` | unsigned int, default 0 | values removed at ingestion (count only) | `dropColumn` |
| permissions | `ai.conversations.view` | data (grant migration) | new permission, granted additively | none (additive) |

No historical AI record was rewritten. `php artisan ai:redact-history --dry-run` reports stored personal data (counts only, audited). No redaction mode exists (P81-BACKLOG-002).

## 14. Configuration

`config/ai.php` → `privacy`:
- `egress_mode`: `AI_PRIVACY_EGRESS_MODE`, default `redact`. Tests use `block` via `phpunit.xml`.
- `feedback_excerpt_chars`: default 1000.
- `research_query_max_chars`: 120.

Nothing is admin-editable, and no setting can disable the guard.

## 15. Testing strategy

All tests use fake providers; no real key is used.

- **Contract (`ToolPayloadContractTest`):**
  - Every one of the 48 tools runs through the real orchestrator and approval path.
  - Records are seeded with unmistakable sentinel values: names, emails, phones, salaries, remarks, company, meeting link, notes.
  - The test inspects the actual provider payloads, embedding inputs, web queries and AI persistence, plus each tool's raw result before any sanitizer.
  - A completeness check fails when a tool has no case.
- **Unit:** primitives (scrubber, registry, sanitizer, field policy, reference pattern).
- **Feature:**
  - projector, resolver, guard and gateway (every operation, block and redact);
  - tool-output boundary and legacy replay;
  - conversation privacy (system prompt, tampered context, Livewire lock, legacy read-only);
  - communication end to end;
  - RAG, jobs and logging;
  - permissions;
  - two-user hierarchy.
- **Architecture (`AiBoundaryArchitectureTest`):**
  - providers used only via `AiGateway`;
  - no `Http` or provider use in tools;
  - no model dumps;
  - no prohibited field reads;
  - people-reading tools use `AiProjector`, with an explicit, justified exemption list.
- **Browser:** a real-browser smoke on a throwaway database with an unreachable fake provider (12 checks). Phase 7 (20) and Phase 6 (24) smokes re-run as regression.

## 16. Known limitations and future work

See `docs/backlog.md`:
- P81-BACKLOG-001: names typed by users.
- P81-BACKLOG-002: legacy history at rest.
- P81-BACKLOG-003: knowledge-base access and retention.
- P81-BACKLOG-004: the diagnostic command exception.
- P81-BACKLOG-005: inactive-recruiter scope.
- P81-BACKLOG-006: performance metrics policy.

The product is single-organisation. The reporting hierarchy is the access boundary, and Phase 8.1 makes no multi-tenancy or legal-compliance claim.
