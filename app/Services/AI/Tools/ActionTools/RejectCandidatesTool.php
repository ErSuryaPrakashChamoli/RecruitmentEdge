<?php

namespace App\Services\AI\Tools\ActionTools;

use App\Enums\AiRiskLevel;
use App\Models\CandidateApplication;
use App\Models\RecruitmentRejectionReason;
use App\Models\User;
use App\Services\AI\DTO\ToolResult;
use App\Services\AI\Tools\Concerns\ScopesToHierarchy;
use App\Services\AI\Tools\Contracts\AiTool;
use App\Services\StageTransitionService;
use DomainException;
use Illuminate\Database\Eloquent\Builder;

/**
 * HighImpact: bulk rejection is irreversible business-wise (candidate is told no) even though the
 * record itself can be reactivated — always requires explicit confirmation (spec section 25).
 */
class RejectCandidatesTool implements AiTool
{
    use ScopesToHierarchy;

    public function __construct(private readonly StageTransitionService $stageTransitions) {}

    public function name(): string
    {
        return 'reject_candidates';
    }

    public function description(): string
    {
        return 'Reject one or more candidate applications with a reason. High-impact action — always requires human approval.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'application_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'minItems' => 1],
                'rejection_reason_id' => ['type' => 'integer'],
                'remarks' => ['type' => 'string'],
            ],
            'required' => ['application_ids', 'rejection_reason_id'],
        ];
    }

    public function riskLevel(): AiRiskLevel
    {
        return AiRiskLevel::HighImpact;
    }

    public function permission(): ?string
    {
        return 'pipeline.transition';
    }

    public function handle(array $arguments, User $user): ToolResult
    {
        $reason = RecruitmentRejectionReason::query()->find($arguments['rejection_reason_id'] ?? null);

        if ($reason === null) {
            return ToolResult::fail('Rejection reason not found.');
        }

        $visibleIds = $this->visibleEmployeeIds($user);
        $ids = array_slice(array_map('intval', $arguments['application_ids'] ?? []), 0, (int) config('ai.limits.max_bulk_action_size'));

        $applications = CandidateApplication::query()
            ->whereIn('id', $ids)
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereIn('recruiter_id', $visibleIds))
            ->get();

        $rejected = [];
        $failed = [];

        foreach ($applications as $application) {
            try {
                $this->stageTransitions->reject($application, $reason, $user->employee, $arguments['remarks'] ?? null);
                $rejected[] = $application->id;
            } catch (DomainException $e) {
                $failed[] = ['application_id' => $application->id, 'reason' => $e->getMessage()];
            }
        }

        return ToolResult::ok(
            data: ['entity_type' => 'CandidateApplication', 'entity_ids' => $rejected, 'failed' => $failed],
            summary: count($rejected).' of '.count($ids).' application(s) rejected'.($failed !== [] ? ' ('.count($failed).' failed)' : '').'.',
            type: 'action_result',
        );
    }
}
