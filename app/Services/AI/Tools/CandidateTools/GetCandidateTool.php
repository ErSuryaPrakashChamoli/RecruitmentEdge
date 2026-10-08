<?php

namespace App\Services\AI\Tools\CandidateTools;

use App\Enums\AiRiskLevel;
use App\Models\Candidate;
use App\Models\User;
use App\Services\AI\DTO\ToolResult;
use App\Services\AI\Tools\Concerns\ProjectsForAi;
use App\Services\AI\Tools\Concerns\ScopesToHierarchy;
use App\Services\AI\Tools\Contracts\AiTool;
use Illuminate\Database\Eloquent\Builder;

class GetCandidateTool implements AiTool
{
    use ProjectsForAi, ScopesToHierarchy;

    public function name(): string
    {
        return 'get_candidate';
    }

    public function description(): string
    {
        return 'Get profile detail for one candidate by id: experience, skills, qualification, notice period, city, compensation fit against each role budget, and their visible applications with current stage.'.' Candidates are identified by candidate_ref codes; the application shows names and contact details to the user, never to you.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'candidate_id' => ['type' => 'integer'],
            ],
            'required' => ['candidate_id'],
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

        $candidate = Candidate::query()
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereHas(
                'applications',
                fn (Builder $a) => $a->whereIn('recruiter_id', $visibleIds),
            ))
            ->with(['source', 'applications.requisition.designation', 'applications.recruiter'])
            ->find($arguments['candidate_id'] ?? null);

        if ($candidate === null) {
            return ToolResult::fail('Candidate not found, or not visible to you.');
        }

        $applications = $candidate->applications->filter(fn ($application) => $visibleIds === null || $visibleIds->contains($application->recruiter_id));
        $profile = $this->projector()->candidateProfile($candidate, $applications);

        return ToolResult::ok(
            data: ['candidate' => $profile],
            summary: "Loaded profile for {$profile['candidate_ref']}.",
            type: 'candidate_card',
        );
    }
}
