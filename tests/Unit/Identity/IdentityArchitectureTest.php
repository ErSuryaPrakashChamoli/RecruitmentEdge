<?php

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
