<?php

namespace App\Services\Automation;

use App\Enums\ActionPriority;
use App\Enums\AutomationScope;
use App\Enums\CommunicationChannel;
use App\Models\AutomationRule;
use App\Models\CommunicationTemplate;
use App\Models\Department;
use App\Models\Employee;
use App\Models\Location;
use App\Models\RecruitmentRequisition;
use App\Models\User;
use App\Services\Automation\Actions\Handlers\SendCommunicationAction;
use App\Services\Communication\CommunicationProviderManager;

/**
 * Validates automation rules (Phase 6). `errors()` is the structural check applied on every save;
 * `activationErrors()` adds everything that must be true before a rule may run: at least one
 * action, active templates for every message, configured providers for explicitly chosen
 * channels, and the activating user's permission for the rule's scope.
 */
class AutomationRuleValidator
{
    public const int MAX_ACTIONS = 10;

    public const int MAX_ESCALATION_STEPS = 5;

    public function __construct(
        private readonly AutomationEventRegistry $events,
        private readonly AutomationFieldRegistry $fields,
        private readonly AutomationActionRegistry $actions,
        private readonly AutomationScopeResolver $scopes,
        private readonly CommunicationProviderManager $providers,
    ) {}

    /**
     * @return array<int, string>
     */
    public function errors(AutomationRule $rule): array
    {
        $errors = [];
        $trigger = $this->events->find((string) $rule->trigger);

        if ($trigger === null) {
            $errors[] = 'Choose a valid trigger.';
        }

        $errors = [...$errors, ...$this->conditionErrors($rule->conditions, 'Condition'), ...$this->timingErrors($rule->timing ?? [], $trigger)];

        $actions = array_values($rule->actions ?? []);

        if (count($actions) > self::MAX_ACTIONS) {
            $errors[] = 'A rule can have at most '.self::MAX_ACTIONS.' actions.';
        }

        foreach ($actions as $index => $action) {
            $handler = $this->actions->find((string) ($action['type'] ?? ''));
            $label = 'Action '.($index + 1);

            if ($handler === null) {
                $errors[] = "{$label}: choose a valid action type.";

                continue;
            }

            foreach ($handler->validate($action) as $error) {
                $errors[] = "{$label} ({$handler->label()}): {$error}";
            }
        }

        $errors = [...$errors, ...$this->escalationErrors($rule->escalation ?? [])];

        foreach (['cooldown_minutes', 'max_executions_per_day', 'max_executions_per_entity'] as $limit) {
            if ($rule->{$limit} !== null && $rule->{$limit} < 0) {
                $errors[] = 'Limits cannot be negative.';
            }
        }

        if ($rule->effective_from !== null && $rule->effective_until !== null && $rule->effective_until->lte($rule->effective_from)) {
            $errors[] = 'The effective end must be after the effective start.';
        }

        return [...$errors, ...$this->scopeErrors($rule)];
    }

    /**
     * @return array<int, string>
     */
    public function activationErrors(AutomationRule $rule, User $actor): array
    {
        $errors = $this->errors($rule);

        if (empty($rule->actions)) {
            $errors[] = 'Add at least one action before activating.';
        }

        if (! $actor->can('automation.activate')) {
            $errors[] = 'You do not have permission to activate automation rules.';
        }

        if (! $this->scopes->canUseScope($actor, $rule->scope_type, $rule->scope_id)) {
            $errors[] = $rule->scope_type === AutomationScope::Organization
                ? 'Organization-wide rules need the "automation.organization" permission.'
                : 'This rule\'s scope is outside your team.';
        }

        foreach (array_values($rule->actions ?? []) as $index => $action) {
            if (($action['type'] ?? null) === 'send_communication') {
                $errors = [...$errors, ...$this->messageReadinessErrors((string) ($action['template_key'] ?? ''), (array) ($action['channels'] ?? []), 'Action '.($index + 1))];
            }
        }

        foreach (array_values($rule->escalation['steps'] ?? []) as $index => $step) {
            if (filled($step['candidate_template'] ?? null)) {
                $errors = [...$errors, ...$this->messageReadinessErrors((string) $step['candidate_template'], [], 'Escalation step '.($index + 1))];
            }
        }

        return array_values(array_unique($errors));
    }

