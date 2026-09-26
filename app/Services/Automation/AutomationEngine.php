<?php

namespace App\Services\Automation;

use App\Enums\AutomationActionStatus;
use App\Enums\AutomationExecutionStatus;
use App\Enums\AutomationFailureBehavior;
use App\Enums\EscalationStatus;
use App\Jobs\RunAutomationExecutionJob;
use App\Models\AuditLog;
use App\Models\AutomationActionExecution;
use App\Models\AutomationExecution;
use App\Models\AutomationRule;
use App\Models\User;
use App\Services\Automation\Actions\ActionOutcome;
use App\Services\Automation\Data\TriggerDefinition;
use Carbon\CarbonInterface;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The automation execution engine (Phase 6): EVENT → CONDITION → ACTION → ESCALATION → AUDIT.
 *
 * 1. `trigger()` (from TriggerAutomationRules, synchronously after commit) or `sweep()` (from the
 *    dispatch command) finds Active rules for the trigger, checks window and scope, applies loop
 *    prevention and creates one execution per rule + entity under a unique idempotency key.
 * 2. Due executions run on the automation queue (`run()`): lock → re-check rule, record, anchor,
 *    cooldown and limits → evaluate conditions against fresh state → run each action through its
 *    handler (own result row, failure behaviour) → schedule escalation → audit.
 *
 * Everything runs against the rule *version* the execution was created with, so history keeps its
 * meaning after edits. Nothing here writes domain data directly — handlers call the owning engines.
 */
class AutomationEngine
{
    public const string ACTIVE_TRIGGERS_CACHE_KEY = 'automation:active-triggers';

    public function __construct(
        private readonly AutomationEventRegistry $events,
        private readonly AutomationFieldRegistry $fields,
        private readonly ConditionEvaluator $conditions,
        private readonly AutomationActionRegistry $actions,
        private readonly AutomationRuntime $runtime,
        private readonly AutomationScopeResolver $scopes,
        private readonly EscalationService $escalations,
    ) {}

    public static function forgetActiveTriggers(): void
    {
        Cache::forget(self::ACTIVE_TRIGGERS_CACHE_KEY);
    }

    /**
     * Entry point for domain events. Returns the number of executions created.
     */
    public function handleEvent(object $event): int
    {
        $mapped = $this->events->fromEvent($event);

        return $mapped === null ? 0 : $this->trigger(...$mapped);
    }

    /**
     * @param  array<string, scalar|null>  $eventData
     */
    public function trigger(string $trigger, Model $subject, array $eventData = []): int
    {
        if (! in_array($trigger, $this->activeTriggers(), true)) {
            return 0;
        }

        $created = 0;

        foreach ($this->activeRulesFor($trigger) as $rule) {
            if (! $rule->isEffectiveAt(now())) {
                continue;
            }

            $context = AutomationContext::for($subject, $trigger, $eventData);

            if (! $this->scopes->matches($rule, $context)) {
                continue;
            }

            $timing = $rule->currentVersion?->snapshot['timing'] ?? $rule->timing ?? [];
            [$runAt, $anchorAt, $anchorField] = $this->scheduleFor($timing, $context);
            $discriminator = sha1(json_encode($eventData).'|'.$subject->updated_at?->format('U').'|'.$anchorAt?->format('U'));

            $execution = $this->createExecution($rule, $context, $trigger, $discriminator, $runAt, $anchorAt, $anchorField);
            $created += $execution !== null ? 1 : 0;
        }

        return $created;
    }

