<?php

namespace App\Services\AI\Tools\JobTools;

use App\Enums\AiRiskLevel;
use App\Models\RecruitmentRequisition;
use App\Models\User;
use App\Services\AI\DTO\ToolResult;
use App\Services\AI\Tools\Contracts\AiTool;
use App\Services\HierarchyService;

class GetRequisitionTool implements AiTool
{
    public function __construct(private readonly HierarchyService $hierarchy) {}

    public function name(): string
    {
        return 'get_requisition';
    }

    public function description(): string
    {
        return 'Get full detail for one requisition by id, including openings filled, ageing, and assigned recruiters.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => ['requisition_id' => ['type' => 'integer']],
            'required' => ['requisition_id'],
        ];
    }

    public function riskLevel(): AiRiskLevel
    {
        return AiRiskLevel::Read;
    }

    public function permission(): ?string
    {
        return 'requisitions.viewAny';
    }

    public function handle(array $arguments, User $user): ToolResult
    {
        $requisition = RecruitmentRequisition::query()
            ->with(['department', 'designation', 'location', 'recruiters', 'manager', 'vpHr'])
            ->find($arguments['requisition_id'] ?? null);

        if ($requisition === null) {
            return ToolResult::fail('Requisition not found.');
        }

        $visibleIds = $this->hierarchy->visibleEmployeeIdsFor($user);

        if ($visibleIds !== null && ! collect($requisition->involvedEmployeeIds())->intersect($visibleIds)->isNotEmpty()) {
            return ToolResult::fail('Requisition not found, or not visible to you.');
        }

        return ToolResult::ok(
            data: [
                'requisition' => $requisition->toArray(),
                'remaining_openings' => $requisition->remainingOpenings(),
                'ageing_days' => $requisition->ageingInDays(),
            ],
            summary: "Loaded requisition {$requisition->code}.",
            type: 'job_card',
        );
    }
}
