---
paths:
  - 'app/Services/AI/Tools/**'
---

# Tools

## New AI tools: use CallsLanguageModel and ScopesToHierarchy helpers
Tools that call the model must use the CallsLanguageModel trait so a missing/unconfigured provider returns a graceful failure ToolResult instead of throwing (the no-key path answers via KnowledgeBaseFallback → AiAssistantService). Every record lookup in a tool — including by explicit candidate/requisition id — must go through the ScopesToHierarchy helpers; never load a candidate or run company-wide analytics without passing the user. Write/external tools are hidden from users lacking ai.actions.execute and ActionExecutor::approve re-checks the tool's own permission.

## AI tools build records with AiProjector; every tool needs a privacy contract case
Tool output is persisted and replayed to the external provider. Never toArray()/getAttributes() a model or read email, mobile, salaries, CTC, remarks, full_name or current_company for output — use the ProjectsForAi trait ($this->projector()), which returns codes (CAND-/APP-/EMP-/REQ-/OFR-, INT-/JOIN-/FUP-/RISK-{id}) and compensation_fit instead of figures, and registers people so free text naming them is scrubbed. Resolve the projector at call time (tools live in a singleton registry; its value register is request-scoped). Messaging tools take an entity id, never a destination — the application resolves the address at send time. Adding a tool: add a case to tests/Feature/Ai/Privacy/ToolPayloadContractTest.php (a completeness check fails otherwise); AiBoundaryArchitectureTest enforces the rest.
