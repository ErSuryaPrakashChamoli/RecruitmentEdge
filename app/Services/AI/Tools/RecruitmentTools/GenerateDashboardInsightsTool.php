<?php

namespace App\Services\AI\Tools\RecruitmentTools;

use App\Enums\AiRiskLevel;
use App\Models\User;
use App\Services\AI\DTO\ToolResult;
use App\Services\AI\Tools\Contracts\AiTool;
use App\Services\RecruitmentInsightsService;
use Carbon\CarbonImmutable;

/**
 * Chat-callable wrapper over RecruitmentInsightsService, so "What should I work on today?" works
 * from AiCopilot the same way the dashboard's own "Generate Insights" button does — one
 * implementation, two entry points.
 */
class GenerateDashboardInsightsTool implements AiTool
{
    public function __construct(private readonly RecruitmentInsightsService $insights) {}

    public function name(): string
    {
        return 'generate_dashboard_insights';
    }

    public function description(): string
    {
        return 'Summarize this recruiter\'s current recruitment funnel, turn-up ratio, positions at risk, pending work, and alerts, with prioritized recommendations for what to work on next (default period: last 7 days).';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'start_date' => ['type' => 'string'],
                'end_date' => ['type' => 'string'],
            ],
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
        $end = filled($arguments['end_date'] ?? null) ? CarbonImmutable::parse($arguments['end_date']) : CarbonImmutable::now();
        $start = filled($arguments['start_date'] ?? null) ? CarbonImmutable::parse($arguments['start_date']) : $end->subDays(7);

        $result = $this->insights->generate($user->employee, $user, $start, $end);

        if (! $result['configured']) {
            return ToolResult::ok(
                data: ['facts' => $result['facts']],
                summary: 'AI narration is not configured; showing database facts only.',
                type: 'insights',
            );
        }

        return ToolResult::ok(
            data: ['facts' => $result['facts'], 'narrative' => $result['narrative']],
            summary: $result['narrative'],
            type: 'insights',
        );
    }
}
