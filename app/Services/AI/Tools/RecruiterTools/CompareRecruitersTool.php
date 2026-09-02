<?php

namespace App\Services\AI\Tools\RecruiterTools;

use App\Enums\AiRiskLevel;
use App\Models\Employee;
use App\Models\User;
use App\Services\AI\DTO\ToolResult;
use App\Services\AI\Tools\Contracts\AiTool;
use App\Services\HierarchyService;
use App\Services\PerformanceEngine;
use Carbon\CarbonImmutable;

class CompareRecruitersTool implements AiTool
{
    public function __construct(
        private readonly PerformanceEngine $performance,
        private readonly HierarchyService $hierarchy,
    ) {}

    public function name(): string
    {
        return 'compare_recruiters';
    }

    public function description(): string
    {
        return 'Compare composite performance scores for 2-8 recruiters over the same date range (default: current month).';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'employee_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'minItems' => 2, 'maxItems' => 8],
                'start_date' => ['type' => 'string'],
                'end_date' => ['type' => 'string'],
            ],
            'required' => ['employee_ids'],
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
        $start = filled($arguments['start_date'] ?? null) ? CarbonImmutable::parse($arguments['start_date']) : $end->startOfMonth();

        $ids = array_slice(array_map('intval', $arguments['employee_ids'] ?? []), 0, 8);

        $rows = Employee::query()->whereIn('id', $ids)->get()
            ->filter(fn (Employee $e) => $this->hierarchy->canView($user, $e))
            ->map(function (Employee $recruiter) use ($start, $end) {
                $result = $this->performance->computeFor($recruiter, $start, $end);

                return [
                    'employee_id' => $recruiter->id,
                    'name' => $recruiter->fullName(),
                    'score' => $result['score'],
                ];
            })
            ->sortByDesc('score')
            ->values();

        if ($rows->count() < 2) {
            return ToolResult::fail('At least two visible recruiters are required to compare.');
        }

        return ToolResult::ok(
            data: ['comparison' => $rows->toArray(), 'start_date' => $start->toDateString(), 'end_date' => $end->toDateString()],
            summary: "Compared {$rows->count()} recruiters' performance scores.",
            type: 'comparison_table',
        );
    }
}
