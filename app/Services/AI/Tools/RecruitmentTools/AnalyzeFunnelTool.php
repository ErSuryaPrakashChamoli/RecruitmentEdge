<?php

namespace App\Services\AI\Tools\RecruitmentTools;

use App\Enums\AiRiskLevel;
use App\Models\User;
use App\Services\AI\DTO\ToolResult;
use App\Services\AI\Tools\Concerns\ResolvesMetricPeriod;
use App\Services\AI\Tools\Contracts\AiTool;
use App\Services\RecruitmentAnalyticsService;

class AnalyzeFunnelTool implements AiTool
{
    use ResolvesMetricPeriod;

    public function __construct(private readonly RecruitmentAnalyticsService $analytics) {}

    public function name(): string
    {
        return 'analyze_funnel';
    }

    public function description(): string
    {
        return 'Recruitment funnel (Sourced -> ... -> Onboarding Completed) with counts and conversion % from Sourced, for a date range (default: last 30 days).';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'start_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD, default 30 days ago'],
                'end_date' => ['type' => 'string', 'description' => 'YYYY-MM-DD, default today'],
            ],
        ];
    }

    public function riskLevel(): AiRiskLevel
    {
        return AiRiskLevel::Read;
    }

    public function permission(): ?string
    {
        return 'performance.view';
    }

    public function handle(array $arguments, User $user): ToolResult
    {
        $period = $this->metricPeriod($arguments, 30);

        // Phase 8.5: the cohort funnel (pipeline.funnel) — the dashboard's funnel.
        $funnel = $this->analytics->funnel($period->from, $period->lastInstant(), $user)->map(fn (array $row) => [
            'stage' => $row['stage']->label(),
            'reached' => $row['count'],
            'percent_of_applications' => $row['conversion_from_sourced'],
        ]);

        return ToolResult::ok(
            data: [
                'funnel' => $funnel->toArray(),
                'basis' => 'Applications created in the range, and how far each has got by now.',
                'start_date' => $period->fromDate(),
                'end_date' => $period->toDate(),
            ],
            summary: "Funnel of applications created {$period->fromDate()} to {$period->toDate()}.",
            type: 'funnel_chart',
        );
    }
}
