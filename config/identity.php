<?php

/*
|--------------------------------------------------------------------------
| Access & Identity Lifecycle (Phase 8.4)
|--------------------------------------------------------------------------
|
| Employment state (Employee.status) and access state (User.access_status) are separate and
| change only through the identity services. See docs/phase-8-4-access-identity-lifecycle.md.
|
*/

return [
    // identity:enforce-separations applies separations whose last working day has passed. Leave it
    // on; turn it off only for the first deploy window, until identity:reconcile-access has been
    // reviewed. The access gate refuses a separated employee's login either way.
    'scheduled_enforcement' => (bool) env('IDENTITY_SCHEDULED_ENFORCEMENT', true),

    // Roles are identified by an immutable key, never by their (editable) display name. A
    // protected role cannot be deleted, keeps its key through a rename, can only be granted or
    // removed by a holder of the same role, and its permission set cannot be edited in the UI.
    'protected_roles' => ['chro'],

    // The role every provisioned or restored identity starts with. Nothing more is granted
    // automatically — privileged roles are always an explicit, audited assignment.
    'base_role' => 'employee',

    // The role whose effective holders must never drop to zero (last-CHRO protection).
    'chro_role' => 'chro',

    // Who picks up a departed person's open work when no explicit successor or active manager
    // exists: the first permitted user holding this permission.
    'handoff_fallback_permission' => 'employees.separation.manage',

    'mfa' => [
        // Enforce MFA enrolment for privileged users. The test suite turns this off (phpunit.xml)
        // except in the MFA tests; production must keep it on.
        'enforce' => (bool) env('IDENTITY_MFA_ENFORCE', true),

        // Role keys that always require MFA.
        'required_roles' => ['chro', 'vp_hr', 'manager'],

        // Holding any of these permissions also requires MFA.
        'privileged_permissions' => [
            'users.manage',
            'users.access.manage',
            'roles.manage',
            'settings.manage',
            'hierarchy.view-all',
            'hierarchy.reassign',
            'audit.view',
            'access.review',
            'employees.convert',
            'employees.separation.manage',
            'employees.separation.cancel',
            'ai.actions.execute',
            'ai.manage',
        ],
    ],

    'password' => [
        'min_length' => 12,
    ],
];
