<?php

namespace App\Services\Automation;

use App\Enums\AutomationExecutionStatus;
use App\Enums\EscalationStatus;
use App\Models\AutomationActionExecution;
use App\Models\AutomationEscalation;
use App\Models\AutomationExecution;
use App\Models\AutomationRule;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Automation health (Phase 6): Healthy / Warning / Failed per active rule and overall, from
 * observable signals only — failure rate, missing templates, unconfigured providers, unresolved
 * recipients, excessive retries, prevented loops and execution backlog. Statistics are computed
 * with grouped queries for all rules at once (no per-rule query loops).
 */
class AutomationHealthService
{
    public const string HEALTHY = 'healthy';

    public const string WARNING = 'warning';

    public const string FAILED = 'failed';

    public const int WINDOW_DAYS = 7;

    public const int MIN_RUNS_FOR_RATE = 5;

    public function __construct(private readonly AutomationRuleValidator $validator) {}

    /**
     * @return array{status: string, signals: array<int, array{severity: string, message: string, rule_id: int|null}>, backlog: int, queued_jobs: int|null, rules: Collection<int, array{rule: AutomationRule, status: string, signals: array<int, array{severity: string, message: string, rule_id: int|null}>}>}
     */
    public function overview(): array
    {
        $rules = AutomationRule::query()->active()->orderBy('name')->get();
        $stats = $this->statsFor($rules->pluck('id')->all());

        $ruleHealth = $rules->map(function (AutomationRule $rule) use ($stats) {
            $signals = $this->signalsFor($rule, $stats[$rule->id] ?? []);

            return ['rule' => $rule, 'status' => $this->worst($signals), 'signals' => $signals];
        });

        $backlog = AutomationExecution::query()
            ->where('status', AutomationExecutionStatus::Pending)
            ->where('scheduled_for', '<', now()->subMinutes(30))
            ->count();

        $queuedJobs = config('queue.default') === 'database'
            ? DB::table(config('queue.connections.database.table', 'jobs'))->where('queue', config('automation.queue', 'automation'))->count()
            : null;

        $signals = $ruleHealth->flatMap(fn (array $row) => $row['signals'])->values()->all();

        if ($backlog > 0) {
            $signals[] = ['severity' => self::WARNING, 'message' => "{$backlog} automation run(s) are more than 30 minutes overdue — check that the scheduler and the automation queue worker are running.", 'rule_id' => null];
        }

        return [
            'status' => $this->worst($signals),
            'signals' => $signals,
            'backlog' => $backlog,
            'queued_jobs' => $queuedJobs,
            'rules' => $ruleHealth,
        ];
    }

    /**
     * @return array{status: string, signals: array<int, array{severity: string, message: string, rule_id: int|null}>}
     */
    public function forRule(AutomationRule $rule): array
    {
        $signals = $this->signalsFor($rule, $this->statsFor([$rule->id])[$rule->id] ?? []);

        return ['status' => $this->worst($signals), 'signals' => $signals];
    }

    /**
     * @param  array{finished?: int, failed?: int, retried?: int, loops?: int, no_recipient?: int}  $stats
     * @return array<int, array{severity: string, message: string, rule_id: int|null}>
     */
    private function signalsFor(AutomationRule $rule, array $stats): array
    {
        $signals = [];
        $signal = fn (string $severity, string $message): array => ['severity' => $severity, 'message' => "{$rule->name}: {$message}", 'rule_id' => $rule->id];

        $finished = $stats['finished'] ?? 0;
        $failed = $stats['failed'] ?? 0;

        if ($finished >= self::MIN_RUNS_FOR_RATE) {
            $rate = $failed / $finished;

            if ($rate > 0.5) {
                $signals[] = $signal(self::FAILED, round($rate * 100).'% of runs failed in the last '.self::WINDOW_DAYS.' days.');
            } elseif ($rate > 0.2) {
                $signals[] = $signal(self::WARNING, round($rate * 100).'% of runs failed in the last '.self::WINDOW_DAYS.' days.');
            }
        }

        foreach (array_values($rule->actions ?? []) as $index => $action) {
            if (($action['type'] ?? null) !== 'send_communication') {
                continue;
            }

            foreach ($this->validator->missingTemplateErrors((string) ($action['template_key'] ?? ''), (array) ($action['channels'] ?? []), 'Action '.($index + 1)) as $error) {
                $signals[] = $signal(self::FAILED, 'Invalid template — '.$error);
            }

            foreach ($this->validator->unconfiguredProviderErrors((array) ($action['channels'] ?? []), 'Action '.($index + 1)) as $error) {
                $signals[] = $signal(self::WARNING, 'Provider unavailable — '.$error);
            }
        }

        if (($stats['no_recipient'] ?? 0) > 0) {
            $signals[] = $signal(self::WARNING, "{$stats['no_recipient']} action(s)/escalation(s) found no active recipient in the last ".self::WINDOW_DAYS.' days.');
        }

        if (($stats['retried'] ?? 0) > 0) {
            $signals[] = $signal(self::WARNING, "{$stats['retried']} run(s) needed 3 or more retries.");
        }

        if (($stats['loops'] ?? 0) > 0) {
            $signals[] = $signal(self::WARNING, "{$stats['loops']} run(s) were stopped by loop prevention — check whether this rule's actions re-trigger it.");
        }

        return $signals;
    }

