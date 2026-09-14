<?php

namespace App\Services\AI\Tools\InterviewTools;

use App\Enums\AiRiskLevel;
use App\Enums\InterviewStatus;
use App\Models\Interview;
use App\Models\User;
use App\Services\AI\DTO\ToolResult;
use App\Services\AI\Tools\Concerns\ScopesToHierarchy;
use App\Services\AI\Tools\Contracts\AiTool;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Interviews are visible when the application's recruiter is in the caller's hierarchy — the same
 * scope RecruitmentAnalyticsService::scopedInterviews() uses.
 */
class SearchInterviewsTool implements AiTool
{
    use ScopesToHierarchy;

    public function name(): string
    {
        return 'search_interviews';
    }

    public function description(): string
    {
        return 'Search interviews by status, scheduled date range, and/or interviewer (id or name), scoped to what the current user may see.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'status' => ['type' => 'string', 'description' => 'One of: '.implode(', ', array_map(fn (InterviewStatus $status) => $status->value, InterviewStatus::cases()))],
                'start_date' => ['type' => 'string', 'description' => 'Scheduled on/after this date (YYYY-MM-DD)'],
                'end_date' => ['type' => 'string', 'description' => 'Scheduled on/before this date (YYYY-MM-DD)'],
                'interviewer_id' => ['type' => 'integer'],
                'interviewer_name' => ['type' => 'string'],
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
        return 'interviews.manage';
    }

    public function handle(array $arguments, User $user): ToolResult
    {
        $status = filled($arguments['status'] ?? null) ? InterviewStatus::tryFrom((string) $arguments['status']) : null;

        if (filled($arguments['status'] ?? null) && $status === null) {
            return ToolResult::fail('Unknown interview status.');
        }

        $limit = max(1, min((int) ($arguments['limit'] ?? 20), 50));
        $visibleIds = $this->visibleEmployeeIds($user);

        $interviews = Interview::query()
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereHas('candidateApplication', fn (Builder $a) => $a->whereIn('recruiter_id', $visibleIds)))
            ->when($status !== null, fn (Builder $q) => $q->where('status', $status))
            ->when(filled($arguments['start_date'] ?? null), fn (Builder $q) => $q->where('scheduled_at', '>=', CarbonImmutable::parse($arguments['start_date'])->startOfDay()))
            ->when(filled($arguments['end_date'] ?? null), fn (Builder $q) => $q->where('scheduled_at', '<=', CarbonImmutable::parse($arguments['end_date'])->endOfDay()))
            ->when(filled($arguments['interviewer_id'] ?? null), fn (Builder $q) => $q->where('interviewer_id', (int) $arguments['interviewer_id']))
            ->when(filled($arguments['interviewer_name'] ?? null), fn (Builder $q) => $q->whereHas('interviewer', function (Builder $interviewer) use ($arguments): void {
                $name = '%'.$arguments['interviewer_name'].'%';
                $interviewer->where('first_name', 'like', $name)->orWhere('last_name', 'like', $name);
            }))
            ->with(['candidateApplication.candidate:id,full_name', 'interviewer:id,first_name,last_name'])
            ->orderBy('scheduled_at')
            ->limit($limit)
            ->get();

        $rows = $interviews->map(fn (Interview $interview) => [
            'interview_id' => $interview->id,
            'application_id' => $interview->candidate_application_id,
            'candidate' => $interview->candidateApplication?->candidate?->full_name,
            'round_number' => $interview->round_number,
            'round_name' => $interview->round_name,
            'interviewer' => $interview->interviewer?->fullName(),
            'scheduled_at' => $interview->scheduled_at?->toIso8601String(),
            'mode' => $interview->mode?->value,
            'status' => $interview->status->label(),
            'result' => $interview->result?->label(),
        ]);

        return ToolResult::ok(
            data: ['interviews' => $rows->toArray()],
            summary: "Found {$rows->count()} interview(s).",
            type: 'interview_list',
        );
    }
}
