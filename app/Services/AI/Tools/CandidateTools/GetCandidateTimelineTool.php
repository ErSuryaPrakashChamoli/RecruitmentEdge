<?php

namespace App\Services\AI\Tools\CandidateTools;

use App\Enums\AiRiskLevel;
use App\Models\CandidateApplication;
use App\Models\User;
use App\Services\AI\DTO\ToolResult;
use App\Services\AI\Tools\Concerns\ProjectsForAi;
use App\Services\AI\Tools\Concerns\ScopesToHierarchy;
use App\Services\AI\Tools\Contracts\AiTool;
use App\Services\CandidateTimelineService;
use Illuminate\Database\Eloquent\Builder;

class GetCandidateTimelineTool implements AiTool
{
    use ProjectsForAi, ScopesToHierarchy;

    public function __construct(private readonly CandidateTimelineService $timeline) {}

    public function name(): string
    {
        return 'get_candidate_timeline';
    }

    public function description(): string
    {
        return "A candidate application's unified timeline in order: stage changes, interviews, feedback, offers, calls/messages, follow-ups, notes, portal activity and referrals (CandidateTimelineService).";
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
            ->with('candidate')
            ->find($arguments['application_id'] ?? null);

        if ($application === null) {
            return ToolResult::fail('Application not found, or not visible to you.');
        }

        $candidateRef = $this->projector()->candidateRef($application->candidate);
        $timeline = $this->timeline->forApplication($application)->sortBy('at')->map(fn (array $entry) => $this->projector()->timelineEvent($entry));

        return ToolResult::ok(
            data: ['candidate_ref' => $candidateRef, 'application_ref' => $application->application_code, 'timeline' => $timeline->values()->toArray()],
            summary: 'Loaded '.$timeline->count()." timeline event(s) for {$candidateRef}.",
            type: 'timeline',
        );
    }
}
