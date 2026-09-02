<?php

namespace App\Services\AI\Tools\ResearchTools;

use App\Enums\AiRiskLevel;
use App\Models\User;
use App\Services\AI\DTO\ToolResult;
use App\Services\AI\Gateway\AiGateway;
use App\Services\AI\Tools\Contracts\AiTool;

/**
 * External/current market research (salary benchmarks, hiring trends, skills demand, ...). The
 * schema is deliberately shaped around market-level parameters — role/skill/location/topic — never
 * a free-form dump of candidate PII, so nothing personally identifying ever leaves the app via a
 * search query (spec sections 20/45).
 */
class WebResearchTool implements AiTool
{
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

        $results = $this->gateway->research($query, $user);

        return ToolResult::ok(
            data: ['results' => array_map(fn ($r) => $r->toArray(), $results)],
            summary: $results === []
                ? "No external sources found for \"{$query}\"."
                : 'Found '.count($results)." external source(s) for \"{$query}\".",
            type: 'source_list',
        );
    }
}