    /**
     * Finds the entities a schedule-trigger rule applies to right now and creates their executions.
     *
     * @return array{matched: int, created: int}
     */
    public function sweep(AutomationRule $rule, int $limit, bool $dryRun = false, ?int $entityId = null): array
    {
        $definition = $this->events->find($rule->trigger);

        if ($definition === null || ! $definition->isScheduled() || ! $rule->isActive() || ! $rule->isEffectiveAt(now())) {
            return ['matched' => 0, 'created' => 0];
        }

        $timing = $rule->currentVersion?->snapshot['timing'] ?? $rule->timing ?? [];
        $model = new $definition->subjectClass;

        // Records this rule has run for least recently come first, so a bounded sweep never keeps
        // re-reading the same already-handled records while newer ones wait.
        $lastRun = AutomationExecution::query()
            ->selectRaw('max(id)')
            ->where('automation_rule_id', $rule->id)
            ->where('subject_type', $model->getMorphClass())
            ->whereColumn('subject_id', $model->getQualifiedKeyName());

        $query = $this->scopes->constrain(($definition->sweep)($this->thresholdFor($definition, $timing)), $rule)
            ->when($entityId !== null, fn ($q) => $q->whereKey($entityId))
            ->orderByRaw('('.$lastRun->toSql().') is not null', $lastRun->getBindings())
            ->orderBy($lastRun)
            ->orderBy($model->getQualifiedKeyName())
            ->limit($limit);

        $subjects = $query->get();

        if ($dryRun) {
            return ['matched' => $subjects->count(), 'created' => 0];
        }

        $created = 0;
        $repeatHours = (int) ($timing['repeat_every_hours'] ?? 0);
        $bucket = $repeatHours > 0 ? (string) intdiv(now()->getTimestamp(), $repeatHours * 3600) : '';

        foreach ($subjects as $subject) {
            $anchorAt = $definition->anchor !== null ? ($definition->anchor)($subject) : null;
            $context = AutomationContext::for($subject, $rule->trigger, anchorAt: $anchorAt);
            $discriminator = sha1(($anchorAt?->format('U') ?? '').'|'.$bucket);

            $created += $this->createExecution($rule, $context, $rule->trigger, $discriminator, now(), $anchorAt, 'trigger') !== null ? 1 : 0;
        }

        return ['matched' => $subjects->count(), 'created' => $created];
    }

    /**
     * Runs one execution (normally from RunAutomationExecutionJob).
     */
    public function run(int $executionId): ?AutomationExecution
    {
        $execution = DB::transaction(function () use ($executionId): ?AutomationExecution {
            $execution = AutomationExecution::query()->lockForUpdate()->find($executionId);

            if ($execution === null || $execution->status !== AutomationExecutionStatus::Pending) {
                return null;
            }

            $execution->forceFill(['status' => AutomationExecutionStatus::Running, 'started_at' => now()])->save();

            return $execution;
        });

        if ($execution === null) {
            return null;
        }

        try {
            $this->perform($execution);
        } catch (Throwable $e) {
            report($e);
            $this->finish($execution, AutomationExecutionStatus::Failed, failure: mb_substr($e->getMessage(), 0, 500));
        }

        return $execution->refresh();
    }

    /**
     * Re-runs the failed actions of a failed or partially completed execution (completed actions are
     * never repeated).
     */
    public function retry(AutomationExecution $execution, ?User $actor = null): AutomationExecution
    {
        if (! $execution->isRetryable()) {
            throw new DomainException('Only failed or partially completed executions can be retried.');
        }

        DB::transaction(function () use ($execution): void {
            $execution->actionExecutions()->where('status', AutomationActionStatus::Failed)->update(['status' => AutomationActionStatus::Pending, 'error' => null]);
            $execution->forceFill([
                'status' => AutomationExecutionStatus::Pending,
                'retry_count' => $execution->retry_count + 1,
                'failure_reason' => null,
                'completed_at' => null,
                'scheduled_for' => now(),
            ])->save();
        });

        AuditLog::record($execution, 'automation_retried', null, ['retry_count' => $execution->retry_count, 'by_user_id' => $actor?->id]);
        RunAutomationExecutionJob::dispatch($execution->id);

        return $execution->refresh();
    }

    /**
     * Cancels a pending execution and any still-pending escalation of it.
     */
    public function cancel(AutomationExecution $execution, ?User $actor = null, string $reason = 'Cancelled manually'): AutomationExecution
    {
        $pendingEscalations = $execution->escalations()->where('status', EscalationStatus::Pending)->exists();

        if ($execution->status !== AutomationExecutionStatus::Pending && ! $pendingEscalations) {
            throw new DomainException('Only a pending execution (or one with pending escalations) can be cancelled.');
        }

        if ($execution->status === AutomationExecutionStatus::Pending) {
            $execution->forceFill(['status' => AutomationExecutionStatus::Cancelled, 'skip_reason' => $reason, 'completed_at' => now()])->save();
        }

        $this->escalations->stopRemaining($execution, $reason.($actor !== null ? " by {$actor->name}" : ''));
        AuditLog::record($execution, 'automation_cancelled', null, ['reason' => $reason, 'by_user_id' => $actor?->id]);

        return $execution->refresh();
    }

