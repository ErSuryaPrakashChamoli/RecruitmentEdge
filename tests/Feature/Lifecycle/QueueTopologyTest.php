<?php

use App\Jobs\AI\IndexAiDocumentJob;
use App\Jobs\AI\ReindexKnowledgeArticleJob;
use App\Jobs\SendCommunicationJob;
use App\Mail\CandidatePortalLink;
use App\Mail\CandidateStepUpCode;
use App\Notifications\Auth\NoticeOfEmailChangeRequest;
use App\Notifications\Auth\ResetPassword;
use App\Notifications\Auth\VerifyEmailChange;
use App\Notifications\StaffDatabaseNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Phase 8.3: the shipped deployment must consume every queue the application dispatches to —
 * otherwise candidate messages, automation, calendar sync or Outcome Loop capture silently never
 * run. Reads the real docker-compose.yml and the real job/listener classes.
 */
function queueTopologyCompose(): string
{
    return (string) file_get_contents(dirname(__DIR__, 3).'/docker-compose.yml');
}

/**
 * @return array<int, string>
 */
function queueTopologyConsumed(): array
{
    preg_match_all('/"--queue=([^"]+)"/', queueTopologyCompose(), $matches);

    return collect($matches[1])->flatMap(fn (string $list) => explode(',', $list))->unique()->values()->all();
}

/**
 * @return array<string, string> class => queue
 */
function queueTopologyProduced(): array
{
    $root = dirname(__DIR__, 3);
    $queues = [];

    foreach ([...glob($root.'/app/Jobs/*.php'), ...glob($root.'/app/Jobs/*/*.php')] as $file) {
        $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], substr($file, strlen($root.'/app/')));
        $reflection = new ReflectionClass($class);

        if (! $reflection->isInstantiable()) {
            continue;
        }

        $arguments = collect($reflection->getConstructor()?->getParameters() ?? [])
            ->map(fn (ReflectionParameter $parameter) => $parameter->isDefaultValueAvailable() ? $parameter->getDefaultValue() : ($parameter->getType()?->getName() === 'string' ? 'x' : 1))
            ->all();
        $queues[$class] = $reflection->newInstanceArgs($arguments)->queue ?? 'default';
    }

    foreach (glob($root.'/app/Listeners/*.php') as $file) {
        $class = 'App\\Listeners\\'.basename($file, '.php');

        if (is_subclass_of($class, ShouldQueue::class)) {
            $queues[$class] = (new ReflectionClass($class))->getDefaultProperties()['queue'] ?? 'default';
        }
    }

    // Phase 8.7: queued notifications and mail too — in-app alerts and auth mails run on
    // `notifications`, and a queue nobody consumes means nobody is ever told anything.
    foreach ([...glob($root.'/app/Notifications/*.php'), ...glob($root.'/app/Notifications/*/*.php'), ...glob($root.'/app/Mail/*.php')] as $file) {
        $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], substr($file, strlen($root.'/app/')));

        if (is_subclass_of($class, ShouldQueue::class)) {
            $reflection = new ReflectionClass($class);
            $arguments = collect($reflection->getConstructor()?->getParameters() ?? [])
                ->map(fn (ReflectionParameter $parameter) => match (true) {
                    $parameter->isDefaultValueAvailable() => $parameter->getDefaultValue(),
                    $parameter->allowsNull() => null,
                    default => match ($parameter->getType()?->getName()) {
                        'int' => 1,
                        'bool' => false,
                        'array' => [],
                        default => 'x',
                    },
                })
                ->all();
            $queues[$class] = $reflection->newInstanceArgs($arguments)->queue ?? 'default';
        }
    }

    return $queues;
}

test('every queue the application dispatches to is consumed by a shipped worker', function (): void {
    $consumed = queueTopologyConsumed();

    foreach (queueTopologyProduced() as $class => $queue) {
        expect(in_array($queue, $consumed, true))->toBeTrue("{$class} dispatches to [{$queue}], which no worker in docker-compose.yml consumes");
    }

    expect($consumed)->toContain('default');
});

