<?php

namespace App\Services\AI\Tools\RecruitmentTools;

use App\Enums\AiRiskLevel;
use App\Models\User;
use App\Services\AI\DTO\ToolResult;
use App\Services\AI\Tools\Concerns\ResolvesMetricPeriod;
use App\Services\AI\Tools\Contracts\AiTool;
use App\Services\CostPerHireService;
use App\Services\RecruitmentAnalyticsService;

class TimeToHireTool implements AiTool
{
    use ResolvesMetricPeriod;

    public function __construct(
        private readonly RecruitmentAnalyticsService $analytics,
        private readonly CostPerHireService $cost,
    ) {}

    public function name(): string
    {
        return 'time_to_hire';
    }

    public function description(): string
    {
        return 'Median time to hire (days) and cost per hire for joins in a date range (default: last 90 days), optionally scoped to one department — the same governed figures as the dashboard.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'start_date' => ['type' => 'string'],
                'end_date' => ['type' => 'string'],
                'department_id' => ['type' => 'integer'],
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
        $departmentId = filled($arguments['department_id'] ?? null) ? (int) $arguments['department_id'] : null;

        // Phase 8.5: the governed metrics — the same numbers the dashboard and reports show.
        $timeToHire = $this->analytics->timeToHire($period->from, $period->lastInstant(), $user, $departmentId);
        $cost = $this->cost->result($period->from, $period->lastInstant(), null, $departmentId, user: $user);

        return ToolResult::ok(
            data: [
                'median_time_to_hire_days' => $timeToHire->isAvailable() ? $timeToHire->value : null,
                'mean_time_to_hire_days' => $timeToHire->detail('mean'),
                'hires_measured' => $timeToHire->sampleSize,
                'hires_with_unknown_start' => $timeToHire->unknownCount,
                'time_to_hire_status' => $timeToHire->status->value,
                'cost_per_hire' => $cost->isAvailable() ? $cost->value : null,
                'cost_per_hire_hires' => (int) $cost->detail('hires', 0),
                'successful_joins' => (int) $timeToHire->detail('hires', 0),
                'metrics' => ['time_to_hire' => "{$timeToHire->key} v{$timeToHire->version}", 'cost_per_hire' => "{$cost->key} v{$cost->version}"],
                'start_date' => $period->fromDate(),
                'end_date' => $period->toDate(),
            ],
            summary: $timeToHire->isAvailable()
                ? "Median time to hire is {$timeToHire->value} days over {$timeToHire->sampleSize} measured hire(s)."
                : "Time to hire is not available for this period ({$timeToHire->status->label()}; {$timeToHire->sampleSize} measured hire(s)).",
            type: 'kpi_card',
        );
    }
}
