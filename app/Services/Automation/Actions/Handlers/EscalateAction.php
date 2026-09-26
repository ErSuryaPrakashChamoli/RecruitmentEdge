<?php

namespace App\Services\Automation\Actions\Handlers;

use App\Enums\ActionPriority;
use App\Models\AutomationExecution;
use App\Services\Automation\Actions\ActionOutcome;
use App\Services\Automation\Actions\Contracts\AutomationAction;
use App\Services\Automation\AutomationContext;
use App\Services\Automation\EscalationService;
use App\Services\Automation\RecipientResolver;

/**
 * Escalates immediately to a level of the recruiter's management chain (resolved through
 * HierarchyService) — recorded as an escalation for analytics. For delayed, stoppable escalation
 * use the rule's escalation steps instead.
 */
class EscalateAction implements AutomationAction
{
    public function __construct(private readonly EscalationService $escalations) {}

    public function key(): string
    {
        return 'escalate';
    }

    public function label(): string
    {
        return 'Escalate now';
    }

    public function validate(array $config): array
    {
        $errors = [];

        if (! in_array((string) ($config['target'] ?? ''), RecipientResolver::ESCALATION_TARGETS, true)) {
            $errors[] = 'Choose who to escalate to.';
        }

        if (ActionPriority::tryFrom((string) ($config['priority'] ?? 'high')) === null) {
            $errors[] = 'Unknown escalation priority.';
        }

        return $errors;
    }

    public function describe(array $config, ?AutomationContext $context = null): string
    {
        return 'Escalate now to '.RecipientResolver::label((string) ($config['target'] ?? ''));
    }

    public function execute(array $config, AutomationContext $context, AutomationExecution $execution, int $position): ActionOutcome
    {
        $escalation = $this->escalations->escalateNow($execution, $context, [
            'target' => (string) $config['target'],
            'priority' => $config['priority'] ?? 'high',
            'message' => $config['message'] ?? null,
            'create_action' => (bool) ($config['create_action'] ?? false),
        ], 100 + $position);

        return $escalation->status->value === 'sent'
            ? ActionOutcome::completed((string) $escalation->outcome, $escalation)
            : ActionOutcome::failed((string) $escalation->outcome);
    }
}