    /**
     * Dispatches due pending executions and fails runs whose worker died. Bounded.
     *
     * @return array{dispatched: int, stale_failed: int, stale_cancelled: int}
     */
    public function processDue(int $limit): array
    {
        $staleFailed = AutomationExecution::query()
            ->where('status', AutomationExecutionStatus::Running)
            ->where('started_at', '<', now()->subMinutes((int) config('automation.stale_running_minutes', 60)))
            ->update(['status' => AutomationExecutionStatus::Failed, 'failure_reason' => 'The run was interrupted (worker stopped) — retry it to finish the remaining actions.', 'completed_at' => now()]);

        $staleCancelled = AutomationExecution::query()
            ->where('status', AutomationExecutionStatus::Pending)
            ->where('scheduled_for', '<', now()->subDays((int) config('automation.stale_pending_days', 7)))
            ->update(['status' => AutomationExecutionStatus::Cancelled, 'skip_reason' => 'Not run in time (too far past its scheduled time).', 'completed_at' => now()]);

        $due = AutomationExecution::query()
            ->where('status', AutomationExecutionStatus::Pending)
            ->where('scheduled_for', '<=', now())
            ->orderBy('scheduled_for')
            ->limit($limit)
            ->pluck('id');

        $due->each(fn (int $id) => RunAutomationExecutionJob::dispatch($id));

        return ['dispatched' => $due->count(), 'stale_failed' => $staleFailed, 'stale_cancelled' => $staleCancelled];
    }

    /**
     * What the rule's current (possibly unsaved) configuration would do for $subject — conditions,
     * timing, actions and escalation — with no side effects beyond an audit entry.
     *
     * @return array{trigger: string, scope_matches: bool, scope: string, runs_at: string, conditions: array{passed: bool, results: array<int, array<string, mixed>>}, would_run: bool, actions: array<int, array{label: string, description: string, errors: array<int, string>}>, escalation: array<int, array{step: int, after: string, target: string, recipient: string|null}>, limits: array<int, string>}
     */
    public function dryRun(AutomationRule $rule, Model $subject, ?User $actor = null): array
    {
        $definition = $this->events->find($rule->trigger);
        $anchorAt = $definition?->anchor !== null ? ($definition->anchor)($subject) : null;
        $context = AutomationContext::for($subject, $rule->trigger, anchorAt: $anchorAt);
        $scopeMatches = $this->scopes->matches($rule, $context);
        $conditionResult = $this->conditions->evaluate($rule->conditions, $context);
        [$runAt] = $definition?->isScheduled() ? [now()] : $this->scheduleFor($rule->timing ?? [], $context);

        $actions = collect($rule->actions ?? [])->values()->map(function (array $action) use ($context) {
            $handler = $this->actions->find((string) ($action['type'] ?? ''));

            return [
                'label' => $handler?->label() ?? 'Unknown action',
                'description' => $handler?->describe($action, $context) ?? 'Unknown action type',
                'errors' => $handler?->validate($action) ?? ['Unknown action type'],
            ];
        })->all();

        $resolver = app(RecipientResolver::class);
        $escalation = collect($rule->escalation['steps'] ?? [])->values()->map(fn (array $step, int $index) => [
            'step' => $index + 1,
            'after' => AutomationTime::describe((int) ($step['after'] ?? 0), (string) ($step['unit'] ?? 'hours')),
            'target' => RecipientResolver::label((string) ($step['target'] ?? '')),
            'recipient' => $resolver->resolve((string) ($step['target'] ?? ''), $context)?->fullName(),
        ])->all();

        $limits = $this->limitNotes($rule, $subject);

        AuditLog::record($rule, 'automation_dry_run', null, ['subject_type' => class_basename($subject), 'subject_id' => $subject->getKey(), 'conditions_passed' => $conditionResult['passed'], 'by_user_id' => $actor?->id]);

        return [
            'trigger' => $definition?->label ?? $rule->trigger,
            'scope_matches' => $scopeMatches,
            'scope' => $this->scopes->describe($rule),
            'runs_at' => $runAt === null ? 'Never — the timing date is not set on this record' : ($runAt->isFuture() ? $runAt->toDayDateTimeString() : 'Immediately'),
            'conditions' => $conditionResult,
            'would_run' => $scopeMatches && $conditionResult['passed'] && $runAt !== null && $limits === [],
            'actions' => $actions,
            'escalation' => $escalation,
            'limits' => $limits,
        ];
    }

