<?php

namespace App\Console\Commands;

use App\Models\AutomationRule;
use App\Services\Automation\AutomationEngine;
use App\Services\Automation\AutomationEventRegistry;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Time-based automation sweep (Phase 6): for each Active rule with a schedule trigger, runs the
 * trigger's targeted query (bounded by --limit and the rule's scope) and creates executions under
 * idempotency keys — so repeated runs never duplicate work. The actions themselves are queued.
 * Scheduled every 15 minutes in routes/console.php.
 */
#[Signature('recruitment:automation:dispatch {--rule= : Only this rule (id or key)} {--entity= : Only this record id} {--limit= : Maximum records per rule} {--dry-run : Report what would be matched without creating anything}')]
#[Description('Find records that time-based automation rules apply to and queue their executions')]
class DispatchAutomationRules extends Command
{
    public function handle(AutomationEngine $engine, AutomationEventRegistry $events): int
    {
        $limit = max(1, (int) ($this->option('limit') ?: config('automation.dispatch_limit_per_rule', 500)));
        $scheduledTriggers = $events->all()->filter->isScheduled()->keys();

        $rules = AutomationRule::query()
            ->active()
            ->whereIn('trigger', $scheduledTriggers)
            ->with('currentVersion')
            ->when($this->option('rule'), fn ($q, $rule) => is_numeric($rule) ? $q->whereKey($rule) : $q->where('key', $rule))
            ->orderBy('priority')
            ->get();

        $totalMatched = 0;
        $totalCreated = 0;

        foreach ($rules as $rule) {
            $result = $engine->sweep($rule, $limit, (bool) $this->option('dry-run'), $this->option('entity') !== null ? (int) $this->option('entity') : null);
            $totalMatched += $result['matched'];
            $totalCreated += $result['created'];

            if ($this->output->isVerbose() || $this->option('dry-run')) {
                $this->line("{$rule->key}: {$result['matched']} matched, {$result['created']} new execution(s)");
            }
        }

        $this->info(($this->option('dry-run') ? '[dry run] ' : '')."{$rules->count()} time-based rule(s): {$totalMatched} record(s) matched, {$totalCreated} execution(s) queued.");

        return self::SUCCESS;
    }
}
