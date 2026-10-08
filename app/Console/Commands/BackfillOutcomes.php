<?php

namespace App\Console\Commands;

use App\Services\Outcomes\OutcomeBackfillService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * Outcome Loop™ (Phase 8.2): reconstructs deterministic outcomes of past hiring (joined / no-show /
 * dropout, offer decisions, time to hire, time in stage) labelled BACKFILLED_DETERMINISTIC. Dry run
 * unless --execute. Never backfills retention or reads employee status history. Idempotent.
 */
#[Signature('outcomes:backfill
    {--execute : Record outcomes (without it, only counts what would be recorded)}
    {--from= : Only events on or after this date (YYYY-MM-DD)}
    {--to= : Only events on or before this date (YYYY-MM-DD)}
    {--requisition= : Only this requisition id}
    {--batch=200 : Records per chunk}')]
#[Description('Backfill deterministic Outcome Loop outcomes for past hiring (dry run by default; never retention)')]
class BackfillOutcomes extends Command
{
    public function handle(OutcomeBackfillService $backfill): int
    {
        try {
            $from = filled($this->option('from')) ? CarbonImmutable::parse((string) $this->option('from'))->startOfDay() : null;
            $to = filled($this->option('to')) ? CarbonImmutable::parse((string) $this->option('to'))->endOfDay() : null;
        } catch (Throwable) {
            $this->error('--from and --to must be dates (YYYY-MM-DD).');

            return self::FAILURE;
        }

        $requisition = filled($this->option('requisition')) ? (int) $this->option('requisition') : null;
        $execute = (bool) $this->option('execute');

        $this->info($execute ? 'Backfilling deterministic outcomes (BACKFILLED_DETERMINISTIC).' : 'DRY RUN — nothing will be recorded. Pass --execute to record.');
        $this->line('Retention is never backfilled: status observations are recorded going forward only.');

        $counts = $backfill->run($execute, $from, $to, $requisition, (int) $this->option('batch'), function (string $step, int $total) use ($execute) {
            $this->line(sprintf('  %s: %d %s', str_replace('_', ' ', $step), $total, $execute ? 'to record' : 'would be recorded'));

            if (! $execute || $total === 0) {
                return null;
            }

            $bar = $this->output->createProgressBar($total);
            $bar->start();

            return function () use ($bar, $total): void {
                $bar->advance();

                if ($bar->getProgress() >= $total) {
                    $bar->finish();
                    $this->newLine();
                }
            };
        });

        if (! $execute) {
            $this->line('Process outcomes are also recorded for each new snapshot when executed.');
        }

        $this->table(['Step', $execute ? 'Recorded' : 'Due'], collect($counts)->map(fn (int $count, string $step) => [str_replace('_', ' ', $step), $count])->values()->all());
        $this->table(['Current outcomes by capture', 'Count'], collect($backfill->captureModeTotals())->map(fn (int $count, string $mode) => [strtoupper($mode), $count])->values()->all());

        return self::SUCCESS;
    }
}
