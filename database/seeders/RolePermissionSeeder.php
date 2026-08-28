<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds a starting set of roles and permissions for the CHRO -> VP HR -> Manager -> Assistant
 * Manager -> Recruiter hierarchy. This is a default, not a constraint: every permission and role
 * created here remains fully editable from Administration > Roles & Permissions.
 */
class RolePermissionSeeder extends Seeder
{
    /**
     * @var array<int, string>
     */
    private const array PERMISSIONS = [
        'hierarchy.view-all',
        'requisitions.viewAny',
        'requisitions.create',
        'requisitions.update',
        'requisitions.approve',
        'candidates.viewAny',
        'candidates.create',
        'candidates.update',
        'candidates.reassign',
        'pipeline.transition',
        'activities.log',
        'followups.manage',
        'interviews.manage',
        'offers.manage',
        'offers.release',
        'joining.confirm',
        'targets.configure',
        'performance.configure',
        'performance.view',
        'incentives.configureRules',
        'incentives.calculate',
        'incentives.approve',
        'incentives.view',
        'incentives.pay',
        'users.manage',
        'roles.manage',
        'settings.manage',
        'audit.view',
        'ai.query',
        'ai.manage',
    ];

    /**
     * @var array<string, array<int, string>>
     */
    private const array ROLE_PERMISSIONS = [
        'chro' => ['*'],
        'vp_hr' => [
            'requisitions.viewAny', 'requisitions.create', 'requisitions.update', 'requisitions.approve',
            'candidates.viewAny', 'candidates.create', 'candidates.update', 'candidates.reassign',
            'pipeline.transition', 'activities.log', 'followups.manage',
            'interviews.manage', 'offers.manage', 'offers.release', 'joining.confirm',
            'targets.configure', 'performance.configure', 'performance.view',
            'incentives.configureRules', 'incentives.calculate', 'incentives.approve', 'incentives.view',
            'audit.view', 'ai.query', 'ai.manage',
        ],
        'manager' => [
            'requisitions.viewAny', 'requisitions.create', 'requisitions.update',
            'candidates.viewAny', 'candidates.create', 'candidates.update', 'candidates.reassign',
            'pipeline.transition', 'activities.log', 'followups.manage',
            'interviews.manage', 'offers.manage', 'offers.release', 'joining.confirm',
            'targets.configure', 'performance.view', 'incentives.view', 'ai.query',
        ],
        'assistant_manager' => [
            'requisitions.viewAny',
            'candidates.viewAny', 'candidates.update',
            'pipeline.transition', 'activities.log', 'followups.manage',
            'interviews.manage', 'offers.manage', 'joining.confirm',
            'performance.view', 'incentives.view', 'ai.query',
        ],
        'recruiter' => [
            'requisitions.viewAny',
            'candidates.viewAny', 'candidates.create', 'candidates.update',
            'pipeline.transition', 'activities.log', 'followups.manage',
            'interviews.manage', 'offers.manage', 'joining.confirm',
            'performance.view', 'incentives.view', 'ai.query',
        ],
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission);
        }

        foreach (self::ROLE_PERMISSIONS as $roleName => $permissions) {
            $role = Role::findOrCreate($roleName);

            $role->syncPermissions($permissions === ['*'] ? self::PERMISSIONS : $permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
