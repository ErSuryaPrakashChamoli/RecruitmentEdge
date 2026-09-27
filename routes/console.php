<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('incentives:release-matured')->daily();
Schedule::command('notifications:dispatch-alerts')->hourly();
Schedule::command('performance:snapshot')->dailyAt('00:30');
Schedule::command('offers:expire-lapsed')->dailyAt('00:15');
Schedule::command('interview-slots:expire')->hourly();
Schedule::command('jobs:sync-distributions')->dailyAt('01:00');
Schedule::command('communications:send-reminders')->hourly();
Schedule::command('recruitment:automation:dispatch')->everyFifteenMinutes()->withoutOverlapping();
Schedule::command('recruitment:automation:process')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('recruitment:automation:cleanup')->dailyAt('02:00');
Schedule::command('intelligence:refresh')->hourly()->withoutOverlapping();
Schedule::command('outcomes:evaluate')->dailyAt('03:00')->withoutOverlapping();
// Phase 8.4: separations whose last working day has passed (identity.scheduled_enforcement).
Schedule::command('ai:expire-pending-actions')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('identity:enforce-separations')->hourly()->withoutOverlapping()->when(fn (): bool => (bool) config('identity.scheduled_enforcement'));
// Phase 8.7 (D8.7-012): failed jobs (payload and redacted exception) are kept for the retention
// window only — the queue health page shows them until then.
Schedule::command('queue:prune-failed', ['--hours' => (int) config('queue.failed.retention_hours', 720)])->dailyAt('02:30');
// Phase 8.7 (D8.7-013): re-queue lost messages; fail work a crashed worker or request left stuck.
Schedule::command('reliability:sweep')->everyFiveMinutes()->withoutOverlapping();
