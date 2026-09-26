<?php

namespace App\Services\AI\Tools\CandidateTools;

use App\Enums\AiRiskLevel;
use App\Models\Candidate;
use App\Models\User;
use App\Services\AI\DTO\ToolResult;
use App\Services\AI\Tools\Concerns\ProjectsForAi;
use App\Services\AI\Tools\Concerns\ScopesToHierarchy;
use App\Services\AI\Tools\Contracts\AiTool;
use App\Services\CandidateDuplicateDetector;
use App\Services\DuplicateCandidateMatch;

/**
 * Both the subject candidate and every reported match are restricted to the caller's hierarchy
 * (same rule as CandidatePolicy) — CandidateDuplicateDetector itself matches company-wide, so its
 * output is filtered here rather than leaking out-of-scope candidates' names.
 */
class FindDuplicateCandidatesTool implements AiTool
{
    use ProjectsForAi, ScopesToHierarchy;

    public function __construct(private readonly CandidateDuplicateDetector $detector) {}

    public function name(): string
    {
        return 'find_duplicate_candidates';
    }

    public function description(): string
    {
        return 'Check whether a candidate has likely duplicates among the candidates you can see, matched by mobile number or email — mirrors CandidateDuplicateDetector, the same logic used on candidate creation.';
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
        $candidate = $this->scopeCandidatesVisibleTo(Candidate::query(), $user)->find($arguments['candidate_id'] ?? null);

        if ($candidate === null) {
            return ToolResult::fail('Candidate not found, or not visible to you.');
        }

        $matches = $this->detector->detect($candidate->only(['full_name', 'mobile', 'alternate_mobile', 'email']), $candidate->id);

        $visibleMatchIds = $this->scopeCandidatesVisibleTo(Candidate::query(), $user)
            ->whereKey($matches->map(fn (DuplicateCandidateMatch $match) => $match->candidate->id)->values())
            ->pluck('id');

        $rows = $matches
            ->filter(fn (DuplicateCandidateMatch $match) => $visibleMatchIds->contains($match->candidate->id))
            ->map(fn (DuplicateCandidateMatch $match) => [
                'candidate_id' => $match->candidate->id,
                'candidate_ref' => $this->projector()->candidateRef($match->candidate),
                'match_type' => $match->type->value,
                'confidence' => $match->confidence,
                'matching_fields' => $match->matchingFields,
                'reason' => $match->reason,
            ])
            ->values();

        $candidateRef = $this->projector()->candidateRef($candidate);

        return ToolResult::ok(
            data: ['candidate_ref' => $candidateRef, 'duplicates' => $rows->toArray()],
            summary: $rows->isEmpty()
                ? "No likely duplicates found for {$candidateRef}."
                : "Found {$rows->count()} likely duplicate(s) for {$candidateRef}.",
            type: 'candidate_list',
        );
    }
}
