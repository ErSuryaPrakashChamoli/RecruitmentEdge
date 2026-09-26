<?php

use App\Jobs\AI\IndexAiDocumentJob;
use App\Jobs\AI\ReindexKnowledgeArticleJob;
use App\Jobs\SendCommunicationJob;
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

test('a job is never handed to a second worker while it is still running', function (): void {
    preg_match_all('/"--timeout=(\d+)"/', queueTopologyCompose(), $timeouts);
    preg_match('/DB_QUEUE_RETRY_AFTER: "(\d+)"/', queueTopologyCompose(), $retryAfter);

    expect((int) ($retryAfter[1] ?? 0))->toBeGreaterThan(max(array_map('intval', $timeouts[1])));
});
