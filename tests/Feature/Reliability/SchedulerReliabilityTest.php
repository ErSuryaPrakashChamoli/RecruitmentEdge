<?php

use App\Enums\OfferStatus;
use App\Models\Offer;
use App\Services\SchedulerHeartbeat;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Scheduling\Event as ScheduledEvent;
use Illuminate\Console\Scheduling\Schedule;

/**
 * Phase 8.7 (D8.7-011/021): one scheduler; no task ever runs twice at once or on two hosts; one bad
 * item never stops a scheduled pass; every run leaves a heartbeat.
 */
function scheduledTask(string $command): ScheduledEvent
{
    return collect(app(Schedule::class)->events())->first(fn (ScheduledEvent $event) => str_contains((string) $event->command, $command))
        ?? throw new RuntimeException("{$command} is not scheduled");
}

test('every scheduled task is guarded against overlapping runs and a second scheduler host', function (): void {
    $events = collect(app(Schedule::class)->events());

    expect($events)->not->toBeEmpty()
        ->and($events->reject(fn (ScheduledEvent $event) => $event->withoutOverlapping && $event->onOneServer)->map->command->values()->all())->toBe([])
        ->and($events->every(fn (ScheduledEvent $event) => $event->expiresAt >= 10))->toBeTrue();
});

test('long tasks run in the background so they never delay the tasks behind them', function (string $command): void {
    expect(scheduledTask($command)->runInBackground)->toBeTrue();
})->with(['performance:snapshot', 'intelligence:refresh', 'outcomes:evaluate']);

test('each scheduled run records a heartbeat with its outcome', function (): void {
    $sweep = scheduledTask('reliability:sweep');
    $sweep->exitCode = 0;

    event(new ScheduledTaskFinished($sweep, 0.4));
    event(new ScheduledTaskSkipped(scheduledTask('performance:snapshot')));

    $tasks = collect(app(SchedulerHeartbeat::class)->tasks())->keyBy('task');

    expect(app(SchedulerHeartbeat::class)->lastTick())->not->toBeNull()
        ->and($tasks['reliability:sweep']['outcome'])->toBe('finished')
        ->and($tasks['performance:snapshot']['outcome'])->toBe('skipped')
        ->and($tasks['offers:expire-lapsed']['outcome'])->toBeNull();
});

test('one offer that cannot be expired never stops the others from expiring', function (): void {
    [$broken, $healthy] = Offer::factory()->count(2)->create(['status' => OfferStatus::Released, 'offer_expiry' => now()->subDays(3)])->all();
    Offer::updating(function (Offer $offer) use ($broken): void {
        if ($offer->id === $broken->id) {
            throw new RuntimeException('row is corrupt');
        }
    });

    $this->artisan('offers:expire-lapsed')->assertSuccessful();

    expect($healthy->fresh()->status)->toBe(OfferStatus::Expired)
        ->and($broken->fresh()->status)->toBe(OfferStatus::Released);
});

test('the heartbeat lists the schedule even where the console kernel has not loaded it (a web request)', function (): void {
    app()->instance(Schedule::class, new Schedule);

    $tasks = collect(app(SchedulerHeartbeat::class)->tasks())->pluck('task');

    expect($tasks)->toContain('reliability:sweep', 'queue:health-check', 'intelligence:refresh');
});
