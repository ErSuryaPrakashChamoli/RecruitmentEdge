<?php

namespace App\Services\AI\Tools\RecruiterTools;

use App\Enums\AiRiskLevel;
use App\Models\Employee;
use App\Models\RecruitmentDailyActivity;
use App\Models\User;
use App\Services\AI\DTO\ToolResult;
use App\Services\AI\Tools\Concerns\ProjectsForAi;
use App\Services\AI\Tools\Concerns\ScopesToHierarchy;
use App\Services\AI\Tools\Contracts\AiTool;
use App\Services\PerformanceEngine;

class FindInactiveRecruitersTool implements AiTool
{
    use ProjectsForAi, ScopesToHierarchy;

    public function name(): string
    {
        return 'find_inactive_recruiters';
    }

    public function description(): string
    {
        return 'Find recruiters with no logged activity (calls, screenings, etc.) in the last N days (default 3), within the current user\'s hierarchy.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['days' => ['type' => 'integer', 'description' => 'default 3']],
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
        $days = max(1, (int) ($arguments['days'] ?? 3));
        $since = now()->subDays($days);
        $visibleIds = $this->visibleEmployeeIds($user);

        $activeRecruiterIds = RecruitmentDailyActivity::query()
            ->where('activity_datetime', '>=', $since)
            ->distinct()
            ->pluck('recruiter_id');

        // Phase 8.5 (DF-2): the recruiter population is the one the Performance Engine uses — active
        // employees who own applications — never every active employee in scope.
        $recruiters = app(PerformanceEngine::class)->activeRecruitersQuery($visibleIds)
            ->whereNotIn('id', $activeRecruiterIds)
            ->get();

        $rows = $recruiters->map(fn (Employee $e) => ['employee_id' => $e->id, 'employee_ref' => $this->projector()->employeeRef($e)]);

        return ToolResult::ok(
            data: ['inactive_recruiters' => $rows->toArray(), 'days' => $days],
            summary: "{$rows->count()} recruiter(s) with no logged activity in the last {$days} day(s).",
            type: 'candidate_list',
        );
    }
}
