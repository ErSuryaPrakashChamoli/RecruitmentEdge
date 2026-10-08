<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;

/**
 * Phase 8.10 (P810-OP-03): queue:drain-status tells the deployer when the workers can be stopped —
 * nothing ready, nothing held by a worker, no scheduled task still running — and never changes
 * anything while it looks.
 */
function drainJobRow(string $queue, array $attributes = []): void
{
    DB::table('jobs')->insert([
        'queue' => $queue,
        'payload' => json_encode(['displayName' => 'App\\Jobs\\SendCommunicationJob']),
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => now()->getTimestamp(),
        'created_at' => now()->getTimestamp(),
        ...$attributes,
    ]);
}

test('an empty queue is drained', function (): void {
    $this->artisan('queue:drain-status')
        ->expectsOutputToContain('Drained: no job is ready or reserved')
        ->assertExitCode(0);
});

test('a job waiting to run means the workers must keep going', function (): void {
    drainJobRow('communications');

    $this->artisan('queue:drain-status')
        ->expectsTable(['Queue', 'Ready', 'Delayed', 'Reserved'], [
            ['communications', 1, 0, 0], ['security', 0, 0, 0], ['notifications', 0, 0, 0], ['automation', 0, 0, 0],
            ['documents', 0, 0, 0], ['intelligence', 0, 0, 0], ['integrations', 0, 0, 0], ['exports', 0, 0, 0], ['default', 0, 0, 0],
        ])
        ->expectsOutputToContain('Not drained: 1 ready, 0 reserved')
        ->assertExitCode(1);
});

test('a job a worker still holds blocks the drain', function (): void {
    drainJobRow('documents', ['reserved_at' => now()->getTimestamp(), 'attempts' => 1]);

    $this->artisan('queue:drain-status')
        ->expectsOutputToContain('Not drained: 0 ready, 1 reserved')
        ->assertExitCode(1);
});

test('delayed jobs are reported but do not block the drain', function (): void {
    drainJobRow('communications', ['available_at' => now()->addHour()->getTimestamp()]);

    $this->artisan('queue:drain-status')
        ->expectsOutputToContain('1 delayed job(s) stay queued and run after the release.')
        ->assertExitCode(0);
});

test('a scheduled task still holding its overlap lock blocks the drain; onOneServer locks do not', function (): void {
    config(['cache.default' => 'database']);
    $now = now()->getTimestamp();
    DB::table('cache_locks')->insert([
        ['key' => 'laravel-cache-framework/schedule-'.sha1('one-server').'1405', 'owner' => 'a', 'expiration' => $now + 3600],
        ['key' => 'laravel-cache-framework/schedule-'.sha1('expired-overlap'), 'owner' => 'b', 'expiration' => $now - 60],
    ]);

    $this->artisan('queue:drain-status')->assertExitCode(0);

    DB::table('cache_locks')->insert(['key' => 'laravel-cache-framework/schedule-'.sha1('running-overlap'), 'owner' => 'c', 'expiration' => $now + 600]);

    $this->artisan('queue:drain-status')
        ->expectsOutputToContain('Scheduled tasks running: 1')
        ->expectsOutputToContain('1 scheduled task(s) running')
        ->assertExitCode(1);
});

test('with --wait it keeps checking until the workers have emptied the queues', function (): void {
    Sleep::fake();
    Sleep::whenFakingSleep(fn () => DB::table('jobs')->delete());
    drainJobRow('communications');

    $this->artisan('queue:drain-status', ['--wait' => 60])->assertExitCode(0);

    Sleep::assertSleptTimes(1);
});

test('with --wait it gives up when the queue does not drain in time', function (): void {
    Sleep::fake(syncWithCarbon: true);
    drainJobRow('communications');

    $this->artisan('queue:drain-status', ['--wait' => 20])->assertExitCode(1);

    Sleep::assertSleptTimes(4);
});

test('the check changes nothing it looks at', function (): void {
    drainJobRow('communications');
    drainJobRow('documents', ['reserved_at' => now()->getTimestamp()]);
    $before = DB::table('jobs')->orderBy('id')->get()->toArray();

    $this->artisan('queue:drain-status');

    expect(DB::table('jobs')->orderBy('id')->get()->toArray())->toEqual($before);
});
