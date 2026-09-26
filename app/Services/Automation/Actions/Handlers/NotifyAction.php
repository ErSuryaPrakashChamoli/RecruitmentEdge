<?php

namespace App\Services\Automation\Actions\Handlers;

use App\Enums\ActionPriority;
use App\Models\AutomationExecution;
use App\Services\Automation\Actions\ActionOutcome;
use App\Services\Automation\Actions\Contracts\AutomationAction;
use App\Services\Automation\AutomationContext;
use App\Services\Automation\AutomationLinks;
use App\Services\Automation\RecipientResolver;
use App\Services\Communication\TemplateRenderer;
use App\Services\NotificationDispatchService;
use DomainException;

/**
 * Raises a persistent in-app notification through NotificationDispatchService for a recipient
 * resolved by RecipientResolver (recruiter, interviewer, requisition manager, or a hierarchy
 * level). Text may use the same whitelisted {{variables}} as candidate templates.
 */
class NotifyAction implements AutomationAction
{
    public function __construct(
        private readonly NotificationDispatchService $notifications,
        private readonly RecipientResolver $recipients,
        private readonly TemplateRenderer $renderer,
    ) {}

    public function key(): string
    {
        return 'notify';
    }

    public function label(): string
    {
        return 'Notify a team member';
    }

    public function validate(array $config): array
    {
        $errors = [];

        if (! RecipientResolver::isValidTarget((string) ($config['recipient'] ?? ''))) {
            $errors[] = 'Choose who to notify.';
        }

        if (blank($config['title'] ?? null)) {
            $errors[] = 'A notification title is required.';
        }

        if (ActionPriority::tryFrom((string) ($config['priority'] ?? 'medium')) === null) {
            $errors[] = 'Unknown notification priority.';
        }

        try {
            $this->renderer->validate((string) ($config['title'] ?? ''), (string) ($config['message'] ?? ''));
        } catch (DomainException $e) {
            $errors[] = $e->getMessage();
        }

        return $errors;
    }

    public function describe(array $config, ?AutomationContext $context = null): string
    {
        $recipient = RecipientResolver::label((string) ($config['recipient'] ?? ''));
        $priority = ActionPriority::tryFrom((string) ($config['priority'] ?? 'medium'))?->notificationLabel() ?? 'Medium';

        return "Notify {$recipient} ({$priority}): \"".($config['title'] ?? '').'"';
    }

    public function execute(array $config, AutomationContext $context, AutomationExecution $execution, int $position): ActionOutcome
    {
        $recipient = $this->recipients->resolve((string) $config['recipient'], $context);

        if ($recipient === null) {
            return ActionOutcome::failed('No active recipient found for "'.RecipientResolver::label((string) $config['recipient']).'".');
        }

        $priority = ActionPriority::tryFrom((string) ($config['priority'] ?? 'medium')) ?? ActionPriority::Medium;
        $messageContext = $context->messageContext();
        $render = fn (string $text): string => $messageContext !== null ? $this->renderer->render($text, $messageContext) : $text;

        $this->notifications->alert(
            $recipient->user,
            'Automation',
            $render((string) $config['title']),
            $render((string) ($config['message'] ?? '')),
            $priority->notificationColor(),
            AutomationLinks::for($context->subject),
            "automation-{$execution->id}-{$position}-{$recipient->id}",
            $priority,
            ['rule_id' => $execution->automation_rule_id, 'execution_id' => $execution->id, 'entity_type' => class_basename($context->subject), 'entity_id' => $context->subject->getKey()],
        );

        return ActionOutcome::completed("Notified {$recipient->fullName()}", $recipient);
    }
}
