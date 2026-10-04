<?php

/**
 * SaaS-2: the identity / membership / platform boundaries, enforced by construction. These fail
 * when new code would quietly step around them.
 */
function identityPlaneSources(): array
{
    $root = dirname(__DIR__, 3);

    return collect(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app')))
        ->filter(fn (SplFileInfo $file) => $file->getExtension() === 'php')
        ->mapWithKeys(fn (SplFileInfo $file) => [str_replace($root.'/', '', $file->getPathname()) => (string) file_get_contents($file->getPathname())])
        ->all();
}

test('no query treats the employee link or the access state as columns of the identity', function (): void {
    // They live on tenant_memberships (SaaS-2). On SQLite a dropped column named in a query is read
    // as a string literal — the query silently matches nothing — so this guard catches it first.
    foreach (identityPlaneSources() as $path => $source) {
        expect((bool) preg_match("/['\"]users\\.(employee_id|access_status|access_reason|access_source|access_changed_at|access_changed_by|revoked_roles)['\"]/", $source))->toBeFalse("{$path} names a column the identity no longer has");

        foreach (['User::query()', 'User::where'] as $needle) {
            $offset = 0;

            while (($at = strpos($source, $needle, $offset)) !== false) {
                $statement = substr($source, $at, (strpos($source, ';', $at) ?: strlen($source)) - $at);
                $outsideMembership = preg_replace('/membersOfCurrentTenant\(fn[^)]*\)\s*=>[^;]*?\)\)/s', '', $statement);

                expect((bool) preg_match("/->(where|whereIn|whereNull|whereNotNull|orderBy|pluck|value)\\(['\"](employee_id|access_status|revoked_roles)['\"]/", (string) $outsideMembership))
                    ->toBeFalse("{$path} queries a User by a membership column: ".mb_substr(preg_replace('/\s+/', ' ', $statement), 0, 160));

                $offset = $at + strlen($needle);
            }
        }
    }
});

test('memberships are created only by accepting an invitation or by the provisioning service', function (): void {
    $allowed = ['app/Services/Identity/TenantInvitationService.php', 'app/Services/Identity/IdentityProvisioningService.php'];

    foreach (identityPlaneSources() as $path => $source) {
        if (in_array($path, $allowed, true)) {
            continue;
        }

        expect((bool) preg_match('/TenantMembership::(query\(\)->)?(create|firstOrCreate|updateOrCreate|insert|forceCreate)\(|new TenantMembership\b/', $source))->toBeFalse("{$path} creates a tenant membership outside the identity services");
    }
});

test('the tenant plane never consults the platform plane', function (): void {
    $platform = '/\b(PlatformOperator|PlatformAccess|SupportAccessGrant|SupportAccessService|PlatformRole)\b/';

    foreach (identityPlaneSources() as $path => $source) {
        $isPlatform = str_starts_with($path, 'app/Services/Platform/')
            || in_array($path, ['app/Models/PlatformOperator.php', 'app/Models/SupportAccessGrant.php', 'app/Enums/PlatformRole.php', 'app/Console/Commands/PlatformOperatorCommand.php', 'app/Console/Commands/DisableIdentity.php'], true);

        if (! $isPlatform) {
            expect((bool) preg_match($platform, $source))->toBeFalse("{$path} consults the platform plane — platform roles never grant tenant access");
        }
    }
});

test('an invitation token is never logged, audited or stored', function (): void {
    foreach (['app/Services/Identity/TenantInvitationService.php', 'app/Http/Controllers/Identity/TenantInvitationController.php'] as $path) {
        $source = identityPlaneSources()[$path];

        foreach (['Log::', 'AuditLog::record('] as $needle) {
            $offset = 0;

            while (($at = strpos($source, $needle, $offset)) !== false) {
                $statement = substr($source, $at, (strpos($source, ';', $at) ?: strlen($source)) - $at);

                expect(str_contains($statement, '$token'))->toBeFalse("{$path} writes the invitation token: ".mb_substr($statement, 0, 120));

                $offset = $at + strlen($needle);
            }
        }

        expect((bool) preg_match("/'token_hash'\\s*=>\\s*\\\$token\\b/", $source))->toBeFalse("{$path} stores a token in clear");
    }
});
