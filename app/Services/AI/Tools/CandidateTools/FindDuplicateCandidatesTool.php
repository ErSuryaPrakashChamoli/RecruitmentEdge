<?php

namespace App\Services\AI\Tools\CandidateTools;

use App\Enums\AiRiskLevel;
use App\Models\Candidate;
use App\Models\User;
use App\Services\AI\DTO\ToolResult;
use App\Services\AI\Tools\Contracts\AiTool;
use App\Services\CandidateDuplicateDetector;

class FindDuplicateCandidatesTool implements AiTool
{
    public function __construct(private readonly CandidateDuplicateDetector $detector) {}

    public function name(): string
    {
        return 'find_duplicate_candidates';
    }

    public function description(): string
    {
        return 'Check whether a candidate has likely duplicates elsewhere in the system, matched by mobile number or email — mirrors CandidateDuplicateDetector, the same logic used on candidate creation.';
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
        $candidate = Candidate::query()->find($arguments['candidate_id'] ?? null);

        if ($candidate === null) {
            return ToolResult::fail('Candidate not found.');
        }

        $matches = $this->detector->findMatches($candidate)->map(fn (array $match) => [
            'candidate_id' => $match['candidate']->id,
            'name' => $match['candidate']->full_name,
            'match_type' => $match['type']->value,
        ]);

        return ToolResult::ok(
            data: ['duplicates' => $matches->toArray()],
            summary: $matches->isEmpty()
                ? "No likely duplicates found for {$candidate->full_name}."
                : "Found {$matches->count()} likely duplicate(s) for {$candidate->full_name}.",
            type: 'candidate_list',
        );
    }
}
