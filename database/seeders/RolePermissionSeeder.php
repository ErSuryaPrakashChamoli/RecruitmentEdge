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
        'hierarchy.reassign',
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
        'reports.export',
        'users.manage',
        'roles.manage',
        'settings.manage',
        'audit.view',
        'ai.query',
        'ai.manage',
        'ai.actions.execute',
        'pipeline.configure',
        'pipeline.override',
        'talent-pools.viewAny',
        'talent-pools.manage',
        'referrals.submit',
        'referrals.review',
        'candidates.override-duplicate',
        'portal.manage',
        'interview-slots.manage',
        'communications.view',
        'communications.send',
        'communications.templates',
        'communications.preferences',
        'integrations.manage',
        'calendar.connect',
        'jobs.publish',
        'campaigns.manage',
        'automation.view',
        'automation.manage',
        'automation.activate',
        'automation.organization',
        'automation.executions',
        'automation.retry',
        'automation.escalations',
        'automation.analytics',
        'actions.manage',
        'intelligence.view',
        'intelligence.role-dna.manage',
        'intelligence.rediscover',
        'intelligence.risks.manage',
        'intelligence.memory.view',
        'intelligence.memory.manage',
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
            'audit.view', 'reports.export', 'hierarchy.reassign', 'ai.query', 'ai.manage', 'ai.actions.execute',
            ...self::PHASE_4_ROLE_PERMISSIONS['vp_hr'],
            ...self::PHASE_5_ROLE_PERMISSIONS['vp_hr'],
            ...self::PHASE_6_ROLE_PERMISSIONS['vp_hr'],
            ...self::PHASE_7_ROLE_PERMISSIONS['vp_hr'],
        ],
        'manager' => [
            'requisitions.viewAny', 'requisitions.create', 'requisitions.update',
            'candidates.viewAny', 'candidates.create', 'candidates.update', 'candidates.reassign',
            'pipeline.transition', 'activities.log', 'followups.manage',
            'interviews.manage', 'offers.manage', 'offers.release', 'joining.confirm',
            'targets.configure', 'performance.view', 'incentives.view', 'reports.export', 'ai.query', 'ai.actions.execute',
            ...self::PHASE_4_ROLE_PERMISSIONS['manager'],
            ...self::PHASE_5_ROLE_PERMISSIONS['manager'],
            ...self::PHASE_6_ROLE_PERMISSIONS['manager'],
            ...self::PHASE_7_ROLE_PERMISSIONS['manager'],
        ],
        'assistant_manager' => [
            'requisitions.viewAny',
            'candidates.viewAny', 'candidates.update',
            'pipeline.transition', 'activities.log', 'followups.manage',
            'interviews.manage', 'offers.manage', 'joining.confirm',
            'performance.view', 'incentives.view', 'reports.export', 'ai.query',
            ...self::PHASE_4_ROLE_PERMISSIONS['assistant_manager'],
            ...self::PHASE_5_ROLE_PERMISSIONS['assistant_manager'],
            ...self::PHASE_6_ROLE_PERMISSIONS['assistant_manager'],
            ...self::PHASE_7_ROLE_PERMISSIONS['assistant_manager'],
        ],
        'recruiter' => [
            'requisitions.viewAny',
            'candidates.viewAny', 'candidates.create', 'candidates.update',
            'pipeline.transition', 'activities.log', 'followups.manage',
            'interviews.manage', 'offers.manage', 'joining.confirm',
            'performance.view', 'incentives.view', 'reports.export', 'ai.query',
            ...self::PHASE_4_ROLE_PERMISSIONS['recruiter'],
            ...self::PHASE_5_ROLE_PERMISSIONS['recruiter'],
            ...self::PHASE_6_ROLE_PERMISSIONS['recruiter'],
            ...self::PHASE_7_ROLE_PERMISSIONS['recruiter'],
        ],
        'employee' => self::PHASE_4_ROLE_PERMISSIONS['employee'],
    ];

    /**
     * Phase 4 (configurable recruitment foundation) grants, kept separate so the additive
     * 2026_09_25 permissions migration can grant exactly these to existing installs without
     * re-syncing (and so wiping) any role an administrator has already edited. `employee` is the
     * minimal role for staff outside HR who only submit referrals.
     *
     * @var array<string, array<int, string>>
     */
    public const array PHASE_4_ROLE_PERMISSIONS = [
        'vp_hr' => [
            'pipeline.configure', 'pipeline.override', 'talent-pools.viewAny', 'talent-pools.manage',
            'referrals.submit', 'referrals.review', 'candidates.override-duplicate', 'portal.manage', 'interview-slots.manage',
        ],
        'manager' => [
            'pipeline.override', 'talent-pools.viewAny', 'talent-pools.manage',
            'referrals.submit', 'referrals.review', 'candidates.override-duplicate', 'portal.manage', 'interview-slots.manage',
        ],
        'assistant_manager' => [
            'talent-pools.viewAny', 'talent-pools.manage',
            'referrals.submit', 'referrals.review', 'portal.manage', 'interview-slots.manage',
        ],
        'recruiter' => [
            'talent-pools.viewAny', 'referrals.submit', 'referrals.review', 'portal.manage', 'interview-slots.manage',
        ],
        'employee' => ['referrals.submit'],
    ];

    /**
     * Phase 5 (communication & distribution) grants, applied additively to existing installs by
     * the 2026_09_26 permissions migration.
     *
     * @var array<string, array<int, string>>
     */
    public const array PHASE_5_ROLE_PERMISSIONS = [
        'vp_hr' => [
            'communications.view', 'communications.send', 'communications.templates', 'communications.preferences',
            'integrations.manage', 'calendar.connect', 'jobs.publish', 'campaigns.manage',
        ],
        'manager' => [
            'communications.view', 'communications.send', 'communications.preferences',
            'calendar.connect', 'jobs.publish', 'campaigns.manage',
        ],
        'assistant_manager' => [
            'communications.view', 'communications.send', 'communications.preferences', 'calendar.connect',
        ],
        'recruiter' => [
            'communications.view', 'communications.send', 'communications.preferences', 'calendar.connect',
        ],
    ];

    /**
     * Phase 6 (automation & action OS) grants, applied additively to existing installs by the
     * 2026_09_26_000002 permissions migration. Recruiters get no rule permissions: they work their
     * own Action Center items and notifications, which need no permission. Organization-wide rules
     * need automation.organization on top of automation.activate.
     *
     * @var array<string, array<int, string>>
     */
    public const array PHASE_6_ROLE_PERMISSIONS = [
        'vp_hr' => [
            'automation.view', 'automation.manage', 'automation.activate', 'automation.organization',
            'automation.executions', 'automation.retry', 'automation.escalations', 'automation.analytics', 'actions.manage',
        ],
        'manager' => [
            'automation.view', 'automation.manage', 'automation.activate',
            'automation.executions', 'automation.retry', 'automation.escalations', 'automation.analytics', 'actions.manage',
        ],
        'assistant_manager' => [
            'automation.view', 'automation.executions', 'automation.analytics', 'actions.manage',
        ],
        'recruiter' => [],
    ];

    /**
     * Phase 7 (EDGE Intelligence) grants, applied additively by the 2026_09_26_000003 migration.
     * Organisation-wide intelligence uses the existing hierarchy.view-all; AI requests additionally
     * need the existing ai.query.
     *
     * @var array<string, array<int, string>>
     */
    public const array PHASE_7_ROLE_PERMISSIONS = [
        'vp_hr' => [
            'intelligence.view', 'intelligence.role-dna.manage', 'intelligence.rediscover',
            'intelligence.risks.manage', 'intelligence.memory.view', 'intelligence.memory.manage',
        ],
        'manager' => [
            'intelligence.view', 'intelligence.role-dna.manage', 'intelligence.rediscover',
            'intelligence.risks.manage', 'intelligence.memory.view',
        ],
        'assistant_manager' => [
            'intelligence.view', 'intelligence.rediscover', 'intelligence.risks.manage', 'intelligence.memory.view',
        ],
        'recruiter' => [
            'intelligence.view', 'intelligence.rediscover',
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
