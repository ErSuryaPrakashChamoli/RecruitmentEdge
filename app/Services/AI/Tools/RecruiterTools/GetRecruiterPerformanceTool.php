<?php

namespace App\Services\AI\Tools\RecruiterTools;

use App\Enums\AiRiskLevel;
use App\Models\Employee;
use App\Models\User;
use App\Services\AI\DTO\ToolResult;
use App\Services\AI\Tools\Contracts\AiTool;
use App\Services\HierarchyService;
use App\Services\PerformanceEngine;
use App\Services\RecruiterDailyMetricsService;
use Carbon\CarbonImmutable;

class GetRecruiterPerformanceTool implements AiTool
{
    public function __construct(
        private readonly PerformanceEngine $performance,
        private readonly RecruiterDailyMetricsService $metrics,
        private readonly HierarchyService $hierarchy,
    ) {}

    public function name(): string
    {
        return 'get_recruiter_performance';
    }

    public function description(): string
    {
        return 'A recruiter\'s composite performance score and per-metric target/actual/achievement breakdown for a date range (default: current month).';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'employee_id' => ['type' => 'integer'],
                'start_date' => ['type' => 'string'],
                'end_date' => ['type' => 'string'],
            ],
            'required' => ['employee_id'],
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
        $recruiter = Employee::query()->find($arguments['employee_id'] ?? null);

        if ($recruiter === null || ! $this->hierarchy->canView($user, $recruiter)) {
            return ToolResult::fail('Recruiter not found, or not visible to you.');
        }

        $end = filled($arguments['end_date'] ?? null) ? CarbonImmutable::parse($arguments['end_date']) : CarbonImmutable::now();
        $start = filled($arguments['start_date'] ?? null) ? CarbonImmutable::parse($arguments['start_date']) : $end->startOfMonth();

        $performance = $this->performance->computeFor($recruiter, $start, $end);
        $accountability = $this->metrics->accountabilityFor($recruiter, $start, $end)->map(fn (array $row) => [
            'metric' => $row['metric']->value,
            'target' => $row['target'],
            'actual' => $row['actual'],
            'achievement' => $row['achievement'],
            'gap' => $row['gap'],
        ]);

        return ToolResult::ok(
            data: [
                'recruiter' => $recruiter->fullName(),
                'score' => $performance['score'],
                'breakdown' => $performance['breakdown'],
                'accountability' => $accountability->toArray(),
                'start_date' => $start->toDateString(),
                'end_date' => $end->toDateString(),
            ],
            summary: $performance['score'] !== null
                ? "{$recruiter->fullName()}'s composite performance score is {$performance['score']}."
                : "No scored metrics found for {$recruiter->fullName()} in this range.",
            type: 'kpi_card',
        );
    }
}
