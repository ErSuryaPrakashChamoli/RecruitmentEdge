<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Frozen copy of the internal "legacy" plan, version 1, as of this migration: every capability
     * the product had before SaaS-3, with no limits. Existing tenants are pinned to it so nothing
     * they can do today changes. Which commercial plan they move to is an owner decision
     * (docs/saas-3-decision-register.md); this migration makes none.
     *
     * @var array<string, array{enabled: bool, limit_value: int|null, is_unlimited: bool}>
     */
    private const LEGACY_V1 = [
        'ai.assistant' => ['enabled' => true, 'limit_value' => null, 'is_unlimited' => false],
        'automation.rules' => ['enabled' => true, 'limit_value' => null, 'is_unlimited' => false],
        'distribution.job_boards' => ['enabled' => true, 'limit_value' => null, 'is_unlimited' => false],
        'exports.data' => ['enabled' => true, 'limit_value' => null, 'is_unlimited' => false],
        'requisitions.active.max' => ['enabled' => true, 'limit_value' => null, 'is_unlimited' => true],
        'members.active.max' => ['enabled' => true, 'limit_value' => null, 'is_unlimited' => true],
    ];

    /**
     * SaaS-3 (backfill + validate, step 2 of 2): creates the legacy plan v1 if missing and gives
     * every tenant without a current plan assignment one (source "migration"). Idempotent; creates
     * nothing twice; changes no tenant's status. Refuses to finish unless every tenant has exactly
     * one current assignment.
     */
    public function up(): void
    {
        $now = now();

        $planId = DB::table('plans')->where('code', 'legacy')->value('id') ?? DB::table('plans')->insertGetId([
            'code' => 'legacy',
            'name' => 'Legacy (all features, no limits)',
            'description' => 'Internal: every capability that existed before plans, without limits. Not offered to new customers.',
            'status' => 'internal',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $versionId = DB::table('plan_versions')->where('plan_id', $planId)->where('version', 1)->value('id') ?? DB::table('plan_versions')->insertGetId([
            'plan_id' => $planId,
            'version' => 1,
            'status' => 'published',
            'published_at' => $now,
            'notes' => 'Initial version (SaaS-3 migration).',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        foreach (self::LEGACY_V1 as $key => $value) {
            if (DB::table('plan_entitlements')->where('plan_version_id', $versionId)->where('key', $key)->doesntExist()) {
                DB::table('plan_entitlements')->insert(['plan_version_id' => $versionId, 'key' => $key, ...$value, 'created_at' => $now, 'updated_at' => $now]);
            }
        }

        DB::table('tenants')->orderBy('id')->each(function (object $tenant) use ($versionId, $now): void {
            if (DB::table('tenant_plan_assignments')->where('tenant_id', $tenant->id)->where('is_current', true)->exists()) {
                return;
            }

            DB::table('tenant_plan_assignments')->insert([
                'tenant_id' => $tenant->id,
                'plan_version_id' => $versionId,
                'is_current' => true,
                'effective_from' => $now,
                'source' => 'migration',
                'reason' => 'Existing tenant pinned to the legacy plan (SaaS-3)',
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            DB::table('tenants')->where('id', $tenant->id)->update(['provisioned_at' => $tenant->created_at ?? $now]);
        });

        $unassigned = DB::table('tenants')
            ->whereNotExists(fn ($query) => $query->from('tenant_plan_assignments')->whereColumn('tenant_plan_assignments.tenant_id', 'tenants.id')->where('is_current', true))
            ->count();

        if ($unassigned > 0) {
            throw new RuntimeException("SaaS-3 plan backfill did not validate: {$unassigned} tenant(s) without a current plan assignment.");
        }
    }

    /**
     * Structural rollback only (the tables go in step 1). A data rollback is a backup restore.
     */
    public function down(): void {}
};
