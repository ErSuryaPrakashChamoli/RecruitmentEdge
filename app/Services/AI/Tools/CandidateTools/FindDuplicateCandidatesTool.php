<?php

namespace App\Services\AI\Tools\CandidateTools;

use App\Enums\AiRiskLevel;
use App\Models\Candidate;
use App\Models\User;
use App\Services\AI\DTO\ToolResult;
use App\Services\AI\Tools\Concerns\ScopesToHierarchy;
use App\Services\AI\Tools\Contracts\AiTool;
use App\Services\CandidateDuplicateDetector;

/**
 * Both the subject candidate and every reported match are restricted to the caller's hierarchy
 * (same rule as CandidatePolicy) — CandidateDuplicateDetector itself matches company-wide, so its
 * output is filtered here rather than leaking out-of-scope candidates' names.
 */
class FindDuplicateCandidatesTool implements AiTool
{
    use ScopesToHierarchy;

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

        $matches = $this->detector->findMatches($candidate);

        $visibleMatchIds = $this->scopeCandidatesVisibleTo(Candidate::query(), $user)
            ->whereKey($matches->map(fn (array $match) => $match['candidate']->id)->unique()->values())
            ->pluck('id');

        $rows = $matches
            ->filter(fn (array $match) => $visibleMatchIds->contains($match['candidate']->id))
            ->map(fn (array $match) => [
                'candidate_id' => $match['candidate']->id,
                'name' => $match['candidate']->full_name,
                'match_type' => $match['type']->value,
            ])
            ->values();

        return ToolResult::ok(
            data: ['duplicates' => $rows->toArray()],
            summary: $rows->isEmpty()
                ? "No likely duplicates found for {$candidate->full_name}."
                : "Found {$rows->count()} likely duplicate(s) for {$candidate->full_name}.",
            type: 'candidate_list',
        );
    }
}
