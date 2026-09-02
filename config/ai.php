<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Provider
    |--------------------------------------------------------------------------
    |
    | Which provider (below) backs each capability, resolved centrally by
    | App\Services\AI\Gateway\AiProviderManager — no other class should name a
    | vendor directly. The three capabilities are independent: you can run
    | chat on one provider and embeddings on another (e.g. AI_PROVIDER=gemini,
    | AI_EMBEDDING_PROVIDER=openai) simply by changing env vars. Gemini is the
    | default for this development/testing phase — no OpenAI key is required.
    |
    */

    'provider' => env('AI_PROVIDER', 'gemini'),

    /*
    |--------------------------------------------------------------------------
    | Provider Configuration
    |--------------------------------------------------------------------------
    |
    | Only the credential for the provider(s) actually selected above needs to
    | be set — an unconfigured provider simply isn't resolved (see
    | AiProviderManager), and the app falls back to NullProvider for that
    | capability rather than erroring.
    |
    */

    'providers' => [
        'gemini' => [
            'api_key' => env('GEMINI_API_KEY'),
            'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),
        ],

        'openai' => [
            'api_key' => env('OPENAI_API_KEY', env('AI_API_KEY')),
            'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
            'organization' => env('OPENAI_ORGANIZATION'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Model Routing
    |--------------------------------------------------------------------------
    |
    | Tools request a model by CATEGORY, never by hard-coded model id. These
    | values are interpreted by whichever provider AI_PROVIDER names — when
    | you switch AI_PROVIDER=gemini to AI_PROVIDER=openai, update these to
    | that provider's own model ids too; the application code never changes
    | either way.
    |
    | Defaults below were live-verified against this project's own Gemini API
    | key on 2026-08-28, not just docs: gemini-2.5-flash-lite/-flash/-pro all
    | now 404 with "no longer available to new users" — Google's own error
    | pointed to the replacements below. gemini-3.5-flash-lite and
    | gemini-3.6-flash responded successfully; gemini-3.1-pro-preview is a
    | real, valid model id but returned 429 RESOURCE_EXHAUSTED on this key's
    | free-tier quota — expected per spec ("don't assume unlimited usage"),
    | not a sign the id is wrong. If you're on a paid tier, or usage patterns
    | change, re-verify against ai.google.dev before assuming these are still
    | current — Google has deprecated a full model generation mid-project
    | once already.
    |
    */

    'models' => [
        'classification' => env('AI_MODEL_CLASSIFICATION', 'gemini-3.5-flash-lite'),
        'extraction' => env('AI_MODEL_EXTRACTION', 'gemini-3.5-flash-lite'),
        'summarization' => env('AI_MODEL_SUMMARIZATION', 'gemini-3.6-flash'),
        'generation' => env('AI_MODEL_GENERATION', 'gemini-3.6-flash'),
        'balanced' => env('AI_MODEL_BALANCED', 'gemini-3.6-flash'),
        'advanced' => env('AI_MODEL_ADVANCED', 'gemini-3.1-pro-preview'),
        'planning' => env('AI_MODEL_PLANNING', 'gemini-3.1-pro-preview'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Embeddings
    |--------------------------------------------------------------------------
    |
    | Independent of AI_PROVIDER — set AI_EMBEDDING_PROVIDER=openai here while
    | AI_PROVIDER=gemini above if you want chat on one vendor and embeddings
    | on another. gemini-embedding-001 is Google's current GA embedding model
    | (supports a configurable output dimensionality; 1536 is a safe default
    | that fits this app's existing storage).
    |
    */

    'embeddings' => [
        'provider' => env('AI_EMBEDDING_PROVIDER', 'gemini'),
        'model' => env('AI_EMBEDDING_MODEL', 'gemini-embedding-001'),
        'dimensions' => (int) env('AI_EMBEDDING_DIMENSIONS', 1536),
        'batch_size' => (int) env('AI_EMBEDDING_BATCH_SIZE', 50),
    ],

    /*
    |--------------------------------------------------------------------------
    | Web Search
    |--------------------------------------------------------------------------
    |
    | Also independently selectable — Gemini's built-in `google_search`
    | grounding tool is used by default when enabled.
    |
    */

    'web_search' => [
        'provider' => env('AI_WEB_SEARCH_PROVIDER', 'gemini'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Feature Flags
    |--------------------------------------------------------------------------
    */

    'features' => [
        'web_search_enabled' => (bool) env('AI_WEB_SEARCH_ENABLED', false),
        'rag_enabled' => (bool) env('AI_RAG_ENABLED', true),
        'actions_enabled' => (bool) env('AI_ACTIONS_ENABLED', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Limits & Rate Limiting
    |--------------------------------------------------------------------------
    */

    'limits' => [
        'max_tool_calls_per_turn' => (int) env('AI_MAX_TOOL_CALLS', 8),
        'max_tokens' => (int) env('AI_MAX_TOKENS', 4000),
        'rate_limit_per_minute' => (int) env('AI_RATE_LIMIT_PER_MINUTE', 20),
        'action_rate_limit_per_minute' => (int) env('AI_ACTION_RATE_LIMIT_PER_MINUTE', 10),
        'max_bulk_action_size' => (int) env('AI_MAX_BULK_ACTION_SIZE', 50),
    ],

    /*
    |--------------------------------------------------------------------------
    | RAG Chunking
    |--------------------------------------------------------------------------
    */

    'rag' => [
        'chunk_size_tokens' => (int) env('AI_RAG_CHUNK_SIZE', 500),
        'chunk_overlap_tokens' => (int) env('AI_RAG_CHUNK_OVERLAP', 50),
        'top_k' => (int) env('AI_RAG_TOP_K', 5),
        'min_similarity' => (float) env('AI_RAG_MIN_SIMILARITY', 0.15),
    ],

];
