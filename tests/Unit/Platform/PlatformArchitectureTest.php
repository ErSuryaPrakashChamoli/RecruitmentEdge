<?php

/**
 * SaaS-5: the platform control plane's boundaries, enforced by construction.
 */
function platformSources(string $directory = 'app'): array
{
    $root = dirname(__DIR__, 3);

    return collect(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory)))
        ->filter(fn (SplFileInfo $file) => $file->getExtension() === 'php')
        ->mapWithKeys(fn (SplFileInfo $file) => [str_replace($root.'/', '', $file->getPathname()) => (string) file_get_contents($file->getPathname())])
        ->all();
}

test('the platform UI lives in its own panel and nothing else reaches into it', function (): void {
    foreach (platformSources() as $path => $source) {
        if (str_starts_with($path, 'app/Filament/Platform/') || $path === 'app/Providers/Filament/PlatformPanelProvider.php') {
            continue;
        }

        expect(str_contains($source, 'App\\Filament\\Platform'))->toBeFalse("{$path} reaches into the platform panel");
    }
});

test('every platform page and widget decides access by a platform capability', function (): void {
    foreach (platformSources('app/Filament/Platform') as $path => $source) {
        if (str_contains($path, '/Concerns/')) {
            continue;
        }

        expect((bool) preg_match('/function can(Access|View)\(\): bool\s*\{[^}]*(PlatformCapability::|isOperator\()/s', $source))->toBeTrue("{$path} does not decide access by a platform capability");
    }
});

test('the audit trail is never updated or deleted by the application', function (): void {
    foreach (platformSources() as $path => $source) {
        expect((bool) preg_match("/AuditLog::(query\\(\\)|where)[^;]*->(update|delete|forceDelete|truncate)\\(|DB::table\\('audit_logs'\\)[^;]*->(update|delete|truncate)\\(/s", $source))
            ->toBeFalse("{$path} updates or deletes audit entries — the audit trail is append-only");
    }
});

test('platform jobs are queued with no tenant', function (): void {
    foreach (platformSources() as $path => $source) {
        expect((bool) preg_match('/\b(PurgeTenantJob|GenerateComplianceExport)::dispatch(Sync|Now)?\(/', $source))->toBeFalse("{$path} queues a platform job inside whatever tenant is current")
            ->and((bool) preg_match('/new (PurgeTenantJob|GenerateComplianceExport)\(/', $source) && ! str_contains($source, 'runWithoutTenant(fn () => Bus::dispatch(new'))->toBeFalse("{$path} builds a platform job outside runWithoutTenant");
    }
});
