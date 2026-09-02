<?php

namespace App\Services\AI\Tools\RecruitmentTools;

use App\Enums\AiRiskLevel;
use App\Models\User;
use App\Services\AI\DTO\ToolResult;
use App\Services\AI\Tools\Contracts\AiTool;
use App\Services\RecruitmentAnalyticsService;
use Carbon\CarbonImmutable;

class AnalyzeSourcesTool implements AiTool
{
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
        $end = filled($arguments['end_date'] ?? null) ? CarbonImmutable::parse($arguments['end_date']) : CarbonImmutable::now();
        $start = filled($arguments['start_date'] ?? null) ? CarbonImmutable::parse($arguments['start_date']) : $end->subDays(90);

        $rows = $this->analytics->sourceAnalytics($start, $end)->map(fn (array $row) => [
            'source' => $row['source']->name,
            'sourced' => $row['sourced'],
            'interviewed' => $row['interviewed'],
            'selected' => $row['selected'],
            'joined' => $row['joined'],
            'source_to_joined_rate' => $row['sourced'] > 0 ? round($row['joined'] / $row['sourced'] * 100, 1) : null,
        ]);

        return ToolResult::ok(
            data: ['sources' => $rows->toArray(), 'start_date' => $start->toDateString(), 'end_date' => $end->toDateString()],
            summary: "Source performance for {$start->toDateString()} to {$end->toDateString()}.",
            type: 'comparison_table',
        );
    }
}
