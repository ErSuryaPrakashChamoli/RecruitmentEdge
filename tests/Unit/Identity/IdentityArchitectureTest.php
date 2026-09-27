<?php

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * Phase 8.4: the service layer is the identity security boundary. These checks keep a Filament
 * form, tool or job from quietly becoming a second path for roles and permissions.
 */
function identityAppFiles(): array
{
    $root = dirname(__DIR__, 3);

    return collect(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app')))
        ->filter(fn (SplFileInfo $file) => $file->getExtension() === 'php')
        ->mapWithKeys(fn (SplFileInfo $file) => [str_replace($root.'/', '', $file->getPathname()) => (string) file_get_contents($file->getPathname())])
        ->all();
}

test('no Filament form syncs roles or permissions through a relationship', function (): void {
    foreach (identityAppFiles() as $path => $source) {
        expect((bool) preg_match("/(CheckboxList|Select|Toggle)::make\\('(roles|permissions)'\\)[^;]*?->relationship\\(/s", $source))->toBeFalse("{$path} syncs roles or permissions directly");
    }
});

test('role and permission assignment happens only in RoleAssignmentService (and revocation / restore in StaffAccessService)', function (): void {
    $allowed = ['app/Services/Identity/RoleAssignmentService.php', 'app/Services/Identity/StaffAccessService.php'];

    foreach (identityAppFiles() as $path => $source) {
        if (in_array($path, $allowed, true)) {
            continue;
        }

        expect((bool) preg_match('/->(syncRoles|assignRole|removeRole|givePermissionTo|syncPermissions|revokePermissionTo)\(|roles\(\)->(sync|attach|detach)\(/', $source))->toBeFalse("{$path} assigns roles or permissions outside RoleAssignmentService");
    }
});

test('the application uses its own Role model, never Spatie\'s directly', function (): void {
    foreach (identityAppFiles() as $path => $source) {
        if ($path === 'app/Models/Role.php') {
            continue;
        }

        expect(str_contains($source, 'Spatie\\Permission\\Models\\Role'))->toBeFalse("{$path} uses Spatie's Role model instead of App\\Models\\Role");
    }
});

test('protected roles are found by their key, never by display name', function (): void {
    foreach (identityAppFiles() as $path => $source) {
        expect((bool) preg_match("/Role::(findByName|query\\(\\)->where\\('name')/", $source))->toBeFalse("{$path} looks a role up by its editable name");
    }
});

test('identity state is written only by the identity services', function (): void {
    $allowed = [
        'app/Services/Identity/StaffAccessService.php',
        'app/Services/Identity/EmployeeLifecycleService.php',
        'app/Services/Identity/IdentityProvisioningService.php',
        'app/Services/Identity/HierarchyIntegrityService.php',
        'app/Services/Identity/SessionRevocationService.php',
        // Creates the employee with its first manager (creation, not a change) — the provisioning path.
        'app/Services/EmployeeConversionService.php',
    ];

    foreach (identityAppFiles() as $path => $source) {
        if (in_array($path, $allowed, true)) {
            continue;
        }

        expect((bool) preg_match("/['\"](access_status|reports_to_id|effective_applied_at|revoked_roles|session_epoch)['\"]\\s*=>/", $source) && preg_match('/(forceFill|update|fill)\(/', $source) && ! str_contains($path, 'Filament/') && ! str_contains($path, 'Console/Commands/'))->toBeFalse("{$path} writes identity state outside the identity services");
    }
});

test('there is no API-token surface that revocation would have to cover', function (): void {
    $root = dirname(__DIR__, 3);
    $composer = (string) file_get_contents($root.'/composer.json');

    expect(str_contains($composer, 'laravel/sanctum'))->toBeFalse('Sanctum was added: make StaffAccessService revoke its tokens and extend this test')
        ->and(str_contains($composer, 'laravel/passport'))->toBeFalse('Passport was added: make StaffAccessService revoke its tokens and extend this test')
        ->and(collect(identityAppFiles())->contains(fn (string $source) => str_contains($source, 'HasApiTokens')))->toBeFalse()
        ->and(collect(glob($root.'/database/migrations/*.php'))->contains(fn (string $file) => str_contains((string) file_get_contents($file), 'personal_access_tokens')))->toBeFalse();
});

test('identity events are ids-only and dispatched after commit', function (): void {
    foreach (['EmployeeAccessSuspended', 'EmployeeAccessRevoked', 'EmployeeAccessRestored', 'EmployeeSeparated', 'SeparationCancelled', 'UserProvisioned', 'UserRoleChanged'] as $event) {
        $class = new ReflectionClass("App\\Events\\{$event}");

        expect($class->implementsInterface(ShouldDispatchAfterCommit::class))->toBeTrue("{$event} is not after-commit");

        foreach ($class->getConstructor()->getParameters() as $parameter) {
            expect(in_array((string) $parameter->getType(), ['int', '?int', 'string', 'bool'], true))->toBeTrue("{$event}::\${$parameter->getName()} carries more than an id");
        }
    }
});

test('AI approval re-checks authority and automation re-checks its owner before running', function (): void {
    $root = dirname(__DIR__, 3);

    expect((string) file_get_contents($root.'/app/Services/AI/Actions/ActionExecutor.php'))->toContain('$this->assertStillAuthorised($toolCall, $actor);')
        ->and((string) file_get_contents($root.'/app/Services/Automation/AutomationEngine.php'))->toContain('ownerAuthorityProblem($rule)');
});
