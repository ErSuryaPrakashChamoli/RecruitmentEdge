# Phase 8.1: Security Review

Each finding below lists its risk, the fix, how it was verified, and any remaining limitation. "Contract" means `tests/Feature/Ai/Privacy/ToolPayloadContractTest.php`, which runs every registered tool against sentinel-seeded data and inspects the real provider payloads and AI persistence.

## Findings

**F1. `get_candidate` sent the whole candidate record.** This included contacts, salaries, remarks and the resume path, plus the recruiter's contact details (`$candidate->toArray()`).
- **Risk:** High. Personal and compensation data went to the external provider on a Read tool that needs no approval.
- **Fix:** `AiProjector::candidateProfile`: codes, job-relevant facts and `compensation_fit`. Applications are filtered to those visible to the caller.
- **Verification:**
  - Contract.
  - Mutation check: restoring `toArray()` fails the raw-result and provider-payload assertions.
  - `AiBoundaryArchitectureTest` blocks model dumps.
- **Remaining limitation:** none.

**F2. `summarize_candidate` sent the whole record graph, twice.** It went once into its own prompt and again in its result. That included feedback text, meeting links and remarks.
- **Risk:** High.
- **Fix:** `candidateSummaryFacts` (results, recommendations and scores; no text, links or pay). The result returns the reference and narrative only.
- **Verification:** Contract.
- **Remaining limitation:** none.

**F3. `get_requisition` sent the whole requisition.** This included the salary band, remarks and full Employee rows (email, mobile, date of joining, photo path).
- **Risk:** High.
- **Fix:** `requisitionDetail`: job-relevant fields, with people as `EMP-` references.
- **Verification:** Contract; `AiProjectorTest`.
- **Remaining limitation:** none.

**F4. `search_offers` sent a named candidate's offered CTC.**
- **Risk:** High.
- **Fix:** `AiProjector::offer`: references, status and dates, no pay. The tool description was corrected.
- **Verification:** Contract (sentinel `99999999`).
- **Remaining limitation:** none.

**F5. `summarize_interview_feedback` and `get_candidate_timeline` sent free text.** Raw feedback text, interviewer and candidate names, notes, and email subjects and bodies all reached the provider.
- **Risk:** High.
- **Fix:**
  - Feedback reaches the provider only inside the summarisation prompt, truncated and scrubbed, with interviewers pseudonymised.
  - The timeline is reduced to structure (type, event, time).
- **Verification:** Contract; `AiProjectorTest`.
- **Remaining limitation:** feedback excerpts can still carry job-relevant opinions about the candidate. This is by design (CONDITIONAL).

**F6. The email tools sent the candidate's email address.**
- **Risk:** Medium.
- **Fix:**
  - The model drafts using a `{{candidate.first_name}}` placeholder.
  - The send tool takes an id, and `CommunicationService` resolves the address and name at send time.
  - Summaries say "address on file".
- **Verification:** `CommunicationPrivacyTest`, end to end: the provider saw only the reference, and delivery went to the right address with the name merged.
- **Remaining limitation:** none.

**F7. The Copilot page context was unscoped.** A URL `context_id` was loaded with an unscoped `find()`, and the name went into the system prompt, so any `ai.query` user could pull any candidate's or employee's name into the prompt.
- **Risk:** Medium.
- **Fix:**
  - The context is authorized on mount (denied silently, logged safely).
  - It is `#[Locked]` against Livewire tampering.
  - It is re-authorized against the conversation owner every turn.
  - The prompt now carries codes only, and the user's own name was removed.
- **Verification:**
  - `ConversationPrivacyTest`, covering a tampered id, the Livewire lock and the system prompt.
  - Browser smoke checks 1–3.
- **Remaining limitation:** none.

**F8. `list_hiring_risks` forwarded stored risk titles that name candidates or interviewers.** This corrects the Phase 7 "codes-only" statement.
- **Risk:** Medium.
- **Fix:** The title is projected as "{type} — {reference}", and descriptions and evidence are scrubbed. Stored titles and the UI are unchanged.
- **Verification:** Contract; `AiProjectorTest`.
- **Remaining limitation:** none.

**F9. `recommend_next_step` repeated Risk Radar titles containing the candidate's name.** The contract test found this.
- **Risk:** Medium.
- **Fix:** Everyone involved is registered, and the action and reason text are scrubbed before return.
- **Verification:** Contract (raw-result assertion).
- **Remaining limitation:** none.

**F10. `get_role_dna` sent the requisition budget figures.** The contract test found this.
- **Risk:** Medium.
- **Fix:** The budget attribute is described as "configured on the requisition", never quoted.
- **Verification:** Contract.
- **Remaining limitation:** none.

**F11. No provider egress control.** Tools' own prompts, intelligence prompts, embeddings and web queries went straight to the provider.
- **Risk:** High (systemic).
- **Fix:** `AiEgressGuard` runs on every `AiGateway` operation. It is in redact mode in production and block mode in tests, with no off mode. Research queries that would need redaction are not sent.
- **Verification:**
  - `GatewayEgressTest`, covering all five operations in both modes.
  - The whole suite runs in block mode.
- **Remaining limitation:** Names can't be matched by pattern. They are covered by projection and the request-scoped value register (P81-BACKLOG-001).