    /**
     * @return array<int, string>
     */
    public function activeTriggers(): array
    {
        return Cache::rememberForever(self::ACTIVE_TRIGGERS_CACHE_KEY, fn () => AutomationRule::query()->active()->distinct()->pluck('trigger')->all());
    }

    /**
     * @return Collection<int, AutomationRule>
     */
    private function activeRulesFor(string $trigger): Collection
    {
        return AutomationRule::query()
            ->active()
            ->where('trigger', $trigger)
            ->with('currentVersion')
            ->orderBy('priority')
            ->orderBy('id')
            ->get();
    }

    /**
     * @param  array<string, mixed>  $timing
     */
    private function thresholdFor(TriggerDefinition $definition, array $timing): CarbonInterface
    {
        $amount = (int) ($timing['amount'] ?? 24);
        $unit = (string) ($timing['unit'] ?? 'hours');

        return $definition->thresholdIsLookahead ? AutomationTime::ahead($amount, $unit) : AutomationTime::ago($amount, $unit);
    }

    /**
     * When an event rule should run: now, or a delay after the event, or a delay before/after a date
     * on the record (e.g. 24 hours before the interview). Returns [run at, anchor date, anchor
     * field]; run at is null when the anchor date is missing.
     *
     * @param  array<string, mixed>  $timing
     * @return array{0: CarbonInterface|null, 1: CarbonInterface|null, 2: string|null}
     */
    private function scheduleFor(array $timing, AutomationContext $context): array
    {
        if (($timing['mode'] ?? 'immediate') !== 'delay') {
            return [now(), null, null];
        }

        $amount = (int) ($timing['amount'] ?? 0);
        $unit = (string) ($timing['unit'] ?? 'hours');
        $anchor = (string) ($timing['anchor'] ?? 'event');
        $before = ($timing['direction'] ?? 'after') === 'before';

        if ($anchor === 'event') {
            return [AutomationTime::shift($context->occurredAt, $amount, $unit), null, null];
        }

        $field = $this->fields->find($anchor);
        $value = $field !== null ? $this->fields->resolve($field, $context) : null;

        if ($value === null) {
            return [null, null, $anchor];
        }

        $anchorAt = Carbon::parse($value);
        $runAt = AutomationTime::shift($anchorAt, $amount, $unit, forward: ! $before);

        return [$runAt->isPast() ? now() : $runAt, $anchorAt, $anchor];
    }

