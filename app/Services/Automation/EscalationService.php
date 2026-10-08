<?php

namespace App\Services\Automation;

use App\Enums\ActionPriority;
use App\Enums\EscalationStatus;
use App\Enums\RecruiterActionStatus;
use App\Enums\RecruiterActionType;
use App\Models\AuditLog;
use App\Models\AutomationEscalation;
use App\Models\AutomationExecution;
use App\Models\RecruiterAction;
use App\Services\Automation\Actions\Handlers\SendCommunicationAction;
use App\Services\NotificationDispatchService;
use App\Services\RecruiterActionService;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Hierarchy escalation for automation rules (Phase 6): Recruiter → Assistant Manager → Manager →
 * VP HR → CHRO, resolved only through RecipientResolver/HierarchyService when a step becomes due.
 *
 * Each step: a delay (from when the rule ran), a target, notification priority, and optionally an
 * Action Center item for the recipient and a follow-up candidate message. Before sending, the step
 * checks whether the issue is resolved — the rule's own Action Center item was completed or
 * dismissed, or the stop condition now holds — and if so stops every remaining step instead.
 */
class EscalationService
{
    public function __construct(
        private readonly RecipientResolver $recipients,
        private readonly NotificationDispatchService $notifications,
        private readonly RecruiterActionService $actions,
        private readonly ConditionEvaluator $conditions,
    ) {}

    /**
     * @param  array{steps?: array<int, array<string, mixed>>, stop_conditions?: array<string, mixed>|null}  $escalation
     */
    public function schedule(AutomationExecution $execution, array $escalation, CarbonInterface $from): int
    {
        $created = 0;

        foreach (array_values($escalation['steps'] ?? []) as $index => $step) {
            AutomationEscalation::query()->firstOrCreate(
                ['automation_execution_id' => $execution->id, 'step' => $index + 1],
                [
                    'automation_rule_id' => $execution->automation_rule_id,
                    'target' => (string) $step['target'],
                    'due_at' => AutomationTime::shift($from, (int) ($step['after'] ?? 0), (string) ($step['unit'] ?? 'hours')),
                ],
            );
            $created++;
        }

        return $created;
    }

    /**
     * Processes due steps (bounded), oldest first.
     */
    public function processDue(int $limit = 200): int
    {
        $processed = 0;

        AutomationEscalation::query()
            ->where('status', EscalationStatus::Pending)
            ->where('due_at', '<=', now())
            ->orderBy('due_at')
            ->limit($limit)
            ->pluck('id')
            ->each(function (int $id) use (&$processed) {
                $this->process($id);
                $processed++;
            });

        return $processed;
    }

    public function process(int $escalationId): ?AutomationEscalation
    {
        return DB::transaction(function () use ($escalationId): ?AutomationEscalation {
            $escalation = AutomationEscalation::query()->lockForUpdate()->find($escalationId);

            if ($escalation === null || $escalation->status !== EscalationStatus::Pending) {
                return $escalation;
            }

            $execution = $escalation->execution()->with('rule', 'ruleVersion')->first();
            $context = $execution !== null ? AutomationContext::fromExecution($execution) : null;

            if ($execution === null || $context === null || $execution->rule === null || ! $execution->rule->isActive()) {
                return $this->close($escalation, EscalationStatus::Cancelled, 'Rule is no longer active or the record no longer exists.');
            }

            // Phase 8.7 (SEC-87-06, D8.7-016/023): an escalation acts on the rule owner's authority,
            // re-checked now — effective dates, the owner's access, and the record's scope.
            $rule = $execution->rule;

            if (! $rule->isEffectiveAt(now())) {
                return $this->close($escalation, EscalationStatus::Cancelled, 'The rule is outside its effective dates.');
            }

            if (($problem = app(AutomationRuleService::class)->ownerAuthorityProblem($rule)) !== null) {
                app(AutomationRuleService::class)->pauseForAuthority($rule, $problem);

                return $this->close($escalation, EscalationStatus::Cancelled, "The rule was paused: {$problem}.");
            }

            if (($outside = app(AutomationScopeResolver::class)->outsideAuthority($rule, $context)) !== null) {
                AuditLog::asActor('automation', $rule->owner_id, fn () => AuditLog::record($execution, 'automation_skipped_authority', null, ['escalation_step' => $escalation->step, 'reason' => $outside]));

                return $this->close($escalation, EscalationStatus::Cancelled, $outside);
            }

            $config = $execution->ruleVersion?->snapshot['escalation'] ?? $execution->rule->escalation ?? [];
            $resolved = $this->resolvedReason($execution, $context, $config['stop_conditions'] ?? null);

            if ($resolved !== null) {
                $this->stopRemaining($execution, $resolved);

                return $escalation->refresh();
            }

            return AuditLog::asActor('automation', $rule->owner_id, fn () => $this->send($escalation, $execution, $context, $config['steps'][$escalation->step - 1] ?? ['target' => $escalation->target]));
        });
    }

    /**
     * An immediate escalation (the "Escalate now" action), recorded like a step.
     *
     * @param  array<string, mixed>  $step
     */
    public function escalateNow(AutomationExecution $execution, AutomationContext $context, array $step, int $stepNumber): AutomationEscalation
    {
        $escalation = AutomationEscalation::query()->firstOrCreate(
            ['automation_execution_id' => $execution->id, 'step' => $stepNumber],
            ['automation_rule_id' => $execution->automation_rule_id, 'target' => (string) $step['target'], 'due_at' => now()],
        );

        return $escalation->status === EscalationStatus::Pending ? $this->send($escalation, $execution, $context, $step) : $escalation;
    }

