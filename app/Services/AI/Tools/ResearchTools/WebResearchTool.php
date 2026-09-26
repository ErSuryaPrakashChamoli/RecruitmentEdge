<?php

namespace App\Services\AI\Tools\ResearchTools;

use App\Enums\AiRiskLevel;
use App\Models\User;
use App\Services\AI\DTO\ToolResult;
use App\Services\AI\Gateway\AiGateway;
use App\Services\AI\Privacy\AiPayloadSanitizer;
use App\Services\AI\Privacy\AiReference;
use App\Services\AI\Tools\Concerns\ProjectsForAi;
use App\Services\AI\Tools\Contracts\AiTool;

/**
 * External/current market research (salary benchmarks, hiring trends, skills demand, ...). The
 * schema is deliberately shaped around market-level parameters — role/skill/location/topic — never
 * a free-form dump of candidate PII, so nothing personally identifying ever leaves the app via a
 * search query (spec sections 20/45).
 */
class WebResearchTool implements AiTool
{
    use ProjectsForAi;

    public function __construct(private readonly AiGateway $gateway) {}

    public function name(): string
    {
        return 'web_research';
    }

    public function description(): string
    {
        return 'Research current external information: salary benchmarks, hiring/market trends, skills demand, competitor hiring activity, or recruitment best practices. Only for questions that need up-to-date information beyond internal data.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'topic' => ['type' => 'string', 'description' => 'What to research, e.g. "salary range", "hiring trends", "skills in demand"'],
                'role' => ['type' => 'string'],
                'location' => ['type' => 'string'],
            ],
            'required' => ['topic'],
        ];
    }

    public function riskLevel(): AiRiskLevel
    {
        return AiRiskLevel::Read;
    }

    public function permission(): ?string
    {
        return 'ai.query';
    }

    public function handle(array $arguments, User $user): ToolResult
    {
        if (! config('ai.features.web_search_enabled')) {
            return ToolResult::fail('Web research is not enabled in this environment. Ask an administrator to set AI_WEB_SEARCH_ENABLED=true.');
        }

        $query = trim(collect([$arguments['topic'] ?? null, $arguments['role'] ?? null, $arguments['location'] ?? null, (string) now()->year])->filter()->implode(' '));

        // Phase 8.1: an external search query may only describe the market — never a person,
        // internal record or contact detail. Anything else is refused before it leaves the app.
        if (! $this->isMarketLevelQuery($query)) {
            return ToolResult::fail('Web research only accepts market-level topics (role, skills, location, salary benchmarks) — not names, internal references or contact details.');
        }

        $results = $this->gateway->research($query, $user);

        return ToolResult::ok(
            data: ['results' => array_map(fn ($r) => $r->toArray(), $results)],
            summary: $results === []
                ? 'No external sources found.'
                : 'Found '.count($results).' external source(s).',
            type: 'source_list',
        );
    }

    private function isMarketLevelQuery(string $query): bool
    {
        if (mb_strlen($query) > (int) config('ai.privacy.research_query_max_chars', 120)) {
            return false;
        }

        if (preg_match(AiReference::PATTERN, $query) === 1) {
            return false;
        }

        return app(AiPayloadSanitizer::class)->sanitizeText($query)['counts'] === [];
    }
}
