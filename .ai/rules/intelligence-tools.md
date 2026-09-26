---
paths:
  - 'app/Services/AI/Tools/IntelligenceTools/**'
---

# Intelligence Tools

## Intelligence Copilot tools identify people by code, never by name or contact details
Tool results are sent to the external LLM. EDGE Intelligence tools return reference codes only — never full_name, email, mobile, address or government IDs. Since Phase 8.1 this applies to every Copilot tool (P7-BACKLOG-006 closed): build records with AiProjector (see .ai/rules/tools.md). Stored texts such as HiringRisk titles can name people — project them (AiProjector::hiringRisk), never forward them.
