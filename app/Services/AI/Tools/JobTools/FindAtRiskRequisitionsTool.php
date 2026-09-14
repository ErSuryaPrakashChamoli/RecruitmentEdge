<?php

namespace App\Services\AI\Tools\JobTools;

use App\Enums\AiRiskLevel;
use App\Models\User;
use App\Services\AI\DTO\ToolResult;
use App\Services\AI\Tools\Contracts\AiTool;
use App\Services\RecruitmentAnalyticsService;

/**
 * Thin wrapper over RecruitmentAnalyticsService::vacancyAgeing() — never recomputes the ageing
 * threshold itself (spec section 18).
 */
class FindAtRiskRequisitionsTool implements AiTool
{
    public function __construct(private readonly RecruitmentAnalyticsService $analytics) {}

    public function name(): string
    {
        return 'find_at_risk_requisitions';
    }

    public function description(): string
    {
        return 'List open/on-hold requisitions that have crossed the configured vacancy ageing alert threshold, ordered by how overdue they are.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    public function riskLevel(): AiRiskLevel
    {
        return AiRiskLevel::Read;
    }

    public function permission(): ?string
    {
        return 'requisitions.viewAny';
    }

    public function handle(array $arguments, User $user): ToolResult
    {
        // includeWithinThreshold: vacancyAgeing() returns only overdue rows by default, which would
        // make the "X of Y open requisitions are overdue" summary always read X = Y.
        $rows = $this->analytics->vacancyAgeing($user, includeWithinThreshold: true)->map(fn (array $row) => [
            'id' => $row['requisition']->id,
            'code' => $row['requisition']->code,
            'designation' => $row['requisition']->designation?->name,
            'priority' => $row['priority'] ?? null,
            'ageing_days' => $row['ageing_days'],
            'is_overdue' => $row['is_overdue'],
        ]);

        $overdue = $rows->where('is_overdue', true);

        return ToolResult::ok(
            data: ['requisitions' => $rows->toArray()],
            summary: "{$overdue->count()} of {$rows->count()} open requisition(s) are overdue on vacancy ageing.",
            type: 'job_list',
        );
    }
}
