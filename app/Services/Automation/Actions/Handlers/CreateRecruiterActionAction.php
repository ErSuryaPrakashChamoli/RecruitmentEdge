<?php

namespace App\Services\Automation\Actions\Handlers;

use App\Enums\ActionPriority;
use App\Enums\RecruiterActionType;
use App\Models\AutomationExecution;
use App\Models\Candidate;
use App\Services\Automation\Actions\ActionOutcome;
use App\Services\Automation\Actions\Contracts\AutomationAction;
use App\Services\Automation\AutomationContext;
use App\Services\Automation\RecipientResolver;
use App\Services\Communication\TemplateRenderer;
use App\Services\RecruiterActionService;
use DomainException;

/**
 * Creates an Action Center item through RecruiterActionService (which notifies and audits),
 * owned by a resolved team member. Keyed by execution + position, so a retried or duplicate run
 * never creates a second item.
 */
class CreateRecruiterActionAction implements AutomationAction
{
    public function __construct(
        private readonly RecruiterActionService $actions,
        private readonly RecipientResolver $recipients,
        private readonly TemplateRenderer $renderer,
    ) {}

    public function key(): string
    {
        return 'create_action';
    }

    public function label(): string
    {
        return 'Create an Action Center item';
    }

    public function validate(array $config): array
    {
        $errors = [];

        if (RecruiterActionType::tryFrom((string) ($config['action_type'] ?? '')) === null) {
            $errors[] = 'Choose the kind of action to create.';
        }

        if (! RecipientResolver::isValidTarget((string) ($config['owner'] ?? ''))) {
            $errors[] = 'Choose who owns the action.';
        }

        if (ActionPriority::tryFrom((string) ($config['priority'] ?? 'medium')) === null) {
            $errors[] = 'Unknown action priority.';
        }

        if (isset($config['due_in_hours']) && (! is_numeric($config['due_in_hours']) || (int) $config['due_in_hours'] < 0 || (int) $config['due_in_hours'] > 24 * 60)) {
            $errors[] = 'The action due time must be between 0 hours and 60 days.';
        }

        try {
            $this->renderer->validate((string) ($config['title'] ?? ''));
        } catch (DomainException $e) {
            $errors[] = $e->getMessage();
        }

        return $errors;
    }

    public function describe(array $config, ?AutomationContext $context = null): string
    {
        $type = RecruiterActionType::tryFrom((string) ($config['action_type'] ?? ''))?->label() ?? '?';
        $due = filled($config['due_in_hours'] ?? null) ? ", due in {$config['due_in_hours']}h" : '';

        return "Create \"{$type}\" action for ".RecipientResolver::label((string) ($config['owner'] ?? '')).$due;
    }

    public function execute(array $config, AutomationContext $context, AutomationExecution $execution, int $position): ActionOutcome
    {
        $owner = $this->recipients->resolve((string) $config['owner'], $context);

        if ($owner === null) {
            return ActionOutcome::failed('No active owner found for "'.RecipientResolver::label((string) $config['owner']).'".');
        }

        $type = RecruiterActionType::from((string) $config['action_type']);
        $messageContext = $context->messageContext();
        $title = filled($config['title'] ?? null) ? (string) $config['title'] : $type->label();
        $title = $messageContext !== null ? $this->renderer->render($title, $messageContext) : $title;

        $action = $this->actions->createOnce("automation:{$execution->id}:{$position}", [
            'title' => $title,
            'action_type' => $type,
            'priority' => ActionPriority::tryFrom((string) ($config['priority'] ?? 'medium')) ?? ActionPriority::Medium,
            'owner_id' => $owner->id,
            'candidate_id' => $context->candidate()?->id,
            'candidate_application_id' => $context->application()?->id,
            'requisition_id' => $context->requisition()?->id,
            'subject_type' => $context->subject instanceof Candidate ? null : $context->subject->getMorphClass(),
            'subject_id' => $context->subject->getKey(),
            'reason' => $config['reason'] ?? 'Created by automation rule "'.$execution->rule?->name.'".',
            'suggested_action' => $config['suggested_action'] ?? null,
            'due_at' => filled($config['due_in_hours'] ?? null) ? now()->addHours((int) $config['due_in_hours']) : null,
            'automation_rule_id' => $execution->automation_rule_id,
            'automation_execution_id' => $execution->id,
        ]);

        return ActionOutcome::completed("Action \"{$action->title}\" for {$action->owner?->fullName()}", $action);
    }
}
