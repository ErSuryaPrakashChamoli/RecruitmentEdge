<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Platform\Commercial\TenantLifecycleService;
use DomainException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * SaaS-3: platform staff move a tenant through its lifecycle (the transition table; audited).
 * Nothing here deletes data.
 *
 * SaaS-5: deletion is a workflow (request, a second operator's approval, a grace period, a purge —
 * tenants:deletion or the platform panel); a tenant is no longer marked deletion-pending or deleted
 * by hand, which would skip the approval, the grace period or the purge.
 */
#[Signature('tenants:lifecycle
    {slug : The tenant}
    {action : activate, suspend, past-due, cancel or extend-trial}
    {--days= : Days to extend a trial by}
    {--reason= : Why (required)}')]
#[Description('Change a tenant\'s lifecycle state (platform)')]
class TenantsLifecycle extends Command
{
    public function handle(TenantLifecycleService $lifecycle): int
    {
        $tenant = Tenant::query()->where('slug', (string) $this->argument('slug'))->first();

        if ($tenant === null) {
            $this->error('No such tenant.');

            return self::FAILURE;
        }

        $reason = (string) $this->option('reason');

        try {
            $tenant = match ((string) $this->argument('action')) {
                'activate' => $lifecycle->activate($tenant, $reason),
                'suspend' => $lifecycle->suspend($tenant, $reason),
                'past-due' => $lifecycle->markPastDue($tenant, $reason),
                'cancel' => $lifecycle->cancel($tenant, $reason),
                'extend-trial' => $lifecycle->extendTrial($tenant, (int) $this->option('days'), $reason),
                'deletion-pending', 'deleted' => throw new DomainException('Deletion is a workflow: use tenants:deletion (request, approval, grace period, purge).'),
                default => throw new DomainException('Unknown action.'),
            };
        } catch (DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("{$tenant->slug} is {$tenant->status->label()}.");

        return self::SUCCESS;
    }
}
