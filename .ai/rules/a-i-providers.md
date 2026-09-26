---
paths:
  - 'app/Services/AI/Providers/*.php'
---

# A I Providers

## structured() throws on provider failure; empty array only means unconfigured
Since Phase 7, GeminiProvider/OpenAiProvider::structured() throw AiProviderUnavailableException on an HTTP error (e.g. Gemini 503 "overloaded", which happens intermittently) or unparseable JSON, so AiGateway logs the call as an error in ai_usage_logs instead of "success" and callers can retry. Only NullProvider returns []. Background AI jobs (e.g. GenerateRoleDnaSuggestionsJob) rethrow while attempts remain so the queue retries, and mark Failed on the last attempt.
