<?php

namespace Tests\Feature\Commercial;

use App\Enums\Entitlement;
use App\Enums\TenantStatus;
use App\Models\Employee;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Platform\Commercial\PlanCatalogService;
use App\Services\Tenancy\TenantContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;

/**
 * SaaS-3 commercial fixtures (prompt §42), beside the test's own tenant (legacy plan):
 *
 * - trialStarter (A): Trial, 14 days left, Starter v1 — 10 active requisitions, 5 seats, no AI,
 *   no automation
 * - growth (B): Active, Growth v1 — 50 requisitions, 25 seats, AI, automation
 * - enterprise (C): Active, Enterprise v1 — everything, unlimited
 * - suspended (D): Suspended, Growth v1 (its admin joined while it was active)
 * - unentitled (E): Active, on a test plan whose version does not mention the AI assistant or
 *   automation at all — missing keys, which must be denied (never read as unlimited)
 *
 * Each has a CHRO ("admin") with an employee record, created inside it.
 */
final class CommercialWorld
{
    /** @var array<string, Tenant> */
    public array $tenants = [];

    /** @var array<string, User> */
    public array $admins = [];

    public static function build(): self
    {
        app(PlanCatalogService::class)->sync();
        $world = new self;

        $world->tenants['trialStarter'] = Tenant::factory()->trial(14)->onPlan('starter')->create(['slug' => 'alpha-trial', 'name' => 'Alpha Trial']);
        $world->tenants['growth'] = Tenant::factory()->onPlan('growth')->create(['slug' => 'bravo-growth', 'name' => 'Bravo Growth']);
        $world->tenants['enterprise'] = Tenant::factory()->onPlan('enterprise')->create(['slug' => 'charlie-ent', 'name' => 'Charlie Enterprise']);
        $world->tenants['suspended'] = Tenant::factory()->onPlan('growth')->create(['slug' => 'delta-suspended', 'name' => 'Delta Suspended']);
        $world->tenants['unentitled'] = Tenant::factory()->onPlan(self::partialPlan())->create(['slug' => 'echo-none', 'name' => 'Echo None']);

        foreach ($world->tenants as $key => $tenant) {
            $world->admins[$key] = TenantContext::current()->run($tenant, function () use ($tenant): User {
                (new RolePermissionSeeder)->run();

                // Seats are counted from memberships: the admin's own is created directly (fixture).
                return User::factory()->create(['email' => "admin@{$tenant->slug}.test", 'employee_id' => Employee::factory()->create()->id])->assignRole('chro');
            });
        }

        Tenant::query()->whereKey($world->tenants['suspended']->id)->update(['status' => TenantStatus::Suspended->value, 'status_reason' => 'commercial']);
        $world->tenants['suspended'] = $world->tenants['suspended']->fresh();

        return $world;
    }

    /**
     * A plan version that never mentions two registry keys (written directly: the catalog sync
     * refuses incomplete versions, which is exactly why this can only be a fixture).
     */
    private static function partialPlan(): string
    {
        if (DB::table('plans')->where('code', 'partial-test')->doesntExist()) {
            $planId = DB::table('plans')->insertGetId(['code' => 'partial-test', 'name' => 'Partial (test)', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            $versionId = DB::table('plan_versions')->insertGetId(['plan_id' => $planId, 'version' => 1, 'status' => 'published', 'published_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

            foreach ([Entitlement::DistributionJobBoards, Entitlement::ExportsData] as $feature) {
                DB::table('plan_entitlements')->insert(['plan_version_id' => $versionId, 'key' => $feature->value, 'enabled' => true, 'limit_value' => null, 'is_unlimited' => false, 'created_at' => now(), 'updated_at' => now()]);
            }

            foreach ([Entitlement::RequisitionsActiveMax, Entitlement::MembersActiveMax] as $limit) {
                DB::table('plan_entitlements')->insert(['plan_version_id' => $versionId, 'key' => $limit->value, 'enabled' => true, 'limit_value' => 20, 'is_unlimited' => false, 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        return 'partial-test';
    }

    public function tenant(string $key): Tenant
    {
        return $this->tenants[$key]->fresh();
    }

    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function in(string $key, callable $callback): mixed
    {
        return TenantContext::current()->run($this->tenants[$key]->fresh(), $callback);
    }
}
