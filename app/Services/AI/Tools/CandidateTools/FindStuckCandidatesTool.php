<?php

namespace App\Services\AI\Tools\CandidateTools;

use App\Enums\AiRiskLevel;
use App\Enums\ApplicationStatus;
use App\Models\CandidateApplication;
use App\Models\User;
use App\Services\AI\DTO\ToolResult;
use App\Services\AI\Tools\Concerns\ScopesToHierarchy;
use App\Services\AI\Tools\Contracts\AiTool;
use Illuminate\Database\Eloquent\Builder;

/**
 * "Stuck" = active application whose last_activity_at is older than the requested threshold —
 * reads the same last_activity_at column StageTransitionService maintains on every transition, so
 * this stays consistent with what the pipeline UI shows.
 */
class FindStuckCandidatesTool implements AiTool
{
    use ScopesToHierarchy;

    public function name(): string
    {
        return 'find_stuck_candidates';
    }

    public function description(): string
    {
        return 'Find active candidate applications with no stage movement/activity for more than N days (default 7).';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'days' => ['type' => 'integer', 'description' => 'Minimum days of inactivity, default 7'],
                'limit' => ['type' => 'integer', 'description' => 'Max results, default 20'],
            ],
        ];
    }

    public function riskLevel(): AiRiskLevel
    {
        return AiRiskLevel::Read;
    }

    public function permission(): ?string
    {
        return 'candidates.viewAny';
    }

    public function handle(array $arguments, User $user): ToolResult
    {
        $days = max(1, (int) ($arguments['days'] ?? 7));
        $limit = min((int) ($arguments['limit'] ?? 20), 50);
        $visibleIds = $this->visibleEmployeeIds($user);
        $threshold = now()->subDays($days);

        $applications = CandidateApplication::query()
            ->where('status', ApplicationStatus::Active)
            ->where('last_activity_at', '<=', $threshold)
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereIn('recruiter_id', $visibleIds))
            ->with(['candidate:id,full_name', 'requisition.designation', 'recruiter:id,first_name,last_name'])
            ->orderBy('last_activity_at')
            ->limit($limit)
            ->get();

        $rows = $applications->map(fn (CandidateApplication $app) => [
            'application_id' => $app->id,
            'candidate' => $app->candidate?->full_name,
            'stage' => $app->current_stage->label(),
            'recruiter' => $app->recruiter?->fullName(),
            'days_inactive' => (int) $app->last_activity_at->diffInDays(now()),
        ]);

        return ToolResult::ok(
            data: ['stuck_applications' => $rows->toArray()],
            summary: "Found {$rows->count()} application(s) stuck for {$days}+ days.",
            type: 'candidate_list',
        );
    }
}
