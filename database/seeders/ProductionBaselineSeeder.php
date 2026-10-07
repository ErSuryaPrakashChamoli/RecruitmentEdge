<?php

namespace Database\Seeders;

use App\Enums\TenantStatus;
use App\Models\Role;
use App\Models\Tenant;
use App\Services\HierarchyMemo;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * The production-safe seeder, for after a deployment's migrations in case anything the application
 * relies on is missing: `php artisan db:seed --class=ProductionBaselineSeeder --force`. It only adds
 * what is missing — in every tenant that is not being deleted — and never changes or removes what
 * exists, so it can run on a live database any number of times.
 *
 * - Permissions: every default permission exists.
 * - Roles: a default role missing from a tenant (matched by its immutable key) is created with its
 *   default permissions. An existing role keeps exactly the permissions administrators gave it,
 *   except CHRO, which receives any permission it lacks (CHRO holds every permission).
 * - Reference data: RecruitmentReferenceDataSeeder (creates only what is missing), with new email
 *   templates as Draft, so no candidate is emailed until an administrator activates a template.
 * - Hierarchy: where the employee_hierarchy closure table disagrees with employees.reports_to_id,
 *   the wrong links are removed and the missing ones added.
 *
 * It never creates users, logins, passwords, role assignments, memberships or employees: those are
 * real people's, and the migrations carry them over. Never run DatabaseSeeder in production — it
 * resets every default role's permissions and creates a login with a default password.
 *
 * Deliberately does NOT use WithoutModelEvents: roles rebuild their tenant's permission cache on
 * their saved events.
 */
class ProductionBaselineSeeder extends Seeder
{
    public function run(): void
    {
        foreach (RolePermissionSeeder::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission);
        }

        $tenants = Tenant::query()
            ->whereNotIn('status', [TenantStatus::DeletionPending->value, TenantStatus::Deleted->value])
            ->orderBy('id')
            ->get();

        if ($tenants->isEmpty()) {
            $this->command?->warn('No tenant exists yet: only permissions were checked.');
        }

        foreach ($tenants as $tenant) {
            TenantContext::current()->run($tenant, function () use ($tenant): void {
                $this->ensureDefaultRoles($tenant);
                (new RecruitmentReferenceDataSeeder(activateEmailTemplates: false))->run();
                $this->repairHierarchy($tenant);
            });

            $this->command?->info("[{$tenant->slug}] Baseline checked.");
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Creates a missing default role with its default permissions; gives CHRO any permission it
     * lacks. A role created from Administration that already uses a default role's name, without
     * its key, is never claimed or changed (identity:audit reports it).
     */
    private function ensureDefaultRoles(Tenant $tenant): void
    {
        foreach (RolePermissionSeeder::defaultRolePermissions() as $key => $permissions) {
            $role = Role::byKey($key);

            if ($role === null && Role::query()->forCurrentTenant()->where('name', $key)->exists()) {
                $this->command?->warn("[{$tenant->slug}] A \"{$key}\" role exists without its key: left unchanged, review it in Roles & Permissions.");

                continue;
            }

            if ($role === null) {
                $role = Role::findOrCreate($key);
                $role->forceFill(['key' => $key, 'is_protected' => in_array($key, config('identity.protected_roles'), true)])->save();
                $role->givePermissionTo($permissions);

                $this->command?->info("[{$tenant->slug}] Created the missing {$key} role.");

                continue;
            }

            if ($key !== config('identity.chro_role')) {
                continue;
            }

            $missing = array_values(array_diff($permissions, $role->permissions()->pluck('name')->all()));

            if ($missing !== []) {
                $role->givePermissionTo($missing);

                $this->command?->info("[{$tenant->slug}] Gave the {$key} role ".count($missing).' missing permission(s).');
            }
        }
    }

    /**
     * Brings the tenant's employee_hierarchy closure table back in line with employees.reports_to_id
     * — the same rows EmployeeObserver would have written, soft-deleted employees included (their
     * rows stay on purpose). Only wrong links are removed and only missing links added; a tenant
     * already in sync is not written to. A reporting cycle is reported and left for a person to fix.
     */
    private function repairHierarchy(Tenant $tenant): void
    {
        $managerOf = DB::table('employees')->where('tenant_id', $tenant->id)->pluck('reports_to_id', 'id')
            ->mapWithKeys(fn (mixed $managerId, mixed $employeeId): array => [(int) $employeeId => $managerId === null ? null : (int) $managerId])
            ->all();

        $expected = [];

        foreach (array_keys($managerOf) as $employeeId) {
            $ancestorId = $employeeId;
            $depth = 0;

            while ($ancestorId !== null) {
                if ($depth > count($managerOf)) {
                    $this->command?->error("[{$tenant->slug}] Employee #{$employeeId}'s reporting line loops back on itself: the hierarchy was not repaired. Fix the reporting line, then run this again.");

                    return;
                }

                $expected["{$ancestorId}:{$employeeId}"] = $depth;
                $managerId = $managerOf[$ancestorId];
                $ancestorId = $managerId !== null && array_key_exists($managerId, $managerOf) ? $managerId : null;
                $depth++;
            }
        }

        $actual = DB::table('employee_hierarchy')->where('tenant_id', $tenant->id)->get(['ancestor_id', 'descendant_id', 'depth'])
            ->mapWithKeys(fn (object $row): array => ["{$row->ancestor_id}:{$row->descendant_id}" => (int) $row->depth])
            ->all();

        $wrong = array_keys(array_diff_assoc($actual, $expected));
        $missing = array_diff_assoc($expected, $actual);

        if ($wrong === [] && $missing === []) {
            return;
        }

        DB::transaction(function () use ($tenant, $wrong, $missing): void {
            foreach ($wrong as $link) {
                [$ancestorId, $descendantId] = explode(':', $link);

                DB::table('employee_hierarchy')->where('tenant_id', $tenant->id)->where('ancestor_id', $ancestorId)->where('descendant_id', $descendantId)->delete();
            }

            $now = now();
            $rows = array_map(function (string $link, int $depth) use ($tenant, $now): array {
                [$ancestorId, $descendantId] = explode(':', $link);

                return ['tenant_id' => $tenant->id, 'ancestor_id' => (int) $ancestorId, 'descendant_id' => (int) $descendantId, 'depth' => $depth, 'created_at' => $now];
            }, array_keys($missing), $missing);

            foreach (array_chunk($rows, 1000) as $chunk) {
                DB::table('employee_hierarchy')->insert($chunk);
            }
        });

        app(HierarchyMemo::class)->flush();

        $this->command?->warn("[{$tenant->slug}] Repaired the reporting hierarchy: removed ".count($wrong).' wrong and added '.count($missing).' missing link(s).');
    }
}
