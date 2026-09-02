<?php

namespace App\Services\AI\Tools\CandidateTools;

use App\Enums\AiRiskLevel;
use App\Models\CandidateApplication;
use App\Models\User;
use App\Services\AI\DTO\ToolResult;
use App\Services\AI\Tools\Concerns\ScopesToHierarchy;
use App\Services\AI\Tools\Contracts\AiTool;
use Illuminate\Database\Eloquent\Builder;

class GetCandidateTimelineTool implements AiTool
{
    use ScopesToHierarchy;

    public function name(): string
    {
        return 'get_candidate_timeline';
    }

    public function description(): string
    {
        return "A candidate application's full stage-change history in order, from CandidateStageHistory — the authoritative journey log.";
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['application_id' => ['type' => 'integer']],
            'required' => ['application_id'],
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
        $visibleIds = $this->visibleEmployeeIds($user);

        $application = CandidateApplication::query()
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereIn('recruiter_id', $visibleIds))
            ->with(['candidate:id,full_name', 'stageHistory.changedBy:id,first_name,last_name'])
            ->find($arguments['application_id'] ?? null);

        if ($application === null) {
            return ToolResult::fail('Application not found, or not visible to you.');
        }

        $timeline = $application->stageHistory->sortBy('created_at')->map(fn ($h) => [
            'from' => $h->previous_stage?->label(),
            'to' => $h->new_stage->label(),
            'changed_by' => $h->changedBy?->fullName(),
            'remarks' => $h->remarks,
            'at' => $h->created_at->toIso8601String(),
        ]);

        return ToolResult::ok(
            data: ['candidate' => $application->candidate?->full_name, 'timeline' => $timeline->values()->toArray()],
            summary: 'Loaded '.$timeline->count()." stage change(s) for {$application->candidate?->full_name}.",
            type: 'timeline',
        );
    }
}