    /**
     * @param  array<int, int>  $ruleIds
     * @return array<int, array{finished: int, failed: int, retried: int, loops: int, no_recipient: int}>
     */
    private function statsFor(array $ruleIds): array
    {
        if ($ruleIds === []) {
            return [];
        }

        $since = now()->subDays(self::WINDOW_DAYS);

        $runs = AutomationExecution::query()
            ->whereIn('automation_rule_id', $ruleIds)
            ->where('created_at', '>=', $since)
            ->selectRaw('automation_rule_id,
                sum(case when status in (?, ?, ?) then 1 else 0 end) as finished,
                sum(case when status = ? then 1 else 0 end) as failed,
                sum(case when retry_count >= 3 then 1 else 0 end) as retried,
                sum(case when skip_reason like ? then 1 else 0 end) as loops', [
                AutomationExecutionStatus::Completed->value, AutomationExecutionStatus::PartiallyCompleted->value, AutomationExecutionStatus::Failed->value,
                AutomationExecutionStatus::Failed->value,
                'Loop prevented%',
            ])
            ->groupBy('automation_rule_id')
            ->get()
            ->keyBy('automation_rule_id');

        $noRecipientActions = AutomationActionExecution::query()
            ->join('automation_executions', 'automation_executions.id', '=', 'automation_action_executions.automation_execution_id')
            ->whereIn('automation_executions.automation_rule_id', $ruleIds)
            ->where('automation_action_executions.created_at', '>=', $since)
            ->where('automation_action_executions.error', 'like', 'No active %')
            ->selectRaw('automation_executions.automation_rule_id as rule_id, count(*) as total')
            ->groupBy('automation_executions.automation_rule_id')
            ->pluck('total', 'rule_id');

        $noRecipientEscalations = AutomationEscalation::query()
            ->whereIn('automation_rule_id', $ruleIds)
            ->where('status', EscalationStatus::Failed)
            ->where('created_at', '>=', $since)
            ->selectRaw('automation_rule_id, count(*) as total')
            ->groupBy('automation_rule_id')
            ->pluck('total', 'automation_rule_id');

        return collect($ruleIds)->mapWithKeys(fn (int $id) => [$id => [
            'finished' => (int) ($runs[$id]->finished ?? 0),
            'failed' => (int) ($runs[$id]->failed ?? 0),
            'retried' => (int) ($runs[$id]->retried ?? 0),
            'loops' => (int) ($runs[$id]->loops ?? 0),
            'no_recipient' => (int) ($noRecipientActions[$id] ?? 0) + (int) ($noRecipientEscalations[$id] ?? 0),
        ]])->all();
    }

    /**
     * @param  array<int, array{severity: string}>  $signals
     */
    private function worst(array $signals): string
    {
        $severities = array_column($signals, 'severity');

        return match (true) {
            in_array(self::FAILED, $severities, true) => self::FAILED,
            in_array(self::WARNING, $severities, true) => self::WARNING,
            default => self::HEALTHY,
        };
    }
}