    private function createExecution(AutomationRule $rule, AutomationContext $context, string $trigger, string $discriminator, ?CarbonInterface $runAt, ?CarbonInterface $anchorAt, ?string $anchorField): ?AutomationExecution
    {
        $version = $rule->currentVersion;
        $depth = $this->runtime->depth();
        $subject = $context->subject;
        $key = implode(':', [$rule->id, $version?->version ?? 0, $trigger, $subject->getMorphClass(), $subject->getKey(), $discriminator]);

        $unsafe = match (true) {
            $depth > 0 && in_array($rule->id, $this->runtime->ruleChain(), true) => 'Loop prevented: this rule is already running earlier in the same automation chain.',
            $depth >= (int) config('automation.max_chain_depth', 3) => 'Loop prevented: maximum automation chain depth ('.config('automation.max_chain_depth', 3).') reached.',
            $runAt === null => 'Not scheduled: the timing date ('.$anchorField.') is not set on this record.',
            default => null,
        };

        try {
            $execution = AutomationExecution::query()->create([
                'automation_rule_id' => $rule->id,
                'automation_rule_version_id' => $version?->id,
                'trigger' => $trigger,
                'subject_type' => $subject->getMorphClass(),
                'subject_id' => $subject->getKey(),
                'candidate_application_id' => $context->application()?->id,
                'recruiter_id' => $context->application()?->recruiter_id,
                'idempotency_key' => $unsafe !== null ? mb_substr($key.':'.sha1($unsafe), 0, 191) : mb_substr($key, 0, 191),
                'status' => $unsafe !== null ? AutomationExecutionStatus::Skipped : AutomationExecutionStatus::Pending,
                'scheduled_for' => $runAt ?? now(),
                'triggered_at' => $context->occurredAt,
                'context' => [...$context->toArray(), 'anchor_at' => $anchorAt?->toIso8601String(), 'anchor_field' => $anchorField],
                'depth' => $depth,
                'parent_execution_id' => $this->runtime->parentExecutionId(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        if ($unsafe !== null) {
            $execution->forceFill(['skip_reason' => $unsafe, 'completed_at' => now()])->save();

            if (str_starts_with($unsafe, 'Loop prevented')) {
                AuditLog::record($execution, 'automation_loop_prevented', null, ['rule_id' => $rule->id, 'depth' => $depth, 'chain' => $this->runtime->ruleChain()]);
            }

            return $execution;
        }

        if ($execution->scheduled_for->lte(now())) {
            RunAutomationExecutionJob::dispatch($execution->id);
        }

        return $execution;
    }

    private function perform(AutomationExecution $execution): void
    {
        $rule = $execution->rule;
        $snapshot = $execution->ruleVersion?->snapshot ?? $rule?->configuration() ?? [];

        if ($rule === null || ! $rule->isActive() || ! $rule->isEffectiveAt(now())) {
            $this->finish($execution, AutomationExecutionStatus::Cancelled, skip: 'The rule was paused, archived or is outside its effective dates.');

            return;
        }

        $context = AutomationContext::fromExecution($execution);

        if ($context === null) {
            $this->finish($execution, AutomationExecutionStatus::Cancelled, skip: 'The record no longer exists.');

            return;
        }

        if (($stale = $this->anchorChanged($execution, $context)) !== null) {
            $this->finish($execution, AutomationExecutionStatus::Skipped, skip: $stale);

            return;
        }

        if (($limit = $this->limitReason($execution, $snapshot)) !== null) {
            $this->finish($execution, AutomationExecutionStatus::Skipped, skip: $limit);

            return;
        }

        $result = $this->conditions->evaluate($snapshot['conditions'] ?? null, $context);
        $execution->forceFill(['conditions_passed' => $result['passed'], 'condition_results' => $result['results']])->save();

        if (! $result['passed']) {
            $this->finish($execution, AutomationExecutionStatus::Skipped, skip: 'Conditions not met.');

            return;
        }

        $this->runtime->within($execution, fn () => $this->runActions($execution, $context, $snapshot));

        $statuses = $execution->actionExecutions()->pluck('status');
        $failed = $statuses->filter(fn (AutomationActionStatus $status) => $status === AutomationActionStatus::Failed)->count();
        $completed = $statuses->filter(fn (AutomationActionStatus $status) => $status === AutomationActionStatus::Completed)->count();

        $status = match (true) {
            $failed === 0 => AutomationExecutionStatus::Completed,
            $completed > 0 => AutomationExecutionStatus::PartiallyCompleted,
            default => AutomationExecutionStatus::Failed,
        };

        $errors = $execution->actionExecutions()->where('status', AutomationActionStatus::Failed)->pluck('error')->filter()->implode(' | ');
        $this->finish($execution, $status, failure: $errors !== '' ? mb_substr($errors, 0, 1000) : null);

        if ($status !== AutomationExecutionStatus::Failed && ! empty($snapshot['escalation']['steps'] ?? []) && $execution->escalations()->doesntExist()) {
            $this->escalations->schedule($execution, $snapshot['escalation'], now());
        }

        AuditLog::record($execution, 'automation_executed', null, [
            'rule_id' => $execution->automation_rule_id,
            'rule_version' => $execution->ruleVersion?->version,
            'status' => $status->value,
            'actions' => $execution->actionExecutions()->get(['action_type', 'status'])->map(fn (AutomationActionExecution $row) => "{$row->action_type}:{$row->status->value}")->all(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function runActions(AutomationExecution $execution, AutomationContext $context, array $snapshot): void
    {
        $stopOnFailure = ($snapshot['failure_behavior'] ?? 'continue') === AutomationFailureBehavior::Stop->value;
        $halted = false;

        foreach (array_values($snapshot['actions'] ?? []) as $position => $config) {
            $row = AutomationActionExecution::query()->firstOrCreate(
                ['automation_execution_id' => $execution->id, 'position' => $position],
                ['action_type' => (string) ($config['type'] ?? 'unknown')],
            );

            if ($row->status === AutomationActionStatus::Completed || $row->status === AutomationActionStatus::Skipped) {
                continue;
            }

            if ($halted) {
                $row->forceFill(['status' => AutomationActionStatus::Skipped, 'summary' => 'Not run: an earlier action failed and this rule stops on failure.'])->save();

                continue;
            }

            $handler = $this->actions->find((string) ($config['type'] ?? ''));

            try {
                $outcome = $handler !== null ? $handler->execute($config, $context, $execution, $position) : ActionOutcome::failed('Unknown action type.');
            } catch (Throwable $e) {
                report($e);
                $outcome = ActionOutcome::failed(mb_substr($e->getMessage(), 0, 500));
            }

            $row->forceFill([
                'status' => $outcome->status,
                'summary' => mb_substr($outcome->summary, 0, 250),
                'error' => $outcome->error,
                'attempts' => $row->attempts + 1,
                'target_type' => $outcome->target?->getMorphClass(),
                'target_id' => $outcome->target?->getKey(),
                'completed_at' => now(),
            ])->save();

            $halted = $outcome->status === AutomationActionStatus::Failed && $stopOnFailure;
        }
    }

    /**
     * A time-based run is dropped when the date it was timed against has since changed (e.g. the
     * interview was rescheduled) — the new date gets its own run.
     */
    private function anchorChanged(AutomationExecution $execution, AutomationContext $context): ?string
    {
        $stored = $execution->context['anchor_at'] ?? null;
        $field = $execution->context['anchor_field'] ?? null;

        if ($stored === null || $field === null) {
            return null;
        }

        if ($field === 'trigger') {
            $anchor = $this->events->find($execution->trigger)?->anchor;
            $current = $anchor !== null ? $anchor($context->subject) : null;
        } else {
            $definition = $this->fields->find($field);
            $current = $definition !== null ? $this->fields->resolve($definition, $context) : null;
        }

        return $current !== null && Carbon::parse($current)->equalTo(Carbon::parse($stored))
            ? null
            : 'The record changed since this run was scheduled (for example, it was rescheduled).';
    }

    /**
     * @param  array<string, mixed>  $snapshot
     */
    private function limitReason(AutomationExecution $execution, array $snapshot): ?string
    {
        $sameEntity = AutomationExecution::query()
            ->where('automation_rule_id', $execution->automation_rule_id)
            ->where('subject_type', $execution->subject_type)
            ->where('subject_id', $execution->subject_id)
            ->whereKeyNot($execution->id)
            ->whereIn('status', [AutomationExecutionStatus::Completed, AutomationExecutionStatus::PartiallyCompleted]);

        $cooldown = (int) ($snapshot['cooldown_minutes'] ?? 0);

        if ($cooldown > 0 && (clone $sameEntity)->where('completed_at', '>=', now()->subMinutes($cooldown))->exists()) {
            return "Cooldown: this rule already ran for this record within the last {$cooldown} minutes.";
        }

        $perEntity = (int) ($snapshot['max_executions_per_entity'] ?? 0);

        if ($perEntity > 0 && (clone $sameEntity)->count() >= $perEntity) {
            return "Limit: this rule already ran {$perEntity} time(s) for this record.";
        }

        $daily = min(
            (int) ($snapshot['max_executions_per_day'] ?? 0) ?: PHP_INT_MAX,
            (int) config('automation.max_executions_per_rule_per_day', 500),
        );

        $today = AutomationExecution::query()
            ->where('automation_rule_id', $execution->automation_rule_id)
            ->whereKeyNot($execution->id)
            ->whereIn('status', [AutomationExecutionStatus::Completed, AutomationExecutionStatus::PartiallyCompleted, AutomationExecutionStatus::Failed, AutomationExecutionStatus::Running])
            ->where('started_at', '>=', now()->startOfDay())
            ->count();

        return $today >= $daily ? "Daily limit: this rule already ran {$today} time(s) today (limit {$daily})." : null;
    }

    /**
     * @return array<int, string>
     */
    private function limitNotes(AutomationRule $rule, Model $subject): array
    {
        $cooldown = (int) $rule->cooldown_minutes;

        if ($cooldown > 0 && AutomationExecution::query()
            ->where('automation_rule_id', $rule->id)
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->whereIn('status', [AutomationExecutionStatus::Completed, AutomationExecutionStatus::PartiallyCompleted])
            ->where('completed_at', '>=', now()->subMinutes($cooldown))
            ->exists()) {
            return ["Cooldown: already ran for this record within the last {$cooldown} minutes."];
        }

        return [];
    }

    private function finish(AutomationExecution $execution, AutomationExecutionStatus $status, ?string $skip = null, ?string $failure = null): void
    {
        $execution->forceFill([
            'status' => $status,
            'skip_reason' => $skip,
            'failure_reason' => $failure,
            'completed_at' => now(),
        ])->save();
    }
}
