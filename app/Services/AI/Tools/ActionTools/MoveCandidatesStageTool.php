<?php

namespace App\Services\AI\Tools\ActionTools;

use App\Enums\AiRiskLevel;
use App\Enums\CandidateStage;
use App\Models\CandidateApplication;
use App\Models\User;
use App\Services\AI\DTO\ToolResult;
use App\Services\AI\Tools\Concerns\ScopesToHierarchy;
use App\Services\AI\Tools\Contracts\AiTool;
use App\Services\StageTransitionService;
use DomainException;
use Illuminate\Database\Eloquent\Builder;

class MoveCandidatesStageTool implements AiTool
{
    use ScopesToHierarchy;

    public function __construct(private readonly StageTransitionService $stageTransitions) {}

    public function name(): string
    {
        return 'move_candidates_stage';
    }

    public function description(): string
    {
        return 'Move one or more candidate applications forward to a new pipeline stage. Requires human approval before executing. Backward moves are rejected by the underlying service.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'application_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'minItems' => 1],
                'stage' => ['type' => 'string', 'description' => 'One of: '.implode(', ', array_map(fn ($c) => $c->value, CandidateStage::cases()))],
                'remarks' => ['type' => 'string'],
            ],
            'required' => ['application_ids', 'stage'],
        ];
    }

    public function riskLevel(): AiRiskLevel
    {
        return AiRiskLevel::Write;
    }

    public function permission(): ?string
    {
        return 'pipeline.transition';
    }

    public function handle(array $arguments, User $user): ToolResult
    {
        $stage = CandidateStage::tryFrom((string) ($arguments['stage'] ?? ''));

        if ($stage === null) {
            return ToolResult::fail('Unknown pipeline stage.');
        }

        $visibleIds = $this->visibleEmployeeIds($user);
        $ids = array_slice(array_map('intval', $arguments['application_ids'] ?? []), 0, (int) config('ai.limits.max_bulk_action_size'));

        $applications = CandidateApplication::query()
            ->whereIn('id', $ids)
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereIn('recruiter_id', $visibleIds))
            ->get();

        $moved = [];
        $failed = [];

        foreach ($applications as $application) {
            try {
                $this->stageTransitions->transitionTo($application, $stage, $user->employee, $arguments['remarks'] ?? null);
                $moved[] = $application->id;
            } catch (DomainException $e) {
                $failed[] = ['application_id' => $application->id, 'reason' => $e->getMessage()];
            }
        }

        return ToolResult::ok(
            data: ['entity_type' => 'CandidateApplication', 'entity_ids' => $moved, 'failed' => $failed, 'stage' => $stage->label()],
            summary: count($moved).' of '.count($ids)." application(s) moved to {$stage->label()}".($failed !== [] ? ' ('.count($failed).' failed)' : '').'.',
            type: 'action_result',
        );
    }
}
