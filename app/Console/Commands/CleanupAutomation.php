<?php

namespace App\Console\Commands;

use App\Enums\AutomationExecutionStatus;
use App\Models\AutomationExecution;
use App\Services\RecruiterActionService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Daily automation housekeeping (Phase 6): moves open Action Center items away from inactive
 * owners, expires items long past due (both audited), and prunes old "conditions not met" runs,
 * which carry no action or audit value. Runs that did something are never pruned.
 */
#[Signature('recruitment:automation:cleanup {--dry-run : Report counts without changing anything}')]
#[Description('Reassign actions of inactive owners, expire stale actions, prune old skipped automation runs')]
class CleanupAutomation extends Command
{
    public function handle(RecruiterActionService $actions): int
    {
        $pruneBefore = now()->subDays((int) config('automation.prune_skipped_after_days', 90));
        $prunable = AutomationExecution::query()
            ->where('status', AutomationExecutionStatus::Skipped)
            ->where('conditions_passed', false)
            ->where('created_at', '<', $pruneBefore);

        if ($this->option('dry-run')) {
            $this->info("[dry run] {$prunable->count()} skipped execution(s) would be pruned.");

            return self::SUCCESS;
        }

        $reassigned = $actions->reassignFromInactiveOwners();
        $expired = $actions->expireOverdue((int) config('automation.action_expiry_days', 14));
        $pruned = $prunable->delete();

        $this->info("Reassigned {$reassigned} action(s) from inactive owners, expired {$expired} stale action(s), pruned {$pruned} skipped execution(s).");

        return self::SUCCESS;
    }
}
