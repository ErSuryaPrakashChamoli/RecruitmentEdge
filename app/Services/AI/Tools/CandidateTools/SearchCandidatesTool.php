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
 * Wraps a plain Candidate search restricted to the acting user's hierarchy — candidates have no
 * owning-recruiter column, so scoping is via "any application recruited by someone I can see"
 * (see .ai/rules/policies-policies.md).
 */
class SearchCandidatesTool implements AiTool
{
    use ScopesToHierarchy;

    public function name(): string
    {
        return 'search_candidates';
    }

    public function description(): string
    {
        return 'Search candidates by name, skill, current company, or location, scoped to the candidates the current user is allowed to see.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'query' => ['type' => 'string', 'description' => 'Free-text search: name, skill, company, or location'],
                'limit' => ['type' => 'integer', 'description' => 'Max results, default 10, max 25'],
            ],
            'required' => ['query'],
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
        $query = trim((string) ($arguments['query'] ?? ''));

        if ($query === '') {
            return ToolResult::fail('A search query is required.');
        }

        $limit = min((int) ($arguments['limit'] ?? 10), 25);
        $visibleIds = $this->visibleEmployeeIds($user);

        $candidates = Candidate::query()
            ->where(function (Builder $q) use ($query): void {
                $q->where('full_name', 'like', "%{$query}%")
                    ->orWhere('current_company', 'like', "%{$query}%")
                    ->orWhere('current_city', 'like', "%{$query}%")
                    ->orWhereJsonContains('skills', $query);
            })
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereHas(
                'applications',
                fn (Builder $a) => $a->whereIn('recruiter_id', $visibleIds),
            ))
            ->limit($limit)
            ->get(['id', 'full_name', 'current_company', 'current_designation', 'current_city', 'total_experience', 'skills']);

        return ToolResult::ok(
            data: ['candidates' => $candidates->toArray()],
            summary: "Found {$candidates->count()} candidate(s) matching \"{$query}\".",
            type: 'candidate_list',
        );
    }
}
