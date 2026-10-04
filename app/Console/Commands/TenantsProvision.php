<?php

namespace App\Console\Commands;

use App\Services\Platform\Commercial\ProvisioningRequest;
use App\Services\Platform\Commercial\TenantProvisioningService;
use DomainException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

/**
 * SaaS-3: platform staff provision a tenant. Idempotent: the same request again returns (or
 * finishes) the same tenant.
 */
#[Signature('tenants:provision
    {slug : The tenant\'s URL slug (unique)}
    {--name= : Organisation name}
    {--owner= : The initial owner\'s email (invited as CHRO and owner)}
    {--owner-name= : The owner\'s name}
    {--plan= : Plan code (its latest published version)}
    {--trial= : Start a trial of this many days instead of activating}
    {--legal-name=}
    {--timezone=Asia/Kolkata}
    {--locale=en}
    {--currency=INR}
    {--country=IN}')]
#[Description('Provision a tenant: defaults, plan, owner invitation, then trial or active (platform)')]
class TenantsProvision extends Command
{
    public function handle(TenantProvisioningService $provisioning): int
    {
        try {
            $tenant = $provisioning->provision(new ProvisioningRequest(
                slug: (string) $this->argument('slug'),
                name: (string) $this->option('name'),
                ownerEmail: (string) $this->option('owner'),
                planCode: (string) $this->option('plan'),
                trialDays: filled($this->option('trial')) ? (int) $this->option('trial') : null,
                ownerName: $this->option('owner-name'),
                legalName: $this->option('legal-name'),
                timezone: (string) $this->option('timezone'),
                locale: (string) $this->option('locale'),
                currency: (string) $this->option('currency'),
                country: (string) $this->option('country'),
            ));
        } catch (DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('Provisioning failed and can be retried: '.$e::class);

            return self::FAILURE;
        }

        $this->info("Tenant {$tenant->slug} is {$tenant->status->label()}".($tenant->trial_ends_at !== null ? ' until '.$tenant->trial_ends_at->toDateString() : '').'.');

        return self::SUCCESS;
    }
}
