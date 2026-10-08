<?php

/*
 * SaaS-5: the platform control plane (docs/saas-5-platform-control.md). Every duration and retention
 * here is a configurable safe default pending an owner decision (docs/saas-5-decision-register.md);
 * none is a legal retention period.
 */
return [

    /*
     * The platform's own brand — the SaaS operator, used on platform surfaces (the platform panel,
     * sign-in, staff invitations). Tenant-facing surfaces (careers, portal, candidate mail, offer
     * letters) use the tenant's own name (App\Services\Branding).
     */
    'brand' => [
        'name' => env('PLATFORM_BRAND_NAME', env('APP_NAME', 'Recruitment Edge')),
    ],

    /*
     * Where critical platform events are mailed (empty: the platform event feed only). D-S5-O8.
     */
    'notify_email' => env('PLATFORM_NOTIFY_EMAIL'),

    'support' => [
        // The longest a tenant may approve support access for (minutes). D-S5-O1.
        'max_minutes' => (int) env('PLATFORM_SUPPORT_MAX_MINUTES', 480),
        // An unanswered support request lapses after this many hours.
        'request_ttl_hours' => (int) env('PLATFORM_SUPPORT_REQUEST_TTL_HOURS', 72),
    ],

    'deletion' => [
        // Days between an approved deletion and its purge, during which it can be cancelled. D-S5-O4.
        'grace_days' => (int) env('PLATFORM_DELETION_GRACE_DAYS', 30),
        // Approval must come from a different operator than the request (two-person rule). D-S5-O9.
        'require_second_operator' => (bool) env('PLATFORM_DELETION_SECOND_OPERATOR', true),
        // A purge worker's claim; another worker resumes a purge whose lease expired.
        'lease_minutes' => 15,
        // Rows deleted per statement.
        'chunk' => 1000,
        /*
         * Tenant tables kept when a tenant is purged — commercial and payment evidence, the audit
         * trail and the record of platform access. Their own retention period is an owner decision
         * (D-S5-O5); nothing deletes them automatically.
         */
        'retain_tables' => [
            'audit_logs',
            'billing_customers',
            'billing_events',
            'billing_invoices',
            'billing_payments',
            'billing_subscriptions',
            'support_access_grants',
            'tenant_entitlement_overrides',
            'tenant_plan_assignments',
        ],
    ],

    'compliance_exports' => [
        // Days an export artifact is kept before it is deleted. D-S5-O6.
        'retention_days' => (int) env('PLATFORM_COMPLIANCE_EXPORT_RETENTION_DAYS', 7),
        'disk' => 'local',
        // Rows read per query while exporting.
        'chunk' => 1000,
    ],
];
