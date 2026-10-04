<?php

namespace App\Console\Commands;

use App\Services\Platform\Commercial\TenantLifecycleService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * SaaS-3: platform task (hourly) — records ended trials and reports unfinished provisioning. It
 * reads only the platform's tenants table; an ended trial is already Suspended in effect.
 */
#[Signature('tenants:lifecycle-sweep')]
#[Description('Record ended trials and report stale provisioning (platform)')]
class TenantsLifecycleSweep extends Command
{
    public function handle(TenantLifecycleService $lifecycle): int
    {
        $result = $lifecycle->sweep();

        $this->info("Trials expired: {$result['expired']}. Provisioning older than an hour: {$result['stale_provisioning']}.");

        return self::SUCCESS;
    }
}
