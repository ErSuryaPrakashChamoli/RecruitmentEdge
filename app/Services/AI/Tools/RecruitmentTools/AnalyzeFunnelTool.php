<?php

namespace App\Services\AI\Tools\RecruitmentTools;

use App\Enums\AiRiskLevel;
use App\Models\User;
use App\Services\AI\DTO\ToolResult;
use App\Services\AI\Tools\Contracts\AiTool;
use App\Services\RecruitmentAnalyticsService;
use Carbon\CarbonImmutable;

class AnalyzeFunnelTool implements AiTool
{
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
        $end = filled($arguments['end_date'] ?? null) ? CarbonImmutable::parse($arguments['end_date']) : CarbonImmutable::now();
        $start = filled($arguments['start_date'] ?? null) ? CarbonImmutable::parse($arguments['start_date']) : $end->subDays(30);

        $funnel = $this->analytics->funnel($start, $end, $user)->map(fn (array $row) => [
            'stage' => $row['stage']->label(),
            'count' => $row['count'],
            'conversion_from_sourced' => $row['conversion_from_sourced'],
        ]);

        return ToolResult::ok(
            data: ['funnel' => $funnel->toArray(), 'start_date' => $start->toDateString(), 'end_date' => $end->toDateString()],
            summary: "Funnel for {$start->toDateString()} to {$end->toDateString()}.",
            type: 'funnel_chart',
        );
    }
}
