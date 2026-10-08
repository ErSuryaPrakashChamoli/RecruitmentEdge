<?php

use Illuminate\Support\Facades\Gate;

/**
 * Phase 8.6 (D8.6-027): every model a Filament resource or relation manager shows has a policy, so
 * strict authorization (local/test) never meets an undeclared model and production's fail-closed
 * gate always has a rule to read.
 */
test('every model shown by a resource or relation manager has a policy', function (): void {
    $root = dirname(__DIR__, 3);
    $missing = [];

    foreach (glob($root.'/app/Filament/Resources/*/*Resource.php') as $resourceFile) {
        $resource = 'App\\Filament\\Resources\\'.basename(dirname($resourceFile)).'\\'.basename($resourceFile, '.php');
        $model = $resource::getModel();
        $models = [$model];

        foreach ($resource::getRelations() as $relationManager) {
            $manager = is_string($relationManager) ? $relationManager : $relationManager->relationManager;
            $relationship = (new ReflectionClass($manager))->getStaticPropertyValue('relationship');
            $models[] = (new $model)->{$relationship}()->getRelated()::class;
        }

        foreach ($models as $shown) {
            if (Gate::getPolicyFor($shown) === null) {
                $missing[] = class_basename($resource).' shows '.class_basename($shown).' without a policy';
            }
        }
    }

    expect(array_values(array_unique($missing)))->toBe([]);
});
