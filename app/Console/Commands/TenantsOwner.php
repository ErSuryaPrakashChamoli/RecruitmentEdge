<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Models\User;
use App\Services\Platform\TenantOwnershipService;
use DomainException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * SaaS-5: shows or transfers a tenant's ownership, as a named platform administrator (the same
 * rules as the platform panel: an active member holding the CHRO role; atomic; audited).
 */
#[Signature('tenants:owner
    {slug : The tenant}
    {email? : The new owner (omit to show the current owner)}
    {--operator= : The platform administrator\'s email}
    {--reason= : Why}')]
#[Description('Show or transfer a tenant\'s ownership (platform)')]
class TenantsOwner extends Command
{
    public function handle(TenantOwnershipService $ownership): int
    {
        $tenant = Tenant::query()->where('slug', (string) $this->argument('slug'))->first();

        if ($tenant === null) {
            $this->error('No such tenant.');

            return self::FAILURE;
        }

        if ($this->argument('email') === null) {
            $owner = $ownership->owner($tenant);
            $this->line($owner === null ? "{$tenant->slug}: no owner recorded." : "{$tenant->slug}: {$owner->name} <{$owner->email}>");

            return self::SUCCESS;
        }

        $newOwner = User::query()->where('email', strtolower(trim((string) $this->argument('email'))))->first();
        $operator = User::query()->where('email', strtolower(trim((string) $this->option('operator'))))->first();

        try {
            if ($newOwner === null || $operator === null) {
                throw new DomainException('Name an existing member as the new owner, and the platform administrator with --operator=<email>.');
            }

            $ownership->transfer($tenant, $newOwner, (string) $this->option('reason'), $operator);
        } catch (DomainException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("{$tenant->slug} is now owned by {$newOwner->email}.");

        return self::SUCCESS;
    }
}
