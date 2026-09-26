<?php

namespace App\Services\AI\Tools\IntelligenceTools;

use App\Enums\AiRiskLevel;
use App\Models\HiringMemoryRecord;
use App\Models\RecruitmentRequisition;
use App\Models\User;
use App\Services\AI\DTO\ToolResult;
use App\Services\AI\Tools\Contracts\AiTool;

/**
 * READ: recorded Hiring Memory (hires, rejections, offers not converted, failed joinings,
 * requisition outcomes) for a requisition or a designation the user may see.
 */
class GetHiringMemoryTool implements AiTool
{
    use ResolvesIntelligenceScope;

    public function name(): string
    {
        return 'get_hiring_memory';
    }

    public function description(): string
    {
        return 'Get recorded Hiring Memory — what actually happened in past hiring (hires, rejections, declined offers, failed joinings, requisition outcomes) — for a requisition, or for all visible requisitions of the same designation. Facts only; post-joining outcomes are not tracked yet.';
    }

    public function inputSchema(): array
    {
        return ['type' => 'object', 'properties' => ['requisition_id' => ['type' => 'integer'], 'same_designation' => ['type' => 'boolean']], 'required' => ['requisition_id']];
    }

    public function riskLevel(): AiRiskLevel
    {
        return AiRiskLevel::Read;
    }

    public function permission(): ?string
    {
        return 'intelligence.memory.view';
    }

    public function handle(array $arguments, User $user): ToolResult
    {
        $requisition = $this->visibleRequisition($user, $arguments['requisition_id'] ?? null);

        if ($requisition === null) {
            return ToolResult::fail('Requisition not found, or not visible to you.');
        }

        $records = HiringMemoryRecord::query()
            ->where('is_current', true)
            ->whereIn('requisition_id', RecruitmentRequisition::query()->visibleTo($user)->select('id'))
            ->when(($arguments['same_designation'] ?? false) && $requisition->designation_id !== null,
                fn ($q) => $q->where('designation_id', $requisition->designation_id),
                fn ($q) => $q->where('requisition_id', $requisition->id))
            ->latest('captured_at')
            ->limit(30)
            ->get();

        return ToolResult::ok(
            data: ['memory' => $records->map(fn (HiringMemoryRecord $r) => ['type' => $r->memory_type->label(), 'summary' => $r->summary, 'captured' => $r->captured_at->toDateString(), 'version' => $r->version])->all()],
            summary: "{$records->count()} memory record(s).",
            type: 'hiring_memory',
        );
    }
}
