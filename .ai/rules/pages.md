---
paths:
  - 'app/Services/AiAssistantService.php,app/Filament/Pages/AiCopilot.php'
---

# Pages

## AiAssistantService is now the no-provider fallback, not the whole AI feature
`AiAssistantService::ask()/search()` still does keyword `LIKE` matching over published `AiKnowledgeArticle` rows and logs to `ai_query_logs` — but it is no longer the AI module itself. It survives as the deterministic fallback `SearchKnowledgeBaseTool` falls back to when RAG is disabled or no embedding provider is configured (see `app/Services/AI/Tools/KnowledgeTools/SearchKnowledgeBaseTool.php`). Don't remove it or its logging: it's load-bearing for the "AI still works with no API key" guarantee.

## The real AI Copilot lives under app/Services/AI/**
The placeholder `AskAi` page described in earlier project notes has been replaced by `app/Filament/Pages/AiCopilot.php`, backed by a full provider-agnostic AI layer: `AiGateway`/`AiOrchestrator`/`ToolRegistry`/`ActionExecutor` under `app/Services/AI/`, ~35 tools grouped by domain (`app/Services/AI/Tools/*Tools/`), RAG via `app/Services/AI/Rag/` (brute-force cosine similarity over `ai_document_chunks` — deliberate, matches this app's DB engine/scale), and permission-gated write/external/high-impact actions that always stop for human approval (`AiToolCall` status `pending`, gated by the `ai.actions.execute` permission). Add new tools by creating an `AiTool` implementation and listing it in `App\Services\AI\Tools\ToolRegistrar::CLASSES` — the registry/orchestrator/UI pick it up automatically.

## Provider selection is centralized — see .ai/rules/providers.md
`config('ai.provider')` defaults to `gemini` (dev/test phase default); `OPENAI_API_KEY` also works as a fully-implemented peer provider, not a fallback. `App\Services\AI\Gateway\AiProviderManager` is the only class that resolves which concrete provider (`GeminiProvider`/`OpenAiProvider`) backs each of the three capability interfaces, independently per capability (`AI_PROVIDER` / `AI_EMBEDDING_PROVIDER` / `AI_WEB_SEARCH_PROVIDER`). No credential configured for the selected provider → falls back to `NullProvider` automatically, so the Copilot degrades to a clear "not configured" message rather than erroring, and the rest of the app is unaffected. See `.ai/rules/providers.md` for the exact steps to add a new provider — never branch on a provider name anywhere outside `AiProviderManager`/`AiServiceProvider`.
