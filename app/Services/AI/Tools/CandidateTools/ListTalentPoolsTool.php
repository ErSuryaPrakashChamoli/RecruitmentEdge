<?php

namespace App\Services\AI\Tools\CandidateTools;

use App\Enums\AiRiskLevel;
use App\Models\Candidate;
use App\Models\TalentPool;
use App\Models\User;
use App\Services\AI\DTO\ToolResult;
use App\Services\AI\Tools\Concerns\ProjectsForAi;
use App\Services\AI\Tools\Concerns\ScopesToHierarchy;
use App\Services\AI\Tools\Contracts\AiTool;

/**
 * Phase 4 AI integration point for talent pools (the data Phase 7 Talent Rediscovery builds on):
 * the pools the user can see, or one pool's members — only candidates the user may see.
 */
class ListTalentPoolsTool implements AiTool
{
    use ProjectsForAi, ScopesToHierarchy;

    public function name(): string
    {
        return 'list_talent_pools';
    }

    public function description(): string
    {
        return 'List the talent pools visible to the user (name, criteria, tags, member count), or pass talent_pool_id to list that pool\'s candidates.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'talent_pool_id' => ['type' => 'integer', 'description' => 'Optional pool to list members of.'],
            ],
        ];
    }

    public function riskLevel(): AiRiskLevel
    {
        return AiRiskLevel::Read;
    }

    public function permission(): ?string
    {
        return 'talent-pools.viewAny';
    }

    public function handle(array $arguments, User $user): ToolResult
    {
        $pools = TalentPool::query()->visibleTo($user)->active();

        if (filled($arguments['talent_pool_id'] ?? null)) {
            $pool = $pools->find($arguments['talent_pool_id']);

            if ($pool === null) {
                return ToolResult::fail('Talent pool not found, or not visible to you.');
            }

            $members = $this->scopeCandidatesVisibleTo(Candidate::query(), $user)
                ->whereIn('id', $pool->activeMemberships()->select('candidate_id'))
                ->limit(100)
                ->get()
                ->map(fn (Candidate $candidate) => $this->projector()->candidateListItem($candidate))
                ->values();

            return ToolResult::ok(
                data: ['pool' => $pool->only(['id', 'name', 'criteria', 'tags']), 'candidates' => $members->toArray()],
                summary: "{$pool->name}: {$members->count()} visible candidate(s).",
                type: 'candidate_list',
            );
        }

        $rows = $pools->withCount('activeMemberships')->orderBy('name')->limit(100)->get()
            ->map(fn (TalentPool $pool) => [
                'id' => $pool->id,
                'name' => $pool->name,
                'visibility' => $pool->visibility->value,
                'criteria' => $pool->criteria,
                'tags' => $pool->tags,
                'members' => $pool->active_memberships_count,
            ])
            ->values();

        return ToolResult::ok(
            data: ['talent_pools' => $rows->toArray()],
            summary: "{$rows->count()} talent pool(s) visible.",
            type: 'table',
        );
    }
}