**F12. Persisted tool output was replayed on every later turn.** `ai_tool_results`, `ai_action_logs` and Tool messages stored raw results, and the context builder replayed them to the provider.
- **Risk:** High.
- **Fix:** `ActionExecutor` sanitizes before persisting, appending or returning. `ConversationContextBuilder` re-sanitizes history on replay.
- **Verification:** `ToolOutputBoundaryTest`, including legacy replay; the contract test's persistence assertions.
- **Remaining limitation:** Pre-8.1 rows still hold personal data at rest (P81-BACKLOG-002).

**F13. Legacy conversations could be continued and re-sent.**
- **Risk:** Medium.
- **Fix:** `privacy_version` (additive). Legacy conversations are read-only: never continued, never rewritten, still viewable with permission.
- **Verification:** `ConversationPrivacyTest`; browser smoke check 6.
- **Remaining limitation:** P81-BACKLOG-002.

**F14. `ai.manage` gave org-wide access to every user's conversation and action log, including tool output.**
- **Risk:** Medium.
- **Fix:** New hierarchy-scoped `ai.conversations.view` permission. `ai.manage` and `audit.view` grant no conversation access, and out-of-scope records return 404.
- **Verification:** `AiConversationPermissionTest`; browser smoke checks 7 and 9.
- **Remaining limitation:** none.

**F15. `schedule_interview` accepted any employee id as the interviewer.**
- **Risk:** Low.
- **Fix:** The interviewer must be on the active interviewer list or inside the caller's hierarchy (the same rule as the interview form).
- **Verification:** Contract; existing schedule tests.
- **Remaining limitation:** none.

**F16. `explain_talent_signal` history evidence could describe applications the viewer can't see.**
- **Risk:** Low.
- **Fix:** Evidence about other applications is kept only if the viewer can see them.
- **Verification:** Contract; `HierarchyPrivacyTest`.
- **Remaining limitation:** none.

**F17. Knowledge-base content reached the provider unchecked.**
- **Risk:** Medium.
- **Fix:**
  - A no-personal-data declaration is required and audited, and undeclared documents are not retrieved.
  - Chunks are scrubbed before embedding and storage, and again at prompt assembly.
- **Verification:** `RagPrivacyTest`; browser smoke checks 10–11.
- **Remaining limitation:** Organisation-wide retrieval; names inside documents; chunk retention (P81-BACKLOG-003).

**F18. `web_research` accepted any free-text topic and echoed it back.**
- **Risk:** Low.
- **Fix:** Market-level queries only (≤120 chars, no references or PII), and no echo.
- **Verification:** `GatewayEgressTest`; Contract.
- **Remaining limitation:** none.

**F19. Provider and tool failure logs included response bodies and raw exception messages (TD-003).**
- **Risk:** Low.
- **Fix:** Logs carry status, the provider's error code and the exception class, with scrubbed messages.
- **Verification:** `AiJobsAndLoggingPrivacyTest`.
- **Remaining limitation:** none.

**F20. The Hiring Memory summary prompt used a denylist.** A future fact key would have leaked by default.
- **Risk:** Low.
- **Fix:** An explicit per-type allowlist.
- **Verification:** `AiJobsAndLoggingPrivacyTest`.
- **Remaining limitation:** none.

**F21. The indexing jobs had no retry, uniqueness or failure record.**
- **Risk:** Low.
- **Fix:** Tries and backoff, `ShouldBeUnique` with `uniqueFor` 3600, and a `failed()` handler.
- **Verification:** `AiJobsAndLoggingPrivacyTest`.
- **Remaining limitation:** none.

**F22. `ai:test-provider` calls providers directly.**
- **Risk:** Low.
- **Fix:** Accepted exception: it sends fixed prompts and no application data. The architecture test allows exactly this class.
- **Verification:** `AiBoundaryArchitectureTest`.
- **Remaining limitation:** P81-BACKLOG-004.

## Preserved controls (verified unchanged)

- **Approval gate:** Write, External and HighImpact tools still stop for human approval. `ActionExecutor::approve` re-checks `ai.actions.execute`, the tool permission, the feature flag and the rate limit (existing `ActionExecutorTest`, plus the contract's approval path).
- **No autonomy:** AI can't reject, move, email or schedule without that approval.
- **Hiring decisions:** Rediscovery still excludes hired candidates and current applicants (existing `TalentRediscoveryTest`).
- **Role DNA** stays role-level. **Hiring Health**'s "Insufficient history" is unchanged and expected.
- **Hierarchy:** the reporting hierarchy remains the access boundary. `HierarchyPrivacyTest` shows that User A can't reach User B's candidate through eight tools, that nothing about it reaches the provider, and that the denial doesn't confirm the record exists.

## Secrets

- `.env` is not tracked, and no API key appears in any file, test or commit.
- Tests use fake providers and a fake key.
- The browser smoke used an unreachable fake provider and a fake key on throwaway databases, which were dropped afterwards.

## Not claimed

Phase 8.1 does not make the product multi-tenant and does not claim compliance with any law or regulation. Items outside the AI boundary that the Phase 8 discovery found are listed in `docs/backlog.md`.
