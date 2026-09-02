<?php

namespace App\Services\AI\Tools\RecruiterTools;

use App\Enums\AiRiskLevel;
use App\Models\Employee;
use App\Models\RecruitmentDailyActivity;
use App\Models\User;
use App\Services\AI\DTO\ToolResult;
use App\Services\AI\Tools\Concerns\ScopesToHierarchy;
use App\Services\AI\Tools\Contracts\AiTool;
use Illuminate\Database\Eloquent\Builder;

class FindInactiveRecruitersTool implements AiTool
{
    use ScopesToHierarchy;

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

        $recruiters = Employee::query()
            ->whereNotIn('id', $activeRecruiterIds)
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereIn('id', $visibleIds))
            ->where('status', 'active')
            ->get(['id', 'first_name', 'last_name', 'department_id']);

        $rows = $recruiters->map(fn (Employee $e) => ['employee_id' => $e->id, 'name' => $e->fullName()]);

        return ToolResult::ok(
            data: ['inactive_recruiters' => $rows->toArray(), 'days' => $days],
            summary: "{$rows->count()} recruiter(s) with no logged activity in the last {$days} day(s).",
            type: 'candidate_list',
        );
    }
}
