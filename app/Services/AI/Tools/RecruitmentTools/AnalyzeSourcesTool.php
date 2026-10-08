<?php

namespace App\Services\AI\Tools\RecruitmentTools;

use App\Enums\AiRiskLevel;
use App\Models\User;
use App\Services\AI\DTO\ToolResult;
use App\Services\AI\Tools\Concerns\ResolvesMetricPeriod;
use App\Services\AI\Tools\Contracts\AiTool;
use App\Services\RecruitmentAnalyticsService;

class AnalyzeSourcesTool implements AiTool
{
    use ResolvesMetricPeriod;

    public function __construct(private readonly RecruitmentAnalyticsService $analytics) {}

    public function name(): string
    {
        return 'analyze_sources';
    }

    public function description(): string
    {
        return 'Per-source funnel (Sourced -> Interviewed -> Selected -> Joined) to identify the best-performing sourcing channels, for a date range (default: last 90 days).';
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
        return 'performance.view';
    }

    public function handle(array $arguments, User $user): ToolResult
    {
        $period = $this->metricPeriod($arguments, 90);

        $rows = $this->analytics->sourceAnalytics($period->from, $period->lastInstant(), $user)->map(fn (array $row) => [
            'source' => $row['source_name'],
            'applications' => $row['sourced'],
            'interviewed' => $row['interviewed'],
            'selected' => $row['selected'],
            'joined' => $row['joined'],
            'application_to_joined_rate' => $row['conversion_percent'],
        ]);

        return ToolResult::ok(
            data: ['sources' => $rows->toArray(), 'start_date' => $period->fromDate(), 'end_date' => $period->toDate()],
            summary: "Source performance for applications created {$period->fromDate()} to {$period->toDate()}.",
            type: 'comparison_table',
        );
    }
}
