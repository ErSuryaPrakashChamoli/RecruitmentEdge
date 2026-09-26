<?php

namespace App\Services\AI\Tools\IntelligenceTools;

use App\Enums\AiRiskLevel;
use App\Models\HiringRisk;
use App\Models\RecruitmentRequisition;
use App\Models\User;
use App\Services\AI\DTO\ToolResult;
use App\Services\AI\Tools\Contracts\AiTool;

/**
 * READ: open Hiring Risk Radar risks the user may see, with evidence and recommended actions.
 */
class ListHiringRisksTool implements AiTool
{
    use ResolvesIntelligenceScope;

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

        $risks = HiringRisk::query()
            ->open()
            ->whereIn('requisition_id', RecruitmentRequisition::query()->visibleTo($user)->select('id'))
            ->when(filled($arguments['requisition_id'] ?? null), fn ($q) => $q->where('requisition_id', $arguments['requisition_id']))
            ->whereIn('severity', array_slice($severities, 0, $min === false ? 4 : $min + 1))
            ->with('evidence')
            ->latest('last_seen_at')
            ->limit(25)
            ->get();

        return ToolResult::ok(
            data: ['risks' => $risks->map(fn (HiringRisk $r) => [
                'id' => $r->id,
                'type' => $r->type->label(),
                'severity' => $r->severity->value,
                'title' => $r->title,
                'description' => $r->description,
                'recommended_action' => $r->recommended_action,
                'evidence' => $r->evidence->take(5)->map(fn ($e) => trim($e->label.': '.$e->value))->all(),
                'first_detected' => $r->first_detected_at->toDateString(),
            ])->all()],
            summary: "{$risks->count()} open risk(s).",
            type: 'hiring_risks',
        );
    }
}
