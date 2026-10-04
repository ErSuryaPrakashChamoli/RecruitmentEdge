<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Platform\Commercial\PlanAssignmentService;
use DomainException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * SaaS-3: platform staff change a tenant's plan (effective now, audited). Never a payment: SaaS-4.
 */
#[Signature('tenants:plan
    {slug : The tenant}
    {plan : Plan code (its latest published version)}
    {--reason= : Why (required)}
    {--internal : Allow an internal plan (e.g. legacy)}')]
#[Description('Assign a plan to a tenant (platform)')]
class TenantsPlan extends Command
{
    public function handle(PlanAssignmentService $plans): int
    {
        $tenant = Tenant::query()->where('slug', (string) $this->argument('slug'))->first();

        if ($tenant === null) {
            $this->error('No such tenant.');

            return self::FAILURE;
        }

        try {
            $assignment = $plans->assign($tenant, $plans->latestVersion((string) $this->argument('plan')), 'platform', (string) $this->option('reason'), allowInternal: (bool) $this->option('internal'));
        } catch (DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("{$tenant->slug} is on {$assignment->planVersion->label()}.");

        return self::SUCCESS;
    }
}
