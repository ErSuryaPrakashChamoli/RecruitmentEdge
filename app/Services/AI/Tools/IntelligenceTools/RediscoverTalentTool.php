<?php

namespace App\Services\AI\Tools\IntelligenceTools;

use App\Enums\AiRiskLevel;
use App\Models\RediscoveryResult;
use App\Models\User;
use App\Services\AI\DTO\ToolResult;
use App\Services\AI\Tools\Concerns\ProjectsForAi;
use App\Services\AI\Tools\Contracts\AiTool;
use App\Services\Intelligence\TalentRediscoveryService;

/**
 * RECOMMEND: runs a deterministic Talent Rediscovery for a requisition and returns the suggested
 * people with why. Nothing is changed on any candidate — adding them is a separate human action.
 */
class RediscoverTalentTool implements AiTool
{
    use ProjectsForAi, ResolvesIntelligenceScope;

    public function __construct(private readonly TalentRediscoveryService $rediscovery) {}

    public function name(): string
    {
        return 'rediscover_talent';
    }

    public function description(): string
    {
        return 'Find people already known to the organisation (past candidates, talent pool members) who align with a requisition\'s Role DNA, with the evidence for each. Suggestions only — adding someone to the requisition is done by a person in the app.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => ['requisition_id' => ['type' => 'integer']], 'required' => ['requisition_id']];
    }

    public function riskLevel(): AiRiskLevel
    {
        return AiRiskLevel::Recommend;
    }

    public function permission(): ?string
    {
        return 'intelligence.rediscover';
    }

    public function handle(array $arguments, User $user): ToolResult
    {
        $requisition = $this->visibleRequisition($user, $arguments['requisition_id'] ?? null);

        if ($requisition === null) {
            return ToolResult::fail('Requisition not found, or not visible to you.');
        }

        $run = $this->rediscovery->run($requisition, $user);

        return ToolResult::ok(
            data: [
                'requisition' => $requisition->code,
                'scanned' => $run->candidates_scanned,
                'suggestions' => $run->results()->with('candidate')->limit(10)->get()->map(fn (RediscoveryResult $r) => [
                    'rank' => $r->rank,
                    'candidate' => $this->projector()->candidateRef($r->candidate),
                    'band' => $r->band->label(),
                    'why' => $r->summary['reasons'] ?? [],
                    'do_not_contact' => $r->do_not_contact,
                ])->all(),
                'advisory' => true,
            ],
            summary: "Rediscovered {$run->results_count} candidate(s) from {$run->candidates_scanned} scanned for {$requisition->code}.",
            type: 'rediscovery',
        );
    }
}
