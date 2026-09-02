<?php

namespace App\Services\AI\Tools\CandidateTools;

use App\Enums\AiRiskLevel;
use App\Models\Candidate;
use App\Models\User;
use App\Services\AI\DTO\ToolResult;
use App\Services\AI\Tools\Concerns\ScopesToHierarchy;
use App\Services\AI\Tools\Contracts\AiTool;
use Illuminate\Database\Eloquent\Builder;

/**
 * Returns structured facts about a candidate for the model to summarize in prose — the tool itself
 * never fabricates a narrative, it only assembles ground-truth fields (spec section 45).
 */
class SummarizeCandidateTool implements AiTool
{
    use ScopesToHierarchy;

    public function name(): string
    {
        return 'summarize_candidate';
    }

    public function description(): string
    {
        return 'Gather the facts needed to summarize a candidate: profile, latest application stage, interview feedback, and stage history.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['candidate_id' => ['type' => 'integer']],
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
            ->with([
                'applications.requisition.designation',
                'applications.interviews.feedback',
                'applications.stageHistory',
            ])
            ->find($arguments['candidate_id'] ?? null);

        if ($candidate === null) {
            return ToolResult::fail('Candidate not found, or not visible to you.');
        }

        return ToolResult::ok(
            data: ['candidate' => $candidate->toArray()],
            summary: "Gathered facts for {$candidate->full_name} to summarize.",
            type: 'candidate_card',
        );
    }
}
