<?php

namespace App\Services\Automation\Actions\Handlers;

use App\Enums\CandidateStage;
use App\Models\AutomationExecution;
use App\Services\Automation\Actions\ActionOutcome;
use App\Services\Automation\Actions\Contracts\AutomationAction;
use App\Services\Automation\AutomationContext;
use App\Services\StageTransitionService;
use DomainException;

/**
 * Moves an application to an early, non-decision stage through StageTransitionService (so allowed
 * transitions, history, SLA and events all apply). Hiring decisions are never automated: selection,
 * offer, joining and rejection stages cannot be targeted.
 */
class MoveStageAction implements AutomationAction
{
    /**
     * @var array<int, CandidateStage>
     */
    public const array ALLOWED_STAGES = [
        CandidateStage::ContactAttempted,
        CandidateStage::Connected,
        CandidateStage::Interested,
        CandidateStage::Screened,
    ];

    public function __construct(private readonly StageTransitionService $transitions) {}

    public function key(): string
    {
        return 'move_stage';
    }

    public function label(): string
    {
        return 'Move candidate to an early stage';
    }

    public function validate(array $config): array
    {
        $stage = CandidateStage::tryFrom((string) ($config['stage'] ?? ''));

        return $stage === null || ! in_array($stage, self::ALLOWED_STAGES, true)
            ? ['Automation may only move candidates to: '.collect(self::ALLOWED_STAGES)->map->label()->implode(', ').'. Hiring decisions stay with people.']
            : [];
    }

    public function describe(array $config, ?AutomationContext $context = null): string
    {
        return 'Move the candidate to "'.(CandidateStage::tryFrom((string) ($config['stage'] ?? ''))?->label() ?? '?').'" (only if that move is allowed)';
    }

    public function execute(array $config, AutomationContext $context, AutomationExecution $execution, int $position): ActionOutcome
    {
        $application = $context->application();

        if ($application === null) {
            return ActionOutcome::skipped('No application to move.');
        }

        $stage = CandidateStage::from((string) $config['stage']);

        if ($application->current_stage === $stage) {
            return ActionOutcome::skipped("Already at {$stage->label()}.");
        }

        try {
            $this->transitions->transitionTo($application, $stage, null, 'Automation: '.$execution->rule?->name);
        } catch (DomainException $e) {
            return ActionOutcome::failed('Stage move not allowed: '.$e->getMessage());
        }

        return ActionOutcome::completed("Moved to {$stage->label()}", $application);
    }
}
