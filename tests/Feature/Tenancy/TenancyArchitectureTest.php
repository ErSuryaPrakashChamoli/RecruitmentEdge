<?php

use App\Http\Controllers\PrivateFileController;
use App\Http\Middleware\ResolveTenantFromRoute;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Export;
use App\Models\FailedImportRow;
use App\Models\Import;
use App\Services\Tenancy\TenantSchema;
use Filament\Actions\Exports\Models\Export as FilamentExport;
use Filament\Actions\Imports\Models\FailedImportRow as FilamentFailedImportRow;
use Filament\Actions\Imports\Models\Import as FilamentImport;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Finder\Finder;

/*
 * SaaS-1: the tenant boundary is enforced by construction. These checks fail when new code would
 * quietly step around it — a model without BelongsToTenant, an unreviewed bypass, a raw query or a
 * cache key without the tenant, an upload outside the tenant prefix, a route that never resolves
 * its tenant. Each allow-list entry names why it is platform-level or resolves its tenant itself.
 */

/**
 * @return array<string, string> relative path => contents
 */
function tenancyArchSources(string $directory = 'app'): array
{
    $files = [];

    foreach ((new Finder)->files()->in(base_path($directory))->name('*.php') as $file) {
        $files[str_replace(base_path().'/', '', $file->getRealPath())] = $file->getContents();
    }

    return $files;
}

/**
 * Each call of $needle (e.g. "DB::table(") with the statement it starts, up to its semicolon.
 *
 * @return list<array{file: string, statement: string}>
 */
function tenancyArchStatements(string $needle): array
{
    $found = [];

    foreach (tenancyArchSources() as $file => $source) {
        $offset = 0;

        while (($at = strpos($source, $needle, $offset)) !== false) {
            $end = strpos($source, ';', $at);
            $found[] = ['file' => $file, 'statement' => substr($source, $at, ($end === false ? strlen($source) : $end) - $at)];
            $offset = $at + strlen($needle);
        }
    }

    return $found;
}

test('every model of a tenant-owned table belongs to a tenant', function (): void {
    $missing = [];

    foreach (tenancyArchSources('app/Models') as $file => $source) {
        $class = 'App\\Models\\'.str_replace(['app/Models/', '.php', '/'], ['', '', '\\'], $file);

        if (! class_exists($class) || ! is_subclass_of($class, Model::class) || (new ReflectionClass($class))->isAbstract()) {
            continue;
        }

        if (TenantSchema::isTenantOwned((new $class)->getTable()) && ! in_array(BelongsToTenant::class, class_uses_recursive($class), true)) {
            $missing[] = $class;
        }
    }

    expect($missing)->toBe([]);
});

test('Filament\'s export and import records are the tenant-owned models', function (): void {
    expect(app(FilamentExport::class))->toBeInstanceOf(Export::class)
        ->and(app(FilamentImport::class))->toBeInstanceOf(Import::class)
        ->and(app(FilamentFailedImportRow::class))->toBeInstanceOf(FailedImportRow::class);
});

test('crossing tenants happens only in reviewed places', function (): void {
    // file => why it may look across tenants
    $allowed = [
        'app/Services/Tenancy/TenantScope.php' => 'defines the bypass',
        'app/Services/Lifecycle/RowLock.php' => 'drops soft-delete scopes, then re-imposes the tenant itself',
        'app/Services/Communication/DeliveryStatusService.php' => 'finds a provider callback\'s tenant from the provider\'s own message id, then works inside it',
        'app/Http/Middleware/ResolveTenantForFilamentDownload.php' => 'reads an export\'s tenant by id before checking access to it',
        'app/Services/QueueHealthService.php' => 'platform health: counts only, with no tenant established',
    ];

    $found = collect(tenancyArchSources())
        ->filter(fn (string $source) => preg_match('/withoutTenancy\(|withoutGlobalScope\(\s*TenantScope|withoutGlobalScopes\(\s*\)/', $source) === 1)
        ->keys()->sort()->values()->all();

    expect(array_diff($found, array_keys($allowed)))->toBe([]);
});

test('a raw query on a tenant-owned table names its tenant', function (): void {
    // file => why its raw queries are platform-level
    $platform = [
        'app/Services/Tenancy/TenantBackfill.php' => 'the Tenant #1 backfill (migration)',
        'app/Services/Tenancy/TenancyVerifier.php' => 'read-only integrity report across tenants',
        'app/Console/Commands/StorageAudit.php' => 'read-only storage report across tenants',
        'app/Models/User.php' => 'identity plane: the employing tenant of a login',
        'app/Observers/EmployeeObserver.php' => 'closure rows are built with the employee\'s tenant_id before the insert',
    ];
    $offending = [];

    foreach (tenancyArchStatements('DB::table(') as ['file' => $file, 'statement' => $statement]) {
        $table = Str::between($statement, "DB::table('", "'");

        if (isset($platform[$file]) || ! TenantSchema::isTenantOwned(Str::before($table, ' as '))) {
            continue;
        }

        if (! str_contains($statement, 'tenant_id') && ! str_contains($statement, 'TenantContext')) {
            $offending[] = "{$file}: ".Str::limit(preg_replace('/\s+/', ' ', $statement), 120);
        }
    }

    expect($offending)->toBe([]);
});

