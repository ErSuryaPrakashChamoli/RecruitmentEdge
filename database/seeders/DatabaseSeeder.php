<?php

namespace Database\Seeders;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Services\Platform\Commercial\PlanAssignmentService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Deliberately does NOT use WithoutModelEvents: EmployeeObserver relies on Eloquent's
     * created/updated events firing to keep the hierarchy closure table in sync.
     *
     * SaaS-1: the organisation is seeded as one tenant (config/tenancy.php tenant_one), created
     * explicitly here; the platform-level AI evaluation suite is seeded outside any tenant.
     */
    public function run(): void
    {
        $tenant = Tenant::query()->firstOrCreate(['slug' => (string) config('tenancy.tenant_one.slug')], [
            'name' => (string) config('tenancy.tenant_one.name'),
            'legal_name' => config('tenancy.tenant_one.legal_name'),
            'status' => TenantStatus::Active,
            'timezone' => (string) config('tenancy.tenant_one.timezone'),
            'locale' => (string) config('tenancy.tenant_one.locale'),
            'currency' => (string) config('tenancy.tenant_one.currency'),
            'country' => (string) config('tenancy.tenant_one.country'),
        ]);

        // SaaS-3: the plan catalog, and Tenant #1 on the internal legacy plan (every capability,
        // no limits — what it had before plans existed).
        $this->call(PlanCatalogSeeder::class);

        if ($tenant->planAssignments()->withoutTenancy()->where('is_current', true)->doesntExist()) {
            app(PlanAssignmentService::class)->assign($tenant, app(PlanAssignmentService::class)->latestVersion('legacy'), 'seeder', 'Development install', allowInternal: true);
        }

        TenantContext::current()->run($tenant, fn () => $this->call([
            RolePermissionSeeder::class,
            OrganizationSeeder::class,
            AdminUserSeeder::class,
            RecruitmentReferenceDataSeeder::class,
        ]));

        $this->call(AiEvaluationSeeder::class);
    }
}
