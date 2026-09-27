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

Schedule::command('incentives:release-matured')->daily()->withoutOverlapping(60)->onOneServer();
Schedule::command('notifications:dispatch-alerts')->hourly()->withoutOverlapping(55)->onOneServer();
Schedule::command('performance:snapshot')->dailyAt('00:30')->withoutOverlapping(180)->onOneServer()->runInBackground();
Schedule::command('offers:expire-lapsed')->dailyAt('00:15')->withoutOverlapping(60)->onOneServer();
Schedule::command('interview-slots:expire')->hourly()->withoutOverlapping(55)->onOneServer();
Schedule::command('jobs:sync-distributions')->dailyAt('01:00')->withoutOverlapping(60)->onOneServer();
Schedule::command('communications:send-reminders')->hourly()->withoutOverlapping(55)->onOneServer();
Schedule::command('recruitment:automation:dispatch')->everyFifteenMinutes()->withoutOverlapping(14)->onOneServer();
Schedule::command('recruitment:automation:process')->everyFiveMinutes()->withoutOverlapping(10)->onOneServer();
Schedule::command('recruitment:automation:cleanup')->dailyAt('02:00')->withoutOverlapping(60)->onOneServer();
Schedule::command('intelligence:refresh')->hourly()->withoutOverlapping(120)->onOneServer()->runInBackground();
Schedule::command('outcomes:evaluate')->dailyAt('03:00')->withoutOverlapping(180)->onOneServer()->runInBackground();
// Phase 8.4: separations whose last working day has passed (identity.scheduled_enforcement).
Schedule::command('ai:expire-pending-actions')->everyFiveMinutes()->withoutOverlapping(10)->onOneServer();
Schedule::command('identity:enforce-separations')->hourly()->withoutOverlapping(55)->onOneServer()->when(fn (): bool => (bool) config('identity.scheduled_enforcement'));
// Phase 8.7 (D8.7-012): failed jobs (payload and redacted exception) are kept for the retention
// window only — the queue health page shows them until then.
Schedule::command('queue:prune-failed', ['--hours' => (int) config('queue.failed.retention_hours', 720)])->dailyAt('02:30')->withoutOverlapping(30)->onOneServer();
// Phase 8.7 (D8.7-013): re-queue lost messages; fail work a crashed worker or request left stuck.
Schedule::command('reliability:sweep')->everyFiveMinutes()->withoutOverlapping(10)->onOneServer();
