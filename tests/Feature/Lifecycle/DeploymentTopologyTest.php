<?php

use App\Services\SchedulerHeartbeat;
use Illuminate\Queue\Events\Looping;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\Yaml\Yaml;

/**
 * Phase 8.9 (P89-OPS-005, D8.9-022): the shipped compose stack starts in a safe order — migrations
 * complete, then a healthy app, then healthy workers, then the scheduler — never migrates from a
 * serving container, restarts what dies, and is tagged so a release can be rolled back by tag.
 *
 * @return array<string, mixed>
 */
function deploymentCompose(): array
{
    return Yaml::parseFile(dirname(__DIR__, 3).'/docker-compose.yml');
}

test('migrations run once in their own service, and no serving container migrates on start', function (): void {
    $services = deploymentCompose()['services'];

    expect($services['migrate']['command'])->toBe(['php', 'artisan', 'migrate', '--force', '--no-interaction'])
        ->and($services['migrate']['restart'])->toBe('no');

    foreach (['app', 'scheduler', 'queue', 'queue-priority', 'queue-automation', 'queue-background'] as $service) {
        expect($services[$service]['environment']['RUN_MIGRATIONS'] ?? null)->toBe('false');
    }
});

test('the stack starts in order: migrated, healthy app, healthy workers, then the scheduler', function (): void {
    $services = deploymentCompose()['services'];
    $workers = ['queue', 'queue-priority', 'queue-automation', 'queue-background'];

    expect($services['app']['depends_on']['migrate']['condition'])->toBe('service_completed_successfully')
        ->and($services['app']['healthcheck']['test'])->toContain('php')
        ->and(implode(' ', $services['app']['healthcheck']['test']))->toContain('/up');

    foreach ($workers as $worker) {
        expect($services[$worker]['depends_on']['app']['condition'])->toBe('service_healthy')
            ->and(implode(' ', $services[$worker]['healthcheck']['test']))->toContain('ops:heartbeat worker')
            ->and($services[$worker]['restart'])->toBe('unless-stopped');
        expect($services['scheduler']['depends_on'][$worker]['condition'])->toBe('service_healthy');
    }

    expect(implode(' ', $services['scheduler']['healthcheck']['test']))->toContain('ops:heartbeat scheduler')
        ->and($services['app']['restart'])->toBe('unless-stopped');
});

test('the image is tagged per release and the database root never has an empty password', function (): void {
    $compose = deploymentCompose();

    expect($compose['x-app-base']['image'])->toBe('recruitment-edge-app:${APP_IMAGE_TAG:-local}')
        ->and($compose['services']['db']['environment'])->not->toHaveKey('MYSQL_ALLOW_EMPTY_PASSWORD')
        ->and($compose['services']['db']['environment']['MYSQL_RANDOM_ROOT_PASSWORD'])->toBe('yes');
});

test('the worker and scheduler health checks follow their heartbeat', function (): void {
    $this->artisan('ops:heartbeat', ['component' => 'worker', '--queues' => 'communications,default'])->assertFailed();
    $this->artisan('ops:heartbeat', ['component' => 'scheduler'])->assertFailed();

    event(new Looping('database', 'communications,default'));
    Cache::forever(SchedulerHeartbeat::LAST_TICK_KEY, now()->toIso8601String());

    $this->artisan('ops:heartbeat', ['component' => 'worker', '--queues' => 'communications,default'])->assertSuccessful();
    $this->artisan('ops:heartbeat', ['component' => 'scheduler'])->assertSuccessful();

    $this->travel(4)->minutes();
    $this->artisan('ops:heartbeat', ['component' => 'worker', '--queues' => 'communications,default'])->assertFailed();
});
