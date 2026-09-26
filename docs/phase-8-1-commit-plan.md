# Phase 8.1: Commit Plan

Phase 8.1 was committed incrementally on `feature/sep_25_hrm`, on top of the Phase 7 release (`4fc57e53640554467817a6dd93a63bb4a359bc46`). **Nothing has been pushed.**

**Grouping:** the commits follow the suggested structure with two deliberate deviations:
- The tool projections share a commit with the `ActionExecutor` sanitization. The boundary test for sanitized persistence only passes once tools stop putting names in free-text summaries.
- The egress guard comes after the tools. It runs in block mode in tests, so it can only be switched on once no tool leaks.

**State of each commit:**
- Each commit passed the full suite in parallel.
- Each was Pint-clean and scanned for secrets.
- Migrations are additive.

## Commits

| # | Commit | Contents | Suite at commit |
|---|---|---|---|
| 1 | `cbf2d04` Phase 8.1 — AI projector and safe references | Privacy core in `app/Services/AI/Privacy` (AiReference, AiProjector, AiReferenceResolver, AiFieldPolicy, PiiPatternScrubber, AiSensitiveValues, AiPayloadSanitizer, AiEgressGuard), `ai.privacy` config, scoped register binding, unit and feature tests. Not wired yet. | green |
| 2 | `476c5d7` Phase 8.1 — AI tool output sanitization and projections | `ActionExecutor` sanitizes every result before persisting, replaying or returning it. The context builder re-sanitizes history. 26 tools move to AiProjector through the `ProjectsForAi` trait. Email placeholder flow; interviewer and Talent Signal history scoping; web-research query guard. | 1,155 passed |
| 3 | `ed80ccb` Phase 8.1 — provider egress guard | `AiGateway` guards generate, stream, structured, embed and research. Scrubbed failure logs. Block mode in `phpunit.xml`. Recording fakes capture embedding texts and queries. | 1,155 passed (block mode) |
| 4 | `ae0d45e` Phase 8.1 — communication and conversation privacy | Codes-only system prompt with re-authorized page context. Copilot context authorization and `#[Locked]` properties. Render-time name resolution (Copilot and review transcript). Approval preview with the resolved recipient. Legacy conversations read-only (`ai_conversations.privacy_version`, additive). | 1,171 passed |
| 5 | `2fc3b43` Phase 8.1 — RAG, jobs and logging hardening | Knowledge-base declaration (required, audited, legacy action; additive `ai_documents` columns). Ingestion and retrieval scrubbing. Indexing jobs: retry, unique, `uniqueFor`, `failed()`. Hiring Memory prompt allowlist. Provider logs without bodies (TD-003). | 1,184 passed |
| 6 | `b482742` Phase 8.1 — AI conversation permission and hierarchy hardening | `ai.conversations.view` (seeder plus additive grant migration). Hierarchy-scoped conversation and action-log review; owner-only delete. | 1,190 passed |
| 7 | `361abfe` Phase 8.1 — contract, security and hierarchy tests | 48-tool contract (provider payloads, persistence, raw results, completeness), architecture/source scans, two-user hierarchy test, `ai:redact-history` (dry run only). Also three fixes the contract surfaced: `recommend_next_step` risk-title names, `get_role_dna` budget figures, `current_company` added to prohibited keys. | 1,303 passed |
| 8 | Phase 8.1 — documentation and final hardening | `docs/phase-8-1-ai-data-boundary.md`, `docs/phase-8-1-security-review.md`, this plan, backlog updates (P7-BACKLOG-006 and TD-003 closed with evidence; Phase 8.1 and discovery items recorded), `.ai/rules` updates. | — |

## Migrations (all additive)

- `2026_09_26_070744_add_privacy_version_to_ai_conversations_table`
- `2026_09_26_071502_add_privacy_declaration_to_ai_documents_table`
- `2026_09_26_072309_grant_phase_eight_one_permissions` (data: grants `ai.conversations.view`)

## Release notes for deploy

1. Run `php artisan migrate --force`. Existing conversations become legacy (read-only).
2. Tell administrators about two changes:
   - Knowledge-base documents uploaded before 8.1 are not used by the AI until they are declared, via the "Declare no personal data" action.
   - Reviewing other users' AI conversations now needs `ai.conversations.view`, which is granted to VP HR and CHRO and scoped to the reviewer's hierarchy.
3. Keep `AI_PRIVACY_EGRESS_MODE` unset (redact) in production.
4. Optionally run `php artisan ai:redact-history --dry-run` to size the pre-8.1 history. It changes nothing; redaction needs separate approval.