    /**
     * @param  array<string, mixed>|null  $tree
     * @return array<int, string>
     */
    public function conditionErrors(?array $tree, string $label, int $depth = 0): array
    {
        if ($tree === null || empty($tree['rules'] ?? [])) {
            return [];
        }

        if ($depth >= ConditionEvaluator::MAX_DEPTH) {
            return ["{$label}s may be nested at most ".ConditionEvaluator::MAX_DEPTH.' levels deep.'];
        }

        if (! in_array($tree['match'] ?? 'all', ['all', 'any'], true)) {
            return ["{$label} groups must match ALL or ANY."];
        }

        $errors = [];

        foreach (array_values($tree['rules']) as $index => $rule) {
            if (isset($rule['rules'])) {
                $errors = [...$errors, ...$this->conditionErrors($rule, $label, $depth + 1)];

                continue;
            }

            $field = $this->fields->find((string) ($rule['field'] ?? ''));
            $operator = (string) ($rule['operator'] ?? '');
            $name = "{$label} ".($index + 1);

            if ($field === null) {
                $errors[] = "{$name}: choose a valid field.";

                continue;
            }

            if (! array_key_exists($operator, AutomationFieldRegistry::OPERATORS[$field->type])) {
                $errors[] = "{$name} ({$field->label}): choose a valid comparison.";

                continue;
            }

            if (in_array($operator, AutomationFieldRegistry::UNARY_OPERATORS, true) || $field->type === 'boolean') {
                continue;
            }

            if (in_array($operator, AutomationFieldRegistry::DURATION_OPERATORS, true)) {
                if (! is_numeric($rule['amount'] ?? null) || (int) $rule['amount'] < 0 || ! array_key_exists((string) ($rule['unit'] ?? ''), AutomationTime::UNITS)) {
                    $errors[] = "{$name} ({$field->label}): enter a duration.";
                }

                continue;
            }

            if (blank($rule['value'] ?? null)) {
                $errors[] = "{$name} ({$field->label}): enter a value to compare with.";
            }
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $timing
     * @return array<int, string>
     */
    private function timingErrors(array $timing, ?Data\TriggerDefinition $trigger): array
    {
        if ($trigger === null) {
            return [];
        }

        $validAmount = fn ($amount): bool => is_numeric($amount) && (int) $amount >= 0 && (int) $amount <= 24 * 365;
        $validUnit = fn ($unit): bool => array_key_exists((string) $unit, AutomationTime::UNITS);

        if ($trigger->isScheduled()) {
            $errors = [];

            if (! $validAmount($timing['amount'] ?? null) || (int) ($timing['amount'] ?? 0) < 1 || ! $validUnit($timing['unit'] ?? null)) {
                $errors[] = "Timing: enter the \"{$trigger->thresholdLabel}\" threshold.";
            }

            if (filled($timing['repeat_every_hours'] ?? null) && (int) $timing['repeat_every_hours'] < 1) {
                $errors[] = 'Timing: repeat interval must be at least 1 hour.';
            }

            return $errors;
        }

        if (($timing['mode'] ?? 'immediate') === 'immediate') {
            return [];
        }

        $errors = [];

        if (($timing['mode'] ?? null) !== 'delay') {
            $errors[] = 'Timing: choose immediately or after a delay.';
        }

        if (! $validAmount($timing['amount'] ?? null) || ! $validUnit($timing['unit'] ?? null)) {
            $errors[] = 'Timing: enter the delay.';
        }

        if (! array_key_exists((string) ($timing['anchor'] ?? 'event'), $trigger->anchors)) {
            $errors[] = 'Timing: choose what the delay is measured from.';
        }

        if (($timing['direction'] ?? 'after') === 'before' && ($timing['anchor'] ?? 'event') === 'event') {
            $errors[] = 'Timing: a delay before the event itself is not possible — measure it from a date instead.';
        }

        return $errors;
    }

    /**
     * @param  array<string, mixed>  $escalation
     * @return array<int, string>
     */
    private function escalationErrors(array $escalation): array
    {
        $steps = array_values($escalation['steps'] ?? []);
        $errors = [];

        if (count($steps) > self::MAX_ESCALATION_STEPS) {
            $errors[] = 'At most '.self::MAX_ESCALATION_STEPS.' escalation steps.';
        }

        foreach ($steps as $index => $step) {
            $name = 'Escalation step '.($index + 1);

            if (! in_array((string) ($step['target'] ?? ''), RecipientResolver::ESCALATION_TARGETS, true)) {
                $errors[] = "{$name}: choose who to escalate to.";
            }

            if (! is_numeric($step['after'] ?? null) || (int) $step['after'] < 0 || ! array_key_exists((string) ($step['unit'] ?? 'hours'), AutomationTime::UNITS)) {
                $errors[] = "{$name}: enter when to escalate.";
            }

            if (ActionPriority::tryFrom((string) ($step['priority'] ?? 'high')) === null) {
                $errors[] = "{$name}: unknown priority.";
            }

            if (in_array((string) ($step['candidate_template'] ?? ''), SendCommunicationAction::reservedTemplateKeys(), true)) {
                $errors[] = "{$name}: that message is already sent automatically.";
            }
        }

        return [...$errors, ...$this->conditionErrors($escalation['stop_conditions'] ?? null, 'Stop condition')];
    }

    /**
     * @return array<int, string>
     */
    private function scopeErrors(AutomationRule $rule): array
    {
        if ($rule->scope_type === AutomationScope::Organization) {
            return [];
        }

        $exists = match ($rule->scope_type) {
            AutomationScope::Department => Department::query()->whereKey($rule->scope_id)->exists(),
            AutomationScope::Location => Location::query()->whereKey($rule->scope_id)->exists(),
            AutomationScope::Requisition => RecruitmentRequisition::query()->whereKey($rule->scope_id)->exists(),
            AutomationScope::Recruiter, AutomationScope::Team => Employee::query()->whereKey($rule->scope_id)->exists(),
        };

        return $exists ? [] : ["Choose the {$rule->scope_type->label()} this rule applies to."];
    }

    /**
     * @param  array<int, string>  $channels
     * @return array<int, string>
     */
    private function messageReadinessErrors(string $templateKey, array $channels, string $label): array
    {
        return [...$this->missingTemplateErrors($templateKey, $channels, $label), ...$this->unconfiguredProviderErrors($channels, $label)];
    }

    /**
     * @param  array<int, string>  $channels
     * @return array<int, string>
     */
    public function missingTemplateErrors(string $templateKey, array $channels, string $label): array
    {
        $chosen = collect($channels)->map(fn ($channel) => CommunicationChannel::tryFrom((string) $channel))->filter();
        $candidates = $chosen->isEmpty() ? collect(CommunicationChannel::sendable()) : $chosen;

        return $candidates->contains(fn (CommunicationChannel $channel) => CommunicationTemplate::activeFor($templateKey, $channel) !== null)
            ? []
            : ["{$label}: no active \"{$templateKey}\" template exists for ".($chosen->isEmpty() ? 'any channel' : $chosen->map->label()->implode(', ')).'.'];
    }

    /**
     * @param  array<int, string>  $channels
     * @return array<int, string>
     */
    public function unconfiguredProviderErrors(array $channels, string $label): array
    {
        return collect($channels)
            ->map(fn ($channel) => CommunicationChannel::tryFrom((string) $channel))
            ->filter()
            ->reject(fn (CommunicationChannel $channel) => $this->providers->for($channel)?->isConfigured() ?? false)
            ->map(fn (CommunicationChannel $channel) => "{$label}: the {$channel->label()} provider is not configured, so these messages cannot be delivered.")
            ->values()
            ->all();
    }
}