test('slow provider work runs on the background worker, not beside candidate messages', function (): void {
    $produced = queueTopologyProduced();

    expect($produced[IndexAiDocumentJob::class])->toBe('intelligence')
        ->and($produced[ReindexKnowledgeArticleJob::class])->toBe('intelligence')
        ->and($produced[SendCommunicationJob::class])->toBe('communications');
});

test('security mail and staff alerts never share a worker with candidate messages (Phase 8.9, ED-05)', function (): void {
    preg_match_all('/"--queue=([^"]+)"/', queueTopologyCompose(), $matches);
    $workers = collect($matches[1])->map(fn (string $list) => explode(',', $list));
    $produced = queueTopologyProduced();

    foreach ([ResetPassword::class, NoticeOfEmailChangeRequest::class, VerifyEmailChange::class, CandidatePortalLink::class, CandidateStepUpCode::class] as $class) {
        expect($produced[$class])->toBe('security');
    }

    expect($produced[StaffDatabaseNotification::class])->toBe('notifications');

    foreach (['security', 'notifications'] as $queue) {
        $consumers = $workers->filter(fn (array $queues) => in_array($queue, $queues, true));

        expect($consumers)->not->toBeEmpty()
            ->and($consumers->every(fn (array $queues) => ! in_array('communications', $queues, true)))->toBeTrue("{$queue} shares a worker with candidate messages");
    }

    expect($workers->first(fn (array $queues) => in_array('security', $queues, true))[0])->toBe('security');
});

test('a job is never handed to a second worker while it is still running', function (): void {
    preg_match_all('/"--timeout=(\d+)"/', queueTopologyCompose(), $timeouts);
    preg_match('/DB_QUEUE_RETRY_AFTER: "(\d+)"/', queueTopologyCompose(), $retryAfter);

    expect((int) ($retryAfter[1] ?? 0))->toBeGreaterThan(max(array_map('intval', $timeouts[1])));
});

test('workers finish their current job on shutdown: grace period, signal delivery and retry_after agree', function (): void {
    $compose = queueTopologyCompose();
    $root = dirname(__DIR__, 3);
    preg_match('/DB_QUEUE_RETRY_AFTER: "(\d+)"/', $compose, $retryAfter);
    preg_match_all('/"--timeout=(\d+)"/', $compose, $timeouts);
    preg_match_all('/^  ([a-z-]+):\n(?:    .*\n)*?    command: \["php", "artisan", "(?:queue:work|schedule:work)"/m', $compose, $workers);
    preg_match('/^DB_QUEUE_RETRY_AFTER=(\d+)$/m', (string) file_get_contents($root.'/.env.example'), $exampleRetryAfter);
    $entrypoint = (string) file_get_contents($root.'/docker/entrypoint.sh');

    expect($workers[1])->toContain('queue', 'queue-automation', 'queue-background', 'scheduler');

    foreach ($workers[1] as $service) {
        preg_match('/^  '.preg_quote($service, '/').':\n(?:    .*\n)*?    stop_grace_period: (\d+)s/m', $compose, $grace);
        expect((int) ($grace[1] ?? 0))->toBeGreaterThanOrEqual((int) $retryAfter[1], "{$service} has no stop_grace_period covering a running job");
    }

    expect((int) ($exampleRetryAfter[1] ?? 0))->toBe((int) $retryAfter[1])
        ->and(max(array_map('intval', $timeouts[1])))->toBe(config('queue.worker_max_timeout'))
        ->and($entrypoint)->toContain('exec setpriv')
        ->and($entrypoint)->not->toContain('su -s');
});

test('automation has its own worker, so candidate messages never starve it', function (): void {
    preg_match('/queue-automation:\n(?:    .*\n)*?    command: \[[^\]]*"--queue=([^"]+)"/', queueTopologyCompose(), $automation);
    preg_match('/^  queue:\n(?:    .*\n)*?    command: \[[^\]]*"--queue=([^"]+)"/m', queueTopologyCompose(), $messages);

    expect(explode(',', $automation[1] ?? ''))->toContain('automation')
        ->and(explode(',', $messages[1] ?? ''))->not->toContain('automation')
        ->and(explode(',', $messages[1] ?? ''))->toContain('communications');
});
