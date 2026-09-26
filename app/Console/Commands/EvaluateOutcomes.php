<?php

namespace App\Console\Commands;

use App\Services\Outcomes\OutcomeEvaluator;
use App\Services\Outcomes\OutcomeLearningService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Outcome Loop™ (Phase 8.2), daily: records any deterministic outcome not yet captured (joining,
 * offer, process) and the status observations that have fallen due, then recalculates learning
 * insights for human review (never applied automatically). Idempotent; no AI.
 */
#[Signature('outcomes:evaluate {--dry-run : Report what is due without recording anything}')]
#[Description('Record due Outcome Loop outcomes and status observations (deterministic, idempotent)')]
class EvaluateOutcomes extends Command
{
    public function handle(OutcomeEvaluator $evaluator, OutcomeLearningService $learning): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $counts = $evaluator->evaluate($dryRun);

        $this->info(($dryRun ? '[dry run] due: ' : 'Evaluated: ').collect($counts)->map(fn (int $n, string $key) => str_replace('_', ' ', $key).' '.$n)->implode(', ').'.');

        if (! $dryRun) {
            $insights = $learning->refresh();
            $this->info("Learning insights: {$insights['created']} new, {$insights['updated']} refreshed, {$insights['expired']} expired (all await human review).");
        }

        return self::SUCCESS;
    }
}
