<?php

namespace App\Services\AI\Tools\JobTools;

use App\Enums\AiRiskLevel;
use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Models\CandidateApplication;
use App\Models\RecruitmentRequisition;
use App\Models\User;
use App\Services\AI\DTO\ToolResult;
use App\Services\AI\Tools\Concerns\ScopesToHierarchy;
use App\Services\AI\Tools\Contracts\AiTool;

/**
 * Stage and status counts for one requisition's applications. The requisition must be visible
 * (same involvedEmployeeIds() rule as GetRequisitionTool), and only applications whose recruiter is
 * in the caller's hierarchy are counted — so a manager sees their team's slice of a shared role.
 */
class GetRequisitionPipelineTool implements AiTool
{
    use ScopesToHierarchy;

    public function name(): string
    {
        return 'get_requisition_pipeline';
    }

    public function description(): string
    {
        return 'Pipeline for one requisition: number of applications at each stage and in each status (active, rejected, dropout, on hold), plus openings remaining.';
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
            ->with(['designation:id,name', 'department:id,name'])
            ->find($arguments['requisition_id'] ?? null);

        $visibleIds = $this->visibleEmployeeIds($user);

        if ($requisition === null || ($visibleIds !== null && collect($requisition->involvedEmployeeIds())->intersect($visibleIds)->isEmpty())) {
            return ToolResult::fail('Requisition not found, or not visible to you.');
        }

        $applications = $this->scopeRecruiterOwnedTo(CandidateApplication::query(), $user)
            ->where('requisition_id', $requisition->id)
            ->get(['id', 'current_stage', 'status']);

        $stageCounts = $applications->countBy(fn (CandidateApplication $application) => $application->current_stage->value);
        $activeStageCounts = $applications
            ->where('status', ApplicationStatus::Active)
            ->countBy(fn (CandidateApplication $application) => $application->current_stage->value);
        $statusCounts = $applications->countBy(fn (CandidateApplication $application) => $application->status->value);

        $byStage = collect(CandidateStage::cases())->map(fn (CandidateStage $stage) => [
            'stage' => $stage->label(),
            'total' => (int) ($stageCounts[$stage->value] ?? 0),
            'active' => (int) ($activeStageCounts[$stage->value] ?? 0),
        ])->values()->all();

        $byStatus = collect(ApplicationStatus::cases())
            ->mapWithKeys(fn (ApplicationStatus $status) => [$status->label() => (int) ($statusCounts[$status->value] ?? 0)])
            ->all();

        return ToolResult::ok(
            data: [
                'requisition_id' => $requisition->id,
                'code' => $requisition->code,
                'designation' => $requisition->designation?->name,
                'department' => $requisition->department?->name,
                'status' => $requisition->status->label(),
                'openings' => $requisition->openings,
                'remaining_openings' => $requisition->remainingOpenings(),
                'total_applications' => $applications->count(),
                'by_stage' => $byStage,
                'by_status' => $byStatus,
            ],
            summary: "{$requisition->code}: {$applications->count()} application(s), ".($statusCounts[ApplicationStatus::Active->value] ?? 0).' active, '.$requisition->remainingOpenings().' opening(s) remaining.',
            type: 'pipeline',
        );
    }
}
