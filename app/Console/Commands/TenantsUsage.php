<?php

namespace App\Console\Commands;

use App\Enums\EntitlementType;
use App\Models\Tenant;
use App\Services\Entitlements\EntitlementService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * SaaS-3: read-only report — every tenant's (or one tenant's) usage against its limits, flagging
 * any over its limit (a downgrade leaves existing records in place). Usage is counted from the
 * records each time, so there is no counter to drift or reconcile; this is the check.
 */
#[Signature('tenants:usage {slug? : One tenant}')]
#[Description('Report usage against plan limits per tenant (read-only, platform)')]
class TenantsUsage extends Command
{
    public function handle(): int
    {
        $tenants = Tenant::query()->when($this->argument('slug'), fn ($query, $slug) => $query->where('slug', $slug))->orderBy('id')->get();
        $rows = [];

        foreach ($tenants as $tenant) {
            TenantContext::current()->run($tenant, function () use ($tenant, &$rows): void {
                foreach (app(EntitlementService::class)->overview() as $line) {
                    if ($line['entitlement']->type() !== EntitlementType::Limit) {
                        continue;
                    }

                    $effective = $line['effective'];
                    $limit = $effective->unlimited ? 'unlimited' : ($effective->enabled ? (string) $effective->limit : 'none');
                    $over = $effective->enabled && ! $effective->unlimited && $line['usage'] > (int) $effective->limit;

                    $rows[] = [$tenant->slug, $tenant->effectiveStatus()->value, $line['entitlement']->value, $line['usage'], $limit, $over ? 'OVER LIMIT' : ''];
                }
            });
        }

        $this->table(['tenant', 'status', 'limit', 'used', 'allowed', ''], $rows);

        return self::SUCCESS;
    }
}
