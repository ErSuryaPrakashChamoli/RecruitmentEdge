<?php

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Phase 8.7 (D8.7-003/004/006/013/018): the conventions every queued class and domain event keeps.
 * A new job or listener that forgets them fails here, not in production.
 */
function queuedApplicationClasses(): array
{
    $root = dirname(__DIR__, 3);
    $classes = [];

    foreach ([...glob($root.'/app/Jobs/*.php'), ...glob($root.'/app/Jobs/*/*.php'), ...glob($root.'/app/Listeners/*.php')] as $file) {
        $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], substr($file, strlen($root.'/app/')));

        if (class_exists($class) && is_subclass_of($class, ShouldQueue::class)) {
            $classes[] = $class;
        }
    }

    return $classes;
}

test('every queued job and listener declares its tries, a non-zero backoff and a failed() handler', function (): void {
    $missing = [];

    foreach (queuedApplicationClasses() as $class) {
        $reflection = new ReflectionClass($class);
        $defaults = $reflection->getDefaultProperties();
        $hasTries = $reflection->hasProperty('tries') && ($reflection->getProperty('tries')->getDeclaringClass()->getName() === $class);
        $hasBackoff = $reflection->hasMethod('backoff') || ! empty($defaults['backoff'] ?? null);

        if (! $hasTries || ! $hasBackoff || ! $reflection->hasMethod('failed')) {
            $missing[] = $class;
        }
    }

    expect(queuedApplicationClasses())->not->toBeEmpty()
        ->and($missing)->toBe([]);
});

arch('every domain event is dispatched only after its transaction commits')
    ->expect('App\Events')
    ->toImplement(ShouldDispatchAfterCommit::class);

arch('queued jobs reach AI only through the gateway, never a provider directly')
    ->expect('App\Jobs')
    ->not->toUse('App\Services\AI\Providers');
