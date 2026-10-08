<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Schedule
|--------------------------------------------------------------------------
|
| Phase 8.7 (D8.7-011): exactly one scheduler runs this schedule — the `scheduler` service
| (schedule:work). Never add a cron `schedule:run` beside it. Every task is guarded so that a run
| still going when the next is due is skipped rather than doubled (withoutOverlapping, lock expiry
| sized to the task), and so that a second scheduler host never runs it twice (onOneServer). Long
| tasks run in the background so they never delay the tasks behind them. Each run's outcome is
| recorded as the scheduler heartbeat (AppServiceProvider), shown on the Queue health page.
|
*/

// SaaS-1: tasks that work on tenant data run per tenant (App\Services\Tenancy\TenantTasks). The
// scheduler only enumerates the active tenants: tenants:dispatch queues one job per tenant, which
// restores that tenant before running the task; the three long tasks run per tenant in a separate
// background process (tenants:run --all). Nothing scans tenant data across tenants.
Schedule::command('tenants:dispatch incentives:release-matured')->daily()->withoutOverlapping(60)->onOneServer();
Schedule::command('tenants:dispatch notifications:dispatch-alerts')->hourly()->withoutOverlapping(55)->onOneServer();
Schedule::command('tenants:run performance:snapshot --all')->dailyAt('00:30')->withoutOverlapping(180)->onOneServer()->runInBackground();
Schedule::command('tenants:dispatch offers:expire-lapsed')->dailyAt('00:15')->withoutOverlapping(60)->onOneServer();
Schedule::command('tenants:dispatch interview-slots:expire')->hourly()->withoutOverlapping(55)->onOneServer();
Schedule::command('tenants:dispatch jobs:sync-distributions')->dailyAt('01:00')->withoutOverlapping(60)->onOneServer();
Schedule::command('tenants:dispatch communications:send-reminders')->hourly()->withoutOverlapping(55)->onOneServer();
Schedule::command('tenants:dispatch recruitment:automation:dispatch')->everyFifteenMinutes()->withoutOverlapping(14)->onOneServer();
Schedule::command('tenants:dispatch recruitment:automation:process')->everyFiveMinutes()->withoutOverlapping(10)->onOneServer();
Schedule::command('tenants:dispatch recruitment:automation:cleanup')->dailyAt('02:00')->withoutOverlapping(60)->onOneServer();
// SaaS-7 (C5): a 70-minute budget per pass, resumed by a rotating cursor — budget plus the longest
// single tenant (INTELLIGENCE_REFRESH_TIME_BUDGET, 45 min) stays below the 120-minute overlap lock,
// so two passes never run at once, and tenants with high ids are never starved.
Schedule::command('tenants:run intelligence:refresh --all --budget=4200')->hourly()->withoutOverlapping(120)->onOneServer()->runInBackground();
Schedule::command('tenants:run outcomes:evaluate --all')->dailyAt('03:00')->withoutOverlapping(180)->onOneServer()->runInBackground();
Schedule::command('tenants:dispatch ai:expire-pending-actions')->everyFiveMinutes()->withoutOverlapping(10)->onOneServer();
// Phase 8.4: separations whose last working day has passed (identity.scheduled_enforcement).
Schedule::command('tenants:dispatch identity:enforce-separations')->hourly()->withoutOverlapping(55)->onOneServer()->when(fn (): bool => (bool) config('identity.scheduled_enforcement'));
// Phase 8.7 (D8.7-012): failed jobs (payload and redacted exception) are kept for the retention
// window only — the queue health page shows them until then.
Schedule::command('queue:prune-failed', ['--hours' => (int) config('queue.failed.retention_hours', 720)])->dailyAt('02:30')->withoutOverlapping(30)->onOneServer();
// Phase 8.9 (P89-OPS-009, ED-08): technical housekeeping only — entries that have already expired
// (cache, password-reset tokens) and finished job batches past the failed-job window. Not retention.
Schedule::command('cache:prune-expired')->dailyAt('02:40')->withoutOverlapping(30)->onOneServer();
Schedule::command('auth:clear-resets')->dailyAt('02:45')->withoutOverlapping(15)->onOneServer();
Schedule::command('queue:prune-batches', ['--hours' => (int) config('queue.failed.retention_hours', 720)])->dailyAt('02:50')->withoutOverlapping(15)->onOneServer();
// Phase 8.7 (D8.7-013): re-queue lost messages; fail work a crashed worker or request left stuck.
// SaaS-3: ended trials are recorded (they are already treated as suspended) and unfinished
// provisioning is reported — a platform pass over the tenants table only.
Schedule::command('tenants:lifecycle-sweep')->hourly()->withoutOverlapping(55)->onOneServer();
// SaaS-4: billing's clock (renewals, grace and cancellation ends, collection retries), a daily
// report-only reconciliation with the provider, and the payload retention of provider events.
Schedule::command('billing:sweep')->hourly()->withoutOverlapping(55)->onOneServer();
Schedule::command('billing:reconcile')->dailyAt('04:00')->withoutOverlapping(120)->onOneServer();
Schedule::command('billing:prune-events')->dailyAt('04:30')->withoutOverlapping(30)->onOneServer();
// SaaS-6: due webhook retries, stalled inbound events, integration record retention (per tenant).
Schedule::command('tenants:dispatch integrations:sweep')->everyFiveMinutes()->withoutOverlapping(10)->onOneServer();
// SaaS-5: support access expiry, due tenant purges (queued, resumable), compliance export expiry.
Schedule::command('platform:sweep')->everyFifteenMinutes()->withoutOverlapping(14)->onOneServer();
// SaaS-2: pending invitations past their expiry become Expired (each tenant's own, audited).
Schedule::command('tenants:dispatch invitations:expire')->hourly()->withoutOverlapping(55)->onOneServer();
Schedule::command('tenants:dispatch reliability:sweep')->everyFiveMinutes()->withoutOverlapping(10)->onOneServer();
// Phase 8.7 (D8.7-028): raise failed jobs, backlogs, stuck work and a silent scheduler.
Schedule::command('queue:health-check')->everyFiveMinutes()->withoutOverlapping(10)->onOneServer();
