<?php

namespace App\Services\AI\Tools\CandidateTools;

use App\Enums\AiRiskLevel;
use App\Models\Candidate;
use App\Models\User;
use App\Services\AI\DTO\ToolResult;
use App\Services\AI\Tools\Concerns\ScopesToHierarchy;
use App\Services\AI\Tools\Contracts\AiTool;
use Illuminate\Database\Eloquent\Builder;

class GetCandidateTool implements AiTool
{
    use ScopesToHierarchy;

    public function name(): string
    {
        return 'get_candidate';
    }

    public function description(): string
    {
        return 'Get full profile detail for one candidate by id, including their applications and current pipeline stage.';
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

        return ToolResult::ok(
            data: ['candidate' => $candidate->toArray()],
            summary: "Loaded profile for {$candidate->full_name}.",
            type: 'candidate_card',
        );
    }
}
