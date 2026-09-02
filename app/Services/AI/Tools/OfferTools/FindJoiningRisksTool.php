<?php

namespace App\Services\AI\Tools\OfferTools;

use App\Enums\AiRiskLevel;
use App\Enums\JoiningStatus;
use App\Models\CandidateJoining;
use App\Models\User;
use App\Services\AI\DTO\ToolResult;
use App\Services\AI\Tools\Concerns\ScopesToHierarchy;
use App\Services\AI\Tools\Contracts\AiTool;
use Illuminate\Database\Eloquent\Builder;

/**
 * Wraps CandidateJoining::riskLevel(), always computed live (per .ai/rules/models-services.md) —
 * never cached or reimplemented here.
 */
class FindJoiningRisksTool implements AiTool
{
    use ScopesToHierarchy;

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
            ->with(['candidateApplication.candidate:id,full_name'])
            ->get();

        $rows = $joinings->map(fn (CandidateJoining $j) => [
            'joining_id' => $j->id,
            'candidate' => $j->candidateApplication?->candidate?->full_name,
            'status' => $j->status->label(),
            'expected_doj' => $j->expected_doj?->toDateString(),
            'risk' => $j->riskLevel(),
        ])->filter(fn (array $row) => in_array($row['risk'], ['yellow', 'red'], true))->values();

        return ToolResult::ok(
            data: ['at_risk_joinings' => $rows->toArray()],
            summary: "{$rows->count()} joining(s) flagged at risk.",
            type: 'candidate_list',
        );
    }
}
