<?php

namespace App\Console\Commands;

use App\Services\Automation\AutomationEngine;
use App\Services\Automation\EscalationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Hands due automation work to the queue (Phase 6): delayed executions whose time has come, and
 * escalation steps that are due (each re-checks its stop condition first). Also fails runs whose
 * worker died and cancels runs far past their time. Bounded by --limit; scheduled every 5 minutes.
 */
#[Signature('recruitment:automation:process {--limit= : Maximum executions and escalations per run}')]
#[Description('Dispatch due automation executions and process due escalation steps')]
class ProcessAutomationQueue extends Command
{
    public function handle(AutomationEngine $engine, EscalationService $escalations): int
    {
        $limit = max(1, (int) ($this->option('limit') ?: config('automation.process_limit', 200)));

        $result = $engine->processDue($limit);
        $escalated = $escalations->processDue($limit);

        $this->info("Dispatched {$result['dispatched']} due execution(s), processed {$escalated} escalation step(s); {$result['stale_failed']} interrupted run(s) marked failed, {$result['stale_cancelled']} overdue run(s) cancelled.");

        return self::SUCCESS;
    }
}