test('every cache entry or lock of tenant data is keyed under its tenant', function (): void {
    // file => why its keys are global on purpose
    $global = [
        'app/Console/Commands/HeartbeatCheck.php' => 'worker heartbeats (platform)',
        'app/Services/WorkerHeartbeat.php' => 'worker heartbeats (platform)',
        'app/Services/SchedulerHeartbeat.php' => 'scheduler heartbeat (platform)',
        'app/Services/Communication/ProviderCircuitBreaker.php' => 'one breaker per shared provider account (platform credentials)',
        'app/Services/Integrations/Video/ZoomMeetingProvider.php' => 'the platform Zoom account\'s token',
        'app/Services/Communication/DeliveryStatusService.php' => 'held statuses keyed by the provider\'s globally unique message id',
        'app/Services/CandidateStepUpService.php' => 'keyed by the portal account id (global id)',
        'app/Services/Automation/Actions/Handlers/SendCommunicationAction.php' => 'lock keyed by the candidate id (global id)',
        'app/Services/Identity/CredentialService.php' => 'staff identity (global)',
        'app/Services/Metrics/MetricService.php' => 'the key comes from cacheKey(), built with TenantCache::key()',
    ];
    $offending = [];

    foreach (['Cache::remember(', 'Cache::rememberForever(', 'Cache::lock(', 'Cache::add(', 'Cache::put(', 'Cache::forget(', 'Cache::pull(', 'Cache::get(', 'Cache::forever(', 'Cache::increment('] as $needle) {
        foreach (tenancyArchStatements($needle) as ['file' => $file, 'statement' => $statement]) {
            if (! isset($global[$file]) && ! str_contains($statement, 'TenantCache::key(')) {
                $offending[] = "{$file}: ".Str::limit(preg_replace('/\s+/', ' ', $statement), 120);
            }
        }
    }

    expect($offending)->toBe([]);
});

test('uploads are stored under the tenant\'s prefix', function (): void {
    $offending = collect(tenancyArchStatements('->directory('))
        ->reject(fn (array $call) => str_contains($call['statement'], 'TenantStorage::path('))
        ->map(fn (array $call) => "{$call['file']}: ".Str::limit($call['statement'], 100))
        ->values()->all();

    expect($offending)->toBe([]);
});

test('work is never queued from a callback whose result outlives its tenant', function (): void {
    // TenantContext::run(..., fn () => Job::dispatch()) returns a PendingDispatch, which queues
    // only after run() has restored the previous tenant: the job would carry the wrong tenant.
    $offending = collect(tenancyArchSources())
        ->filter(fn (string $source) => preg_match('/->run\([^;]*fn\s*\([^)]*\)\s*(:\s*\w+\s*)?=>\s*(?!Bus::)[\\\\\w]+::dispatch\(/', $source) === 1)
        ->keys()->values()->all();

    expect($offending)->toBe([]);
});

test('every route either lives under a tenant or resolves its tenant itself', function (): void {
    // uri prefix => how the route finds its tenant, or why it has none
    $resolvedOtherwise = [
        'admin/login' => 'staff sign-in (global identity)', 'admin/logout' => 'staff sign-out',
        'admin/password-reset' => 'staff password reset (global identity)', 'admin/email-change-verification' => 'staff email (global identity)',
        'admin/multi-factor-authentication' => 'staff MFA (global identity)', 'admin/profile' => 'runs in the employing tenant (Profile::boot)',
        'filament/exports' => 'the export\'s own tenant (ResolveTenantForFilamentDownload)', 'filament/imports' => 'the import\'s own tenant (ResolveTenantForFilamentDownload)',
        'files/private' => 'the signed tenant (PrivateFileController)', 'webhooks/' => 'the provider message\'s tenant (DeliveryStatusService)',
        'integrations/calendar/{provider}/callback' => 'the tenant kept with the OAuth state', 'health/queue' => 'platform health (token)',
        'livewire-' => 'the component page\'s own (persistent) middleware', '_boost/' => 'development tool (not in the production image)',
    ];
    $offending = [];

    foreach (Route::getRoutes() as $route) {
        $uri = $route->uri();

        if (in_array($uri, ['/', 'up', 'admin'], true) || str_starts_with($uri, 'admin/{tenant') || str_contains($uri, '{tenant}') && in_array(ResolveTenantFromRoute::class, $route->gatherMiddleware(), true)) {
            continue;
        }

        if (collect($resolvedOtherwise)->keys()->contains(fn (string $prefix) => str_starts_with($uri, $prefix))) {
            continue;
        }

        $offending[] = $uri;
    }

    expect($offending)->toBe([]);
});

test('every private-file owner the download checks names a real column', function (): void {
    foreach (PrivateFileController::OWNERS as $owner) {
        $table = (new $owner['model'])->getTable();

        expect(Schema::hasColumn($table, $owner['column']))->toBeTrue("{$table}.{$owner['column']} does not exist");
    }
});
