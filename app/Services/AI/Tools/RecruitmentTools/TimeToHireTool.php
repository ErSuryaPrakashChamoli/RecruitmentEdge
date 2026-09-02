<?php

namespace App\Services\AI\Tools\RecruitmentTools;

use App\Enums\AiRiskLevel;
use App\Models\User;
use App\Services\AI\DTO\ToolResult;
use App\Services\AI\Tools\Contracts\AiTool;
use App\Services\CostPerHireService;
use App\Services\RecruitmentAnalyticsService;
use Carbon\CarbonImmutable;

class TimeToHireTool implements AiTool
{
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
        return 'Average time-to-hire (days) and cost-per-hire for joins in a date range (default: last 90 days), optionally scoped to one department.';
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
        $end = filled($arguments['end_date'] ?? null) ? CarbonImmutable::parse($arguments['end_date']) : CarbonImmutable::now();
        $start = filled($arguments['start_date'] ?? null) ? CarbonImmutable::parse($arguments['start_date']) : $end->subDays(90);
        $departmentId = $arguments['department_id'] ?? null;

        $avgDays = $this->analytics->averageTimeToHireDays($start, $end, $user);
        $costPerHire = $this->cost->costPerHire($start, $end, null, $departmentId);
        $joins = $this->cost->successfulJoins($start, $end, null, $departmentId);

        return ToolResult::ok(
            data: [
                'average_time_to_hire_days' => $avgDays,
                'cost_per_hire' => $costPerHire,
                'successful_joins' => $joins,
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
            ],
            summary: $avgDays !== null
                ? "Average time-to-hire is {$avgDays} days over {$joins} join(s)."
                : 'No joins in this period to compute time-to-hire.',
            type: 'kpi_card',
        );
    }
}
