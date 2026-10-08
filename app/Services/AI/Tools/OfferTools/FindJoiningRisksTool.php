<?php

namespace App\Services\AI\Tools\OfferTools;

use App\Enums\AiRiskLevel;
use App\Enums\JoiningStatus;
use App\Models\CandidateJoining;
use App\Models\User;
use App\Services\AI\DTO\ToolResult;
use App\Services\AI\Tools\Concerns\ProjectsForAi;
use App\Services\AI\Tools\Concerns\ScopesToHierarchy;
use App\Services\AI\Tools\Contracts\AiTool;
use Illuminate\Database\Eloquent\Builder;

/**
 * Wraps CandidateJoining::riskLevel(), always computed live (per .ai/rules/models-services.md) —
 * never cached or reimplemented here.
 */
class FindJoiningRisksTool implements AiTool
{
    use ProjectsForAi, ScopesToHierarchy;

    public function name(): string
    {
        return 'find_joining_risks';
    }

    public function description(): string
    {
        return 'List pending joinings flagged yellow (approaching/at risk) or red (overdue/no-show/dropout) by CandidateJoining::riskLevel().';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => []];
    }

    public function riskLevel(): AiRiskLevel
    {
        return AiRiskLevel::Read;
    }

    public function permission(): ?string
    {
        return 'joining.confirm';
    }

    public function handle(array $arguments, User $user): ToolResult
    {
        $visibleIds = $this->visibleEmployeeIds($user);

        $joinings = CandidateJoining::query()
            ->whereNotIn('status', [JoiningStatus::Joined])
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereHas(
                'candidateApplication',
                fn (Builder $a) => $a->whereIn('recruiter_id', $visibleIds),
            ))
            ->with(['candidateApplication.candidate'])
            ->get();

        $rows = $joinings
            ->filter(fn (CandidateJoining $j) => in_array($j->riskLevel(), ['yellow', 'red'], true))
            ->take(50)
            ->map(fn (CandidateJoining $j) => $this->projector()->joining($j))
            ->values();

        return ToolResult::ok(
            data: ['at_risk_joinings' => $rows->toArray()],
            summary: "{$rows->count()} joining(s) flagged at risk.",
            type: 'candidate_list',
        );
    }
}
