<?php

namespace App\Console\Commands;

use App\Services\PerformanceEngine;
use Carbon\CarbonImmutable;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Refreshes the monthly RecruiterPerformanceSnapshot cache for every active recruiter. Without
 * --month it refreshes the current month, and on the 1st of a month also finalizes the previous
 * month so late activity from its last day is captured.
 */
#[Signature('performance:snapshot {--month= : The month to snapshot, as YYYY-MM (defaults to the current month)}')]
#[Description('Recompute monthly performance snapshots for all active recruiters')]
class SnapshotRecruiterPerformance extends Command
{
    public function handle(PerformanceEngine $engine): int
    {
        $monthOption = $this->option('month');

        if ($monthOption !== null) {
            if (! is_string($monthOption) || preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $monthOption) !== 1) {
                $this->error('The --month option must be in YYYY-MM format, e.g. 2026-09.');

                return self::FAILURE;
            }

            $months = [CarbonImmutable::createFromFormat('!Y-m', $monthOption)];
        } else {
            $currentMonth = CarbonImmutable::now()->startOfMonth();
            $months = CarbonImmutable::now()->day === 1
                ? [$currentMonth->subMonthNoOverflow(), $currentMonth]
                : [$currentMonth];
        }

        foreach ($months as $month) {
            $count = $engine->snapshotAllRecruiters($month->startOfMonth(), $month->endOfMonth());

            $this->info("Snapshotted performance for {$count} recruiter(s) for {$month->format('F Y')}.");
        }

        return self::SUCCESS;
    }
}
