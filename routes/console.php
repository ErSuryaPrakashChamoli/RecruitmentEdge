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
