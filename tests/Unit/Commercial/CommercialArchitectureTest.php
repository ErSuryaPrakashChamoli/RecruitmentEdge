<?php

/**
 * SaaS-3: the commercial boundaries, enforced by construction — the control plane is reachable
 * only from the platform, and the product asks the entitlement registry, never a plan.
 */
function commercialSources(): array
{
    $root = dirname(__DIR__, 3);

    return collect(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app')))
        ->filter(fn (SplFileInfo $file) => $file->getExtension() === 'php')
        ->mapWithKeys(fn (SplFileInfo $file) => [str_replace($root.'/', '', $file->getPathname()) => (string) file_get_contents($file->getPathname())])
        ->all();
}

function commercialIsPlatformCommand(string $path): bool
{
    return in_array($path, array_map(fn (string $command): string => "app/Console/Commands/{$command}.php", ['PlansSync', 'TenantsProvision', 'TenantsPlan', 'TenantsEntitlement', 'TenantsLifecycle', 'TenantsLifecycleSweep']), true);
}

test('only the platform reaches the commercial control plane — no page, route, Livewire component or job', function (): void {
    foreach (commercialSources() as $path => $source) {
        if (str_starts_with($path, 'app/Services/Platform/Commercial/') || commercialIsPlatformCommand($path)) {
            continue;
        }

        expect(str_contains($source, 'Platform\\Commercial'))->toBeFalse("{$path} reaches the commercial control plane — plans, overrides and lifecycle are platform-only");
    }
});

test('the product asks the entitlement registry, never which plan a tenant is on', function (): void {
    $planModels = '/\b(Plan|PlanVersion|PlanEntitlement|TenantPlanAssignment|TenantEntitlementOverride)::|\b(plan_entitlements|tenant_plan_assignments|tenant_entitlement_overrides)\b/';
    $allowed = ['app/Services/Entitlements/EntitlementService.php', 'app/Filament/Pages/PlanAndUsage.php', 'app/Services/Tenancy/TenantSchema.php'];

    foreach (commercialSources() as $path => $source) {
        if (str_starts_with($path, 'app/Services/Platform/Commercial/') || str_starts_with($path, 'app/Models/') || in_array($path, $allowed, true)) {
            continue;
        }

        expect((bool) preg_match($planModels, $source))->toBeFalse("{$path} reads plans directly — gate on App\\Enums\\Entitlement through EntitlementService");
    }
});

test('entitlement keys are named through the registry, never as strings', function (): void {
    $keys = '/[\'"](ai\.assistant|automation\.rules|distribution\.job_boards|exports\.data|requisitions\.active\.max|members\.active\.max)[\'"]/';

    foreach (commercialSources() as $path => $source) {
        if ($path !== 'app/Enums/Entitlement.php') {
            expect((bool) preg_match($keys, $source))->toBeFalse("{$path} names an entitlement key as a string — use App\\Enums\\Entitlement");
        }
    }
});

test('a tenant\'s lifecycle state is written only by the lifecycle and provisioning services', function (): void {
    foreach (commercialSources() as $path => $source) {
        if (str_starts_with($path, 'app/Services/Platform/Commercial/')) {
            continue;
        }

        expect((bool) preg_match('/(Tenant::[^;]*->(update|forceFill|fill)\(\s*\[[^;]*[\'"](status|trial_ends_at|entitlement_version)[\'"]\s*=>|\$tenant->(status|trial_ends_at|entitlement_version)\s*=[^=])/s', $source))
            ->toBeFalse("{$path} writes a tenant's lifecycle or commercial state outside TenantLifecycleService");
    }
});
