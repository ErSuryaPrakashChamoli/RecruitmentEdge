<?php

namespace App\Console\Commands;

use App\Filament\Pages\QueueHealth;
use App\Services\PlatformAlertService;
use App\Services\QueueHealthService;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantDirectory;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Phase 8.7 (D8.7-028): checks the queue, stuck work and the scheduler against the alert
 * thresholds, and raises each problem as an in-app alert to the platform administrators — once
 * per problem per hour. Exits non-zero while anything needs attention, so external monitoring
 * can watch it too. Read-only apart from the alerts.
 *
 * SaaS-1: two passes. The platform pass (no tenant: every queue, worker, the scheduler) is logged
 * as platform.alert — no tenant's administrators are its audience. The tenant pass alerts each
 * tenant's settings.manage holders about that tenant's own failed jobs, backlog and stuck work.
 */
#[Signature('queue:health-check')]
#[Description('Alert platform administrators about failed jobs, queue backlogs, stuck work and a silent scheduler')]
class QueueHealthCheck extends Command
{
    public function handle(QueueHealthService $health, PlatformAlertService $alerts, TenantDirectory $tenants): int
    {
        $context = TenantContext::current();

        foreach ($tenants->forBackgroundWork() as $tenant) {
            $context->run($tenant, function () use ($health, $alerts): void {
                foreach ($health->tenantProblems() as $key => $problem) {
                    $alerts->raise($key, 'Queue health needs attention', $problem, QueueHealth::getUrl());
                }
            });
        }

        $problems = $context->runWithoutTenant(fn (): array => $health->problems());

        foreach ($problems as $key => $problem) {
            $context->runWithoutTenant(fn (): int => $alerts->raise($key, 'Queue health needs attention', $problem));
            $this->warn($problem);
        }

        if ($problems === []) {
            $this->info('Queue health OK.');
        }

        return $problems === [] ? self::SUCCESS : self::FAILURE;
    }
}
