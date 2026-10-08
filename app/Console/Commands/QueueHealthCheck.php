<?php

namespace App\Console\Commands;

use App\Enums\PlatformEventSeverity;
use App\Filament\Pages\QueueHealth;
use App\Services\Platform\PlatformEvents;
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
 *
 * SaaS-7 (C5, C8): the tenant pass is one grouped query set for all tenants (problemsByTenant),
 * visiting only tenants that have a problem — no longer a full scan per tenant. The platform pass
 * records each problem as a platform event (the operators' feed; critical ones are mailed at once
 * to PLATFORM_NOTIFY_EMAIL, never through the queue being reported on), deduplicated per hour.
 */
#[Signature('queue:health-check')]
#[Description('Alert platform administrators about failed jobs, queue backlogs, stuck work and a silent scheduler')]
class QueueHealthCheck extends Command
{
    public function handle(QueueHealthService $health, PlatformAlertService $alerts, TenantDirectory $tenants, PlatformEvents $events): int
    {
        $context = TenantContext::current();
        $byTenant = $context->runWithoutTenant(fn (): array => $health->problemsByTenant());

        foreach ($tenants->lazyForBackgroundWork(array_keys($byTenant)) as $tenant) {
            $context->run($tenant, function () use ($alerts, $byTenant, $tenant): void {
                foreach ($byTenant[$tenant->id] as $key => $problem) {
                    $alerts->raise($key, 'Queue health needs attention', $problem, QueueHealth::getUrl());
                }
            });
        }

        $problems = $context->runWithoutTenant(fn (): array => $health->problems());

        foreach ($problems as $key => $problem) {
            $critical = preg_match('/^(worker-silent|scheduler-silent|scheduled-task-|retry-after)/', $key) === 1;
            $context->runWithoutTenant(fn () => $events->record('platform.health', $critical ? PlatformEventSeverity::Critical : PlatformEventSeverity::Warning, mb_substr($problem, 0, 255), null, ['problem' => $key], 'health:'.$key.':'.now()->format('YmdH'), mailNow: true));
            $this->warn($problem);
        }

        if ($problems === []) {
            $this->info('Queue health OK.');
        }

        return $problems === [] ? self::SUCCESS : self::FAILURE;
    }
}
