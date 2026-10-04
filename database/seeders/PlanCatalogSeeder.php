<?php

namespace Database\Seeders;

use App\Services\Platform\Commercial\PlanCatalogService;
use Illuminate\Database\Seeder;

/**
 * SaaS-3: the plan catalog for development and test environments — plan identities, versions and
 * what each grants (no prices). Idempotent: an existing version is verified, never changed.
 */
class PlanCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $result = app(PlanCatalogService::class)->sync();

        $this->command?->info('Plan catalog: '.(count($result['published']) > 0 ? 'published '.implode(', ', $result['published']) : 'already up to date').'.');
    }
}
