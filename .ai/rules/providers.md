---
paths:
  - 'app/Services/AI/Providers/*.php,app/Services/AI/Gateway/*.php,app/Providers/AiServiceProvider.php'
---

# Providers

## Adding a new AI provider: 3-step pattern, no branching elsewhere
Provider selection is centralized in App\Services\AI\Gateway\AiProviderManager — it resolves LLM/embedding/web-search independently via config('ai.provider') / config('ai.embeddings.provider') / config('ai.web_search.provider'), falling back to NullProvider via an `instanceof` check (not a capability-flag system) when a provider isn't configured or doesn't implement that capability's interface. To add a provider (e.g. Anthropic): (1) write AnthropicProvider implementing whichever of LLMProviderInterface/EmbeddingProviderInterface/WebSearchProviderInterface it actually supports, under app/Services/AI/Providers/; (2) add one `'anthropic' => $this->app->make(AnthropicProvider::class)` case to AiProviderManager::resolveNamed(); (3) register its singleton (built from config('ai.providers.anthropic')) in AiServiceProvider::register(), same pattern as GeminiProvider/OpenAiProvider. Never add `if ($provider === 'x')` branching anywhere else — AiGateway, AiOrchestrator, tools, and the UI only ever depend on the three interfaces. GEMINI_API_KEY is the default provider's credential (config('ai.provider') defaults to 'gemini'); OPENAI_API_KEY is a fully-implemented peer, not a fallback special-cased anywhere.
