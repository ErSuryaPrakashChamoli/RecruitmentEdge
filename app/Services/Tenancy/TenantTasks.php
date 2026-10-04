<?php

namespace App\Services\Tenancy;

/**
 * SaaS-1: the scheduled tasks that work on tenant data, and how each runs per tenant. Only tasks
 * listed here can be run through tenants:dispatch / tenants:run (an allow-list, not a way to run
 * arbitrary commands). Classification of all 20 scheduled tasks: docs/saas-1-tenant-foundation.md.
 *
 * QUEUED: the scheduler's dispatcher queues one RunTenantScheduledTask job per tenant on the named
 * queue (consumed by the docker-compose workers); each job restores its tenant and runs the task
 * there, observable on its own (queue.job_processed, failed_jobs, all carrying tenant_id).
 *
 * BACKGROUND: tasks measured to outlast a queued job's ceiling (retry_after, 330 s —
 * intelligence:refresh took 414 s cold at 520 open requisitions, Phase 8.7) run in a separate
 * background process (tenants:run --all), one tenant at a time, each isolated and logged with
 * its tenant — never inside the scheduler process.
 */
final class TenantTasks
{
    /**
     * @var array<string, string> task => queue
     */
    public const QUEUED = [
        'incentives:release-matured' => 'intelligence',
        'notifications:dispatch-alerts' => 'notifications',
        'offers:expire-lapsed' => 'default',
        'interview-slots:expire' => 'default',
        'jobs:sync-distributions' => 'integrations',
        'communications:send-reminders' => 'communications',
        'recruitment:automation:dispatch' => 'automation',
        'recruitment:automation:process' => 'automation',
        'recruitment:automation:cleanup' => 'automation',
        'ai:expire-pending-actions' => 'intelligence',
        'identity:enforce-separations' => 'security',
        // SaaS-2: invitation expiry (audited per invitation).
        'invitations:expire' => 'security',
        'reliability:sweep' => 'communications',
    ];

    /**
     * @var list<string>
     */
    public const BACKGROUND = [
        'performance:snapshot',
        'intelligence:refresh',
        'outcomes:evaluate',
    ];

    /**
     * Operator commands on tenant data (never scheduled): run them in a tenant with
     * `tenants:run <task> --tenant=<slug> [--with=option[=value] …]`.
     *
     * @var list<string>
     */
    public const OPERATOR = [
        'ai:redact-history',
        'ai:reindex-knowledge',
        'governance:audit',
        'identity:audit',
        'identity:reconcile-access',
        'lifecycle:audit',
        'outcomes:backfill',
        'recruitment:assign-default-pipelines',
    ];

    /**
     * Platform tasks: framework housekeeping with no tenant meaning; they run with no tenant.
     * queue:health-check runs a platform pass and then a light per-tenant pass itself.
     *
     * @var list<string>
     */
    public const PLATFORM = [
        'queue:prune-failed',
        'cache:prune-expired',
        'auth:clear-resets',
        'queue:prune-batches',
        'queue:health-check',
    ];

    public static function isTenantTask(string $task): bool
    {
        return isset(self::QUEUED[$task]) || in_array($task, self::BACKGROUND, true) || in_array($task, self::OPERATOR, true);
    }
}
