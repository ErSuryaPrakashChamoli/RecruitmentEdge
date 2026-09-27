<?php

namespace App\Services\Automation\Actions\Handlers;

use App\Enums\ApplicationStatus;
use App\Models\AutomationExecution;
use App\Services\Automation\Actions\ActionOutcome;
use App\Services\Automation\Actions\Contracts\AutomationAction;
use App\Services\Automation\AutomationContext;
use App\Services\StageTransitionService;
use DomainException;

/**
 * Puts an active application on hold through StageTransitionService::hold() — reversible, recorded
 * in stage history with the rule as the remark. Rejection and dropout are never automated.
 */
class HoldApplicationAction implements AutomationAction
{
    public function __construct(private readonly StageTransitionService $transitions) {}

    public function key(): string
    {
        return 'hold_application';
    }

    public function label(): string
    {
        return 'Put application on hold';
    }

    public function validate(array $config): array
    {
        return blank($config['remarks'] ?? null) ? ['A remark explaining the hold is required.'] : [];
    }

    public function describe(array $config, ?AutomationContext $context = null): string
    {
        return 'Put the application on hold: "'.($config['remarks'] ?? '').'"';
    }

    public function execute(array $config, AutomationContext $context, AutomationExecution $execution, int $position): ActionOutcome
    {
        $application = $context->application();

        if ($application === null) {
            return ActionOutcome::skipped('No application to put on hold.');
        }

        // Phase 8.7 (DQ-87-11): a re-run after the hold already happened reports it as done,
        // not as a failure.
        if ($application->status === ApplicationStatus::OnHold) {
            return ActionOutcome::completed('Application already on hold', $application);
        }

        try {
            $this->transitions->hold($application, null, $config['remarks'].' [Automation: '.$execution->rule?->name.']');
        } catch (DomainException $e) {
            return ActionOutcome::failed($e->getMessage());
        }

        return ActionOutcome::completed('Application put on hold', $application);
    }
}
