<?php

namespace App\Console\Commands;

use App\Enums\Entitlement;
use App\Models\Tenant;
use App\Services\Platform\Commercial\EntitlementOverrideService;
use App\Services\Platform\Commercial\PlanCatalog;
use DomainException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * SaaS-3: platform staff set or remove a tenant's override of one entitlement (audited).
 */
#[Signature('tenants:entitlement
    {slug : The tenant}
    {key : A registry key, e.g. members.active.max}
    {value? : on / off for a feature; a number or "unlimited" for a limit}
    {--remove : Remove the current override instead}
    {--until= : When the override ends (date-time)}
    {--reason= : Why (required)}')]
#[Description('Set or remove a tenant entitlement override (platform)')]
class TenantsEntitlement extends Command
{
    public function handle(EntitlementOverrideService $overrides): int
    {
        $tenant = Tenant::query()->where('slug', (string) $this->argument('slug'))->first();
        $entitlement = Entitlement::tryFrom((string) $this->argument('key'));

        if ($tenant === null || $entitlement === null) {
            $this->error($tenant === null ? 'No such tenant.' : 'Unknown entitlement key.');

            return self::FAILURE;
        }

        try {
            if ($this->option('remove')) {
                $overrides->remove($tenant, $entitlement, (string) $this->option('reason'));
                $this->info("Override of {$entitlement->value} removed.");

                return self::SUCCESS;
            }

            $raw = (string) $this->argument('value');
            $value = match (true) {
                $raw === 'on' => true,
                $raw === 'off' => false,
                $raw === PlanCatalog::UNLIMITED => PlanCatalog::UNLIMITED,
                ctype_digit($raw) => (int) $raw,
                default => throw new DomainException('The value is on, off, a number or "unlimited".'),
            };

            $overrides->set($tenant, $entitlement, $value, (string) $this->option('reason'), filled($this->option('until')) ? Carbon::parse((string) $this->option('until')) : null);
        } catch (DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Override of {$entitlement->value} set to {$raw}.");

        return self::SUCCESS;
    }
}
