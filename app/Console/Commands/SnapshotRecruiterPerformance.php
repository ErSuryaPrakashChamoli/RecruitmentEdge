<?php

namespace App\Console\Commands;

use App\Models\AuditLog;
use App\Models\RecruiterPerformanceSnapshot;
use App\Services\Metrics\MetricPeriod;
use App\Services\PerformanceEngine;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Refreshes the monthly RecruiterPerformanceSnapshot cache for every active recruiter. Without
 * --month it refreshes the current month, and on the 1st of a month also finalizes the previous
 * month so late activity from its last day is captured. Phase 8.6 (D8.6-017): a month among the
 * last three whose freeze was missed (unfrozen snapshots remain) is finalised on the next run.
 *
 * Phase 8.5 (D48, D16): months are business-timezone calendar months; finalising a month freezes
 * its snapshots. A frozen month is only recomputed with --month, --force and a --reason, and each
 * recomputed snapshot is audited.
 */
#[Signature('performance:snapshot {--month= : The month to snapshot, as YYYY-MM (defaults to the current month)} {--force : Recompute a frozen (finalised) month} {--reason= : Why a frozen month is being recomputed (required with --force)}')]
#[Description('Recompute monthly performance snapshots for all active recruiters')]
class SnapshotRecruiterPerformance extends Command
{
    /**
     * How many completed months a missed freeze is caught up for.
     */
    public const int CATCH_UP_MONTHS = 3;

    public function handle(PerformanceEngine $engine): int
    {
        $monthOption = $this->option('month');
        $force = (bool) $this->option('force');

        if ($force && ($monthOption === null || blank($this->option('reason')))) {
            $this->error('--force recomputes a finalised month: give the --month and a --reason.');

            return self::FAILURE;
        }

        if ($monthOption !== null) {
            if (! is_string($monthOption) || preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $monthOption) !== 1) {
                $this->error('The --month option must be in YYYY-MM format, e.g. 2026-09.');

                return self::FAILURE;
            }

            $months = [CarbonImmutable::createFromFormat('!Y-m', $monthOption, MetricPeriod::timezone())];
            $finalise = [];
        } else {
            $currentMonth = MetricPeriod::now()->startOfMonth();
            // The month just ended is finalised on the 1st; Phase 8.6 (D8.6-017) also catches up
            // any of the last CATCH_UP_MONTHS whose freeze was missed (it still has unfrozen
            // snapshots — the daily run snapshots every month it runs in). Frozen months are never
            // recomputed here.
            $justEnded = $currentMonth->subMonthNoOverflow();
            $finalise = collect(range(self::CATCH_UP_MONTHS, 1))
                ->map(fn (int $back) => $currentMonth->subMonthsNoOverflow($back))
                ->filter(fn (CarbonImmutable $month) => ($month->equalTo($justEnded) && MetricPeriod::now()->day === 1) || $this->hasUnfrozenSnapshots($month))
                ->values()
                ->all();
            $months = [...$finalise, $currentMonth];
        }

        foreach ($months as $month) {
            $start = $month->startOfMonth()->startOfDay();
            $end = $month->endOfMonth()->startOfDay();

            if ($force) {
                $this->auditForcedRecompute($start, $end);
            }

            $count = $engine->snapshotAllRecruiters($start, $end, force: $force);

            $this->info("Snapshotted performance for {$count} recruiter(s) for {$month->format('F Y')}.");
        }

        foreach ($finalise as $month) {
            $frozen = $engine->freezePeriod($month->startOfMonth()->startOfDay(), $month->endOfMonth()->startOfDay());

            $this->info("Froze {$frozen} snapshot(s) for {$month->format('F Y')}.");
        }

        return self::SUCCESS;
    }

    private function hasUnfrozenSnapshots(CarbonImmutable $month): bool
    {
        return RecruiterPerformanceSnapshot::query()
            ->whereDate('period_start', $month->startOfMonth()->toDateString())
            ->whereNull('frozen_at')
            ->exists();
    }

    private function auditForcedRecompute(CarbonImmutable $start, CarbonImmutable $end): void
    {
        RecruiterPerformanceSnapshot::query()
            ->whereDate('period_start', $start)
            ->whereDate('period_end', $end)
            ->whereNotNull('frozen_at')
            ->each(fn (RecruiterPerformanceSnapshot $snapshot) => AuditLog::record($snapshot, 'performance_snapshot_recomputed', ['score' => $snapshot->score, 'frozen_at' => $snapshot->frozen_at?->toIso8601String()], ['reason' => mb_substr((string) $this->option('reason'), 0, 255)]));
    }
}