    /**
     * Why the issue counts as resolved, or null if it still needs escalating.
     *
     * @param  array<string, mixed>|null  $stopConditions
     */
    public function resolvedReason(AutomationExecution $execution, AutomationContext $context, ?array $stopConditions): ?string
    {
        $ruleActions = RecruiterAction::query()->where('automation_execution_id', $execution->id)->pluck('status');

        if ($ruleActions->isNotEmpty() && $ruleActions->every(fn (RecruiterActionStatus $status) => in_array($status, [RecruiterActionStatus::Completed, RecruiterActionStatus::Dismissed], true))) {
            return 'Resolved: the Action Center item was closed.';
        }

        if (! empty($stopConditions['rules'] ?? [])) {
            $result = $this->conditions->evaluate($stopConditions, $context);

            if ($result['passed']) {
                return 'Resolved: '.collect($result['results'])->where('passed', true)->map(fn (array $row) => "{$row['label']} {$row['operator']} {$row['expected']}")->implode('; ');
            }
        }

        return null;
    }

    /**
     * Stops every still-pending step of an execution (audited once).
     */
    public function stopRemaining(AutomationExecution $execution, string $reason): int
    {
        /** @var Collection<int, AutomationEscalation> $pending */
        $pending = $execution->escalations()->where('status', EscalationStatus::Pending)->get();

        foreach ($pending as $escalation) {
            $escalation->forceFill(['status' => EscalationStatus::Stopped, 'outcome' => $reason, 'processed_at' => now()])->save();
        }

        if ($pending->isNotEmpty()) {
            AuditLog::record($execution, 'automation_escalation_stopped', null, ['reason' => $reason, 'steps' => $pending->pluck('step')->all()]);
        }

        return $pending->count();
    }

    /**
     * @param  array<string, mixed>  $step
     */
    private function send(AutomationEscalation $escalation, AutomationExecution $execution, AutomationContext $context, array $step): AutomationEscalation
    {
        $recipient = $this->recipients->resolve($escalation->target, $context);

        if ($recipient === null) {
            return $this->close($escalation, EscalationStatus::Failed, 'No active recipient found for "'.RecipientResolver::label($escalation->target).'".');
        }

        $priority = ActionPriority::tryFrom((string) ($step['priority'] ?? 'high')) ?? ActionPriority::High;
        $ruleName = $execution->rule?->name ?? 'Automation rule';
        $candidate = $context->candidate()?->full_name;
        $body = filled($step['message'] ?? null)
            ? (string) $step['message']
            : trim(($candidate !== null ? "{$candidate}: " : '')."\"{$ruleName}\" is still unresolved (recruiter: ".($context->recruiter()?->fullName() ?? 'unassigned').').');

        $this->notifications->alert(
            $recipient->user,
            'Escalation',
            "Escalation: {$ruleName}",
            $body,
            $priority->notificationColor(),
            AutomationLinks::for($context->subject),
            "automation-escalation-{$escalation->id}",
            $priority,
            ['rule_id' => $execution->automation_rule_id, 'execution_id' => $execution->id, 'entity_type' => class_basename($context->subject), 'entity_id' => $context->subject->getKey()],
        );

        $action = null;

        if ((bool) ($step['create_action'] ?? false)) {
            $action = $this->actions->createOnce("escalation:{$escalation->id}", [
                'title' => "Escalated: {$ruleName}",
                'action_type' => RecruiterActionType::tryFrom((string) ($step['action_type'] ?? '')) ?? RecruiterActionType::ReviewApplication,
                'priority' => $priority,
                'owner_id' => $recipient->id,
                'candidate_id' => $context->candidate()?->id,
                'candidate_application_id' => $context->application()?->id,
                'requisition_id' => $context->requisition()?->id,
                'subject_type' => $context->subject->getMorphClass(),
                'subject_id' => $context->subject->getKey(),
                'reason' => $body,
                'due_at' => now()->addDay(),
                'automation_rule_id' => $execution->automation_rule_id,
                'automation_execution_id' => $execution->id,
            ]);
        }

        // Phase 8.7 (SEC-87-06): a candidate message from an escalation needs the owner's send
        // permission and counts toward the candidate's daily automated-message cap.
        if (filled($step['candidate_template'] ?? null) && ($messageContext = $context->messageContext()) !== null && (bool) $execution->rule?->owner?->can('communications.send')) {
            try {
                app(SendCommunicationAction::class)->sendWithinDailyCap((string) $step['candidate_template'], $messageContext, "automation:{$execution->id}:escalation:{$escalation->step}");
            } catch (Throwable $e) {
                report($e);
            }
        }

        $escalation->forceFill([
            'recipient_employee_id' => $recipient->id,
            'recipient_user_id' => $recipient->user?->id,
            'recruiter_action_id' => $action?->id,
        ]);

        $escalated = $this->close($escalation, EscalationStatus::Sent, "Escalated to {$recipient->fullName()} (".RecipientResolver::label($escalation->target).')');

        AuditLog::record($execution, 'automation_escalated', null, ['step' => $escalation->step, 'target' => $escalation->target, 'recipient_employee_id' => $recipient->id]);

        return $escalated;
    }

    private function close(AutomationEscalation $escalation, EscalationStatus $status, string $outcome): AutomationEscalation
    {
        $escalation->forceFill(['status' => $status, 'outcome' => $outcome, 'processed_at' => now()])->save();

        return $escalation;
    }
}
