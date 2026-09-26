---
paths:
  - 'app/Services/AI/Tools/IntelligenceTools/**'
---

# Intelligence Tools

## Intelligence Copilot tools identify people by code, never by name or contact details
Tool results are sent to the external LLM. EDGE Intelligence tools return candidate_code / application_code only — never full_name, email, mobile, address or government IDs (regression-tested in IntelligenceUiTest). Older Copilot tools outside this folder still return names; that is tracked as backlog P7-BACKLOG-006, not a pattern to copy.
