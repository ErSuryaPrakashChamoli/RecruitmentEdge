<?php

namespace App\Services\AI\Tools\IntelligenceTools;

use App\Enums\AiRiskLevel;
use App\Models\Employee;
use App\Models\HiringRisk;
use App\Models\RecruitmentRequisition;
use App\Models\User;
use App\Services\AI\DTO\ToolResult;
use App\Services\AI\Tools\Concerns\ProjectsForAi;
use App\Services\AI\Tools\Contracts\AiTool;
use App\Services\HierarchyService;
use Illuminate\Database\Eloquent\Builder;

/**
 * READ: open Hiring Risk Radar risks the user may see, with evidence and recommended actions.
 */
class ListHiringRisksTool implements AiTool
{
    use ProjectsForAi, ResolvesIntelligenceScope;

    public function name(): string
    {
        return 'list_hiring_risks';
    }

    public function description(): string
    {
        return 'List open hiring risks detected by the Risk Radar (ageing, thin pipeline, stalled sourcing, scheduling delays, offer and joining risk, interviewer backlogs, automation failures), optionally for one requisition, with severity, evidence and the recommended action.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => ['requisition_id' => ['type' => 'integer'], 'min_severity' => ['type' => 'string', 'enum' => ['low', 'medium', 'high', 'critical']]]];
    }

    public function riskLevel(): AiRiskLevel
    {
        return AiRiskLevel::Read;
    }

    public function permission(): ?string
    {
        return 'intelligence.view';
    }

    public function handle(array $arguments, User $user): ToolResult
    {
        if (filled($arguments['requisition_id'] ?? null) && $this->visibleRequisition($user, $arguments['requisition_id']) === null) {
            return ToolResult::fail('Requisition not found, or not visible to you.');
        }

        $severities = ['critical', 'high', 'medium', 'low'];
        $min = array_search($arguments['min_severity'] ?? 'low', $severities, true);

        // Phase 8.5 (DF-3): risks tied to a requisition follow requisition visibility; risks with no
        // requisition (interviewer backlog, failing automation) are visible when their owner or their
        // subject person is in the viewer's hierarchy.
        $visibleIds = app(HierarchyService::class)->visibleEmployeeIdsFor($user);

        $query = HiringRisk::query()
            ->open()
            ->where(fn (Builder $scope) => $scope
                ->whereIn('requisition_id', RecruitmentRequisition::query()->visibleTo($user)->select('id'))
                ->when(blank($arguments['requisition_id'] ?? null), fn (Builder $q) => $q->orWhere(fn (Builder $unattached) => $unattached
                    ->whereNull('requisition_id')
                    ->when($visibleIds !== null, fn (Builder $v) => $v->where(fn (Builder $who) => $who
                        ->whereIn('owner_id', $visibleIds)
                        ->orWhere(fn (Builder $subject) => $subject->where('subject_type', (new Employee)->getMorphClass())->whereIn('subject_id', $visibleIds)))))))
            ->when(filled($arguments['requisition_id'] ?? null), fn ($q) => $q->where('requisition_id', $arguments['requisition_id']))
            ->whereIn('severity', array_slice($severities, 0, $min === false ? 4 : $min + 1));

        $total = (clone $query)->count();
        $risks = $query
            ->with(['evidence', 'candidateApplication.candidate', 'requisition', 'subject'])
            ->latest('last_seen_at')
            ->limit(25)
            ->get();

        return ToolResult::ok(
            data: ['risks' => $risks->map(fn (HiringRisk $r) => $this->projector()->hiringRisk($r))->all(), 'total_open' => $total, 'shown' => $risks->count()],
            summary: $total > $risks->count() ? "{$total} open risk(s); showing the {$risks->count()} most recent." : "{$total} open risk(s).",
            type: 'hiring_risks',
        );
    }
}
