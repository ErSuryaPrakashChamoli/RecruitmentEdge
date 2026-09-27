<?php

namespace App\Console\Commands;

use App\Filament\Pages\QueueHealth;
use App\Services\PlatformAlertService;
use App\Services\QueueHealthService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Phase 8.7 (D8.7-028): checks the queue, stuck work and the scheduler against the alert
 * thresholds, and raises each problem as an in-app alert to the platform administrators — once
 * per problem per hour. Exits non-zero while anything needs attention, so external monitoring
 * can watch it too. Read-only apart from the alerts.
 */
#[Signature('queue:health-check')]
#[Description('Alert platform administrators about failed jobs, queue backlogs, stuck work and a silent scheduler')]
class QueueHealthCheck extends Command
{
    public function handle(QueueHealthService $health, PlatformAlertService $alerts): int
    {
        $problems = $health->problems();

        foreach ($problems as $key => $problem) {
            $alerts->raise($key, 'Queue health needs attention', $problem, QueueHealth::getUrl());
            $this->warn($problem);
        }

        if ($problems === []) {
            $this->info('Queue health OK.');
        }

        return $problems === [] ? self::SUCCESS : self::FAILURE;
    }
}
