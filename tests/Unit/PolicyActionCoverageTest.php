<?php

/**
 * Security guard (Phase 8.6 discovery): Filament treats a missing policy method as "allowed", so a
 * resource that offers a delete / force-delete / restore action (single or bulk) must have a policy
 * that defines the matching method — an explicit rule, never an accidental grant.
 */
test('every destructive Filament action is backed by an explicit policy method', function (): void {
    $root = dirname(__DIR__, 2);
    $required = [
        'DeleteAction' => 'delete', 'ForceDeleteAction' => 'forceDelete', 'RestoreAction' => 'restore',
        'DeleteBulkAction' => 'deleteAny', 'ForceDeleteBulkAction' => 'forceDeleteAny', 'RestoreBulkAction' => 'restoreAny',
    ];
    $missing = [];

    foreach (glob($root.'/app/Filament/Resources/*/*Resource.php') as $resourceFile) {
        $resource = 'App\\Filament\\Resources\\'.basename(dirname($resourceFile)).'\\'.basename($resourceFile, '.php');
        $model = $resource::getModel();
        $policy = 'App\\Policies\\'.class_basename($model).'Policy';
        $source = collect(glob(dirname($resourceFile).'/{*,*/*}.php', GLOB_BRACE))
            ->reject(fn (string $file) => str_contains($file, 'RelationManagers'))
            ->map(fn (string $file) => file_get_contents($file))->implode("\n");

        foreach ($required as $action => $method) {
            if (preg_match('/\b'.$action.'::make\(/', $source) && ! (class_exists($policy) && method_exists($policy, $method))) {
                $missing[] = class_basename($resource).": {$action} needs {$policy}::{$method}()";
            }
        }
    }

    expect($missing)->toBe([]);
});
