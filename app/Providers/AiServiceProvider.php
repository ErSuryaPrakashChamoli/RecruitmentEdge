<?php

namespace App\Providers;

use App\Services\AI\Calendar\Contracts\CalendarProviderInterface;
use App\Services\AI\Calendar\Providers\NullCalendarProvider;
use App\Services\AI\Communication\Contracts\EmailProviderInterface;
use App\Services\AI\Communication\Contracts\SmsProviderInterface;
use App\Services\AI\Communication\Contracts\WhatsAppProviderInterface;
use App\Services\AI\Communication\Providers\MailEmailProvider;
use App\Services\AI\Communication\Providers\NullSmsProvider;
use App\Services\AI\Communication\Providers\NullWhatsAppProvider;
use App\Services\AI\Contracts\EmbeddingProviderInterface;
use App\Services\AI\Contracts\LLMProviderInterface;
use App\Services\AI\Contracts\WebSearchProviderInterface;
use App\Services\AI\Gateway\AiGateway;
use App\Services\AI\Gateway\AiProviderManager;
use App\Services\AI\Providers\GeminiProvider;
use App\Services\AI\Providers\NullProvider;
use App\Services\AI\Providers\OpenAiProvider;
use App\Services\AI\Rag\DocumentIngestionService;
use App\Services\AI\Rag\Parsers\DocxParser;
use App\Services\AI\Rag\Parsers\PdfParser;
use App\Services\AI\Rag\Parsers\PlainTextParser;
use App\Services\AI\Rag\Parsers\SpreadsheetParser;
use App\Services\AI\Tools\ToolRegistrar;
use App\Services\AI\Tools\ToolRegistry;
use Illuminate\Support\ServiceProvider;

/**
 * Wires the AI Recruitment Copilot's provider abstraction, tool registry, and rate limiters.
 * Provider SELECTION is deliberately not decided here — that's AiProviderManager's job, driven
 * entirely by config/ai.php (AI_PROVIDER / AI_EMBEDDING_PROVIDER / AI_WEB_SEARCH_PROVIDER). This
 * class's only job is registering each concrete provider's singleton (built from its own config
 * block) and pointing the three capability interfaces at the manager. No other class should new up
 * GeminiProvider/OpenAiProvider directly, and no `if ($provider === 'gemini')` branching should
 * exist anywhere outside this file and AiProviderManager.
 */
class AiServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(GeminiProvider::class, function () {
            $config = config('ai.providers.gemini');

            return new GeminiProvider(
                apiKey: $config['api_key'] ?? null,
                baseUrl: $config['base_url'] ?? 'https://generativelanguage.googleapis.com/v1beta',
            );
        });

        $this->app->singleton(OpenAiProvider::class, function () {
            $config = config('ai.providers.openai');

            return new OpenAiProvider(
                apiKey: $config['api_key'] ?? null,
                baseUrl: $config['base_url'] ?? 'https://api.openai.com/v1',
                organization: $config['organization'] ?? null,
            );
        });

        $this->app->singleton(NullProvider::class);
        $this->app->singleton(AiProviderManager::class);

        $this->app->bind(LLMProviderInterface::class, fn ($app) => $app->make(AiProviderManager::class)->llm());
        $this->app->bind(EmbeddingProviderInterface::class, fn ($app) => $app->make(AiProviderManager::class)->embeddings());
        $this->app->bind(WebSearchProviderInterface::class, fn ($app) => $app->make(AiProviderManager::class)->webSearch());

        $this->app->bind(EmailProviderInterface::class, MailEmailProvider::class);
        $this->app->bind(WhatsAppProviderInterface::class, NullWhatsAppProvider::class);
        $this->app->bind(SmsProviderInterface::class, NullSmsProvider::class);
        $this->app->bind(CalendarProviderInterface::class, NullCalendarProvider::class);

        $this->app->tag([PdfParser::class, DocxParser::class, SpreadsheetParser::class, PlainTextParser::class], 'ai.document_parsers');

        $this->app->singleton(DocumentIngestionService::class, fn ($app) => new DocumentIngestionService(
            $app->make(AiGateway::class),
            $app->tagged('ai.document_parsers'),
        ));

        $this->app->singleton(ToolRegistry::class, function () {
            $registry = new ToolRegistry;
            ToolRegistrar::registerAll($registry);

            return $registry;
        });
    }

    public function boot(): void
    {
        //
    }
}
