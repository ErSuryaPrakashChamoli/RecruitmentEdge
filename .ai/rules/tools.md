---
paths:
  - 'app/Services/AI/Tools/**'
---

# Tools

## New AI tools: use CallsLanguageModel and ScopesToHierarchy helpers
Tools that call the model must use the CallsLanguageModel trait so a missing/unconfigured provider returns a graceful failure ToolResult instead of throwing (the no-key path answers via KnowledgeBaseFallback → AiAssistantService). Every record lookup in a tool — including by explicit candidate/requisition id — must go through the ScopesToHierarchy helpers; never load a candidate or run company-wide analytics without passing the user. Write/external tools are hidden from users lacking ai.actions.execute and ActionExecutor::approve re-checks the tool's own permission.
