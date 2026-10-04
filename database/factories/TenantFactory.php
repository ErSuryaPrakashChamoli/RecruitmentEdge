<?php

namespace Database\Factories;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use App\Services\Platform\Commercial\PlanCatalogService;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->slug(2),
            'name' => fake()->company(),
            'status' => TenantStatus::Active,
            'timezone' => 'Asia/Kolkata',
            'locale' => 'en',
            'currency' => 'INR',
            'country' => 'IN',
            'provisioned_at' => now(),
        ];
    }

    public function status(TenantStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }

    /**
     * SaaS-3: pinned to a catalog plan (its latest published version) instead of the legacy plan.
     * Plans other than the migration-created legacy plan are published first (PlanCatalogService).
     */
    public function onPlan(string $code): static
    {
        return $this->afterCreating(fn (Tenant $tenant) => self::pin($tenant, $code));
    }

    /**
     * SaaS-3: no plan at all — every entitlement is denied.
     */
    public function withoutPlan(): static
    {
        return $this->afterCreating(fn (Tenant $tenant) => DB::table('tenant_plan_assignments')->where('tenant_id', $tenant->id)->delete());
    }

    public function trial(int $daysLeft = 14): static
    {
        return $this->state(fn (): array => ['status' => TenantStatus::Trial, 'trial_started_at' => now()->subDays(14 - min($daysLeft, 14)), 'trial_ends_at' => now()->addDays($daysLeft)]);
    }

    /**
     * SaaS-1/3: a tenant the way provisioning leaves it — with its current plan (legacy unless
     * onPlan()/withoutPlan() say otherwise).
     */
    public function configure(): static
    {
        return $this->afterCreating(fn (Tenant $tenant) => self::pin($tenant, 'legacy'));
    }

    /**
     * Fixture data, not a commercial change: written directly (no audit, no lock), replacing the
     * tenant's current assignment.
     */
    private static function pin(Tenant $tenant, string $code): void
    {
        $version = fn (): mixed => DB::table('plan_versions')->join('plans', 'plans.id', '=', 'plan_versions.plan_id')
            ->where('plans.code', $code)->where('plan_versions.status', 'published')->orderByDesc('plan_versions.version')->value('plan_versions.id');
        $versionId = $version();

        if ($versionId === null && $code !== 'legacy') {
            app(PlanCatalogService::class)->sync();
            $versionId = $version();
        }

        if ($versionId === null) {
            throw new LogicException("No published plan \"{$code}\".");
        }

        DB::table('tenant_plan_assignments')->where('tenant_id', $tenant->id)->delete();
        DB::table('tenant_plan_assignments')->insert([
            'tenant_id' => $tenant->id,
            'plan_version_id' => $versionId,
            'is_current' => true,
            'effective_from' => now(),
            'source' => 'factory',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('tenants')->where('id', $tenant->id)->increment('entitlement_version');
        $tenant->refresh();
    }
}
