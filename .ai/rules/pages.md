---
paths:
  - 'app/Services/AiAssistantService.php,app/Filament/Pages/AskAi.php'
---

# Pages

## AI module is local keyword search, not a live LLM — by design
AiAssistantService::ask()/search() match published AiKnowledgeArticle rows by keyword (LIKE on title/content) and log every query to ai_query_logs. This is deliberate, not a stopgap: no LLM provider API key is configured in this environment. To upgrade to a real LLM later, keep ask()'s signature and logging behavior intact and replace only the matching step with a provider call (e.g. via Http), still grounding the prompt in the same scoped article search so answers stay auditable and don't leak data outside the asker's hierarchy.
