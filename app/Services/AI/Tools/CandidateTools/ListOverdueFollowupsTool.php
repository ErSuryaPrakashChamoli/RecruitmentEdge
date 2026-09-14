<?php

namespace App\Services\AI\Tools\CandidateTools;

use App\Enums\AiRiskLevel;
use App\Enums\FollowupStatus;
use App\Models\RecruitmentFollowup;
use App\Models\User;
use App\Services\AI\DTO\ToolResult;
use App\Services\AI\Tools\Concerns\ScopesToHierarchy;
use App\Services\AI\Tools\Contracts\AiTool;

/**
 * "Overdue" = pending with a follow-up date in the past — the same definition as
 * RecruitmentFollowup::isOverdue() and RecruitmentActionCenterService's overdue count, scoped by
 * the follow-up's recruiter.
 */
class ListOverdueFollowupsTool implements AiTool
{
    use ScopesToHierarchy;

    public function name(): string
    {
        return 'list_overdue_followups';
    }

    public function description(): string
    {
        return 'List pending follow-ups whose due date has passed, oldest first, with candidate, type, recruiter and how overdue each is.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'limit' => ['type' => 'integer', 'description' => 'Max results, default 25'],
            ],
        ];
    }

    public function riskLevel(): AiRiskLevel
    {
        return AiRiskLevel::Read;
    }

    public function permission(): ?string
    {
        return 'followups.manage';
    }

    public function handle(array $arguments, User $user): ToolResult
    {
        $limit = max(1, min((int) ($arguments['limit'] ?? 25), 50));

        $query = $this->scopeRecruiterOwnedTo(RecruitmentFollowup::query(), $user)
            ->where('status', FollowupStatus::Pending)
            ->where('followup_date', '<', now());

        $total = (clone $query)->count();

        $followups = $query
            ->with(['candidateApplication.candidate:id,full_name', 'recruiter:id,first_name,last_name'])
            ->orderBy('followup_date')
            ->limit($limit)
            ->get();

        $rows = $followups->map(fn (RecruitmentFollowup $followup) => [
            'followup_id' => $followup->id,
            'application_id' => $followup->candidate_application_id,
            'candidate' => $followup->candidateApplication?->candidate?->full_name,
            'type' => $followup->followup_type->label(),
            'due_at' => $followup->followup_date->toIso8601String(),
            'days_overdue' => (int) $followup->followup_date->diffInDays(now()),
            'recruiter' => $followup->recruiter?->fullName(),
            'remarks' => $followup->remarks,
        ]);

        return ToolResult::ok(
            data: ['overdue_followups' => $rows->toArray(), 'total_overdue' => $total],
            summary: "{$total} follow-up(s) overdue".($total > $rows->count() ? " (showing the oldest {$rows->count()})." : '.'),
            type: 'followup_list',
        );
    }
}
