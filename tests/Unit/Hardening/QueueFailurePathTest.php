<?php

use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * SaaS-7 (C4): every queued class has a deterministic failure path, and no failure handler ever
 * finalises work that was refused only because its tenant is paused.
 *
 * - Mail and notifications (not covered by QueueContractTest): tries, a backoff and failed().
 * - A tenant job's failed() that does more than log must return early on TenantUnavailable —
 *   otherwise a suspended tenant's messages, runs and imports are marked failed and the SaaS-3
 *   resume (PausedTenantWork) replays jobs that find nothing left to do.
 */
function queueFailureClasses(string ...$directories): array
{
    $root = dirname(__DIR__, 3);
    $classes = [];

    foreach ($directories as $directory) {
        foreach ([...glob("{$root}/app/{$directory}/*.php"), ...glob("{$root}/app/{$directory}/*/*.php")] as $file) {
            $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], substr($file, strlen($root.'/app/')));

            if (class_exists($class) && is_subclass_of($class, ShouldQueue::class) && ! (new ReflectionClass($class))->isAbstract()) {
                $classes[$class] = $file;
            }
        }
    }

    return $classes;
}

test('every queued mail and notification declares its tries, a backoff and a failed() handler', function (): void {
    $missing = [];

    foreach (queueFailureClasses('Mail', 'Notifications') as $class => $file) {
        $reflection = new ReflectionClass($class);
        $defaults = $reflection->getDefaultProperties();

        if (empty($defaults['tries']) || (empty($defaults['backoff']) && ! $reflection->hasMethod('backoff')) || ! $reflection->hasMethod('failed')) {
            $missing[] = $class;
        }
    }

    expect(queueFailureClasses('Mail', 'Notifications'))->not->toBeEmpty()
        ->and($missing)->toBe([]);
});

test('a tenant job\'s failure handler that changes anything leaves paused-tenant work alone', function (): void {
    // Platform jobs carry no tenant, so the queue guard never refuses them as paused.
    $platform = ['App\\Jobs\\ProcessBillingEvent', 'App\\Jobs\\PurgeTenantJob', 'App\\Jobs\\GenerateComplianceExport'];
    $offenders = [];

    foreach (queueFailureClasses('Jobs', 'Listeners') as $class => $file) {
        if (in_array($class, $platform, true) || ! (new ReflectionClass($class))->hasMethod('failed')) {
            continue;
        }

        $method = new ReflectionMethod($class, 'failed');
        $body = implode('', array_slice(file($method->getFileName()), $method->getStartLine(), $method->getEndLine() - $method->getStartLine()));
        $statements = array_filter(array_map('trim', explode(';', preg_replace(['#//[^\n]*#', '#/\*.*?\*/#s', '/[{}]/'], '', $body))));
        $onlyLogs = collect($statements)->every(fn (string $statement): bool => str_starts_with($statement, 'Log::'));

        if (! $onlyLogs && ! str_contains($body, 'instanceof TenantUnavailable')) {
            $offenders[] = $class;
        }
    }

    expect($offenders)->toBe([]);
});
