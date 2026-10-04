<?php

namespace App\Services\Platform\Commercial;

use Database\Seeders\RecruitmentReferenceDataSeeder;
use Database\Seeders\RolePermissionSeeder;

/**
 * SaaS-3: what every tenant starts with, in the current tenant — the default roles and their
 * permissions, and the recruitment reference data the product needs to work (candidate sources,
 * rejection reasons, settings, stages, pipelines, the standard offer letter). The same idempotent
 * definitions the development seeders use: running them again creates nothing twice and never
 * overwrites a value the tenant changed. No demo data, no people.
 */
class TenantDefaults
{
    public function apply(): void
    {
        (new RolePermissionSeeder)->run();
        (new RecruitmentReferenceDataSeeder)->run();
    }
}
