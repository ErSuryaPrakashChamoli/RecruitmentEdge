<?php

namespace App\Services\Platform;

use App\Console\Commands\StorageAudit;
use App\Enums\ComplianceExportStatus;
use App\Enums\PlatformCapability;
use App\Enums\PlatformEventSeverity;
use App\Enums\TenantStatus;
use App\Jobs\GenerateComplianceExport;
use App\Models\AuditLog;
use App\Models\ComplianceExport;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantSchema;
use App\Services\Tenancy\TenantStorage;
use DomainException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;
use ZipArchive;

/**
 * SaaS-5: a controlled export of one tenant's data for compliance (a data request, an exit) — the
 * operational foundation, not a discovery or warehouse tool.
 *
 * - Requested by a compliance operator (platform.compliance.manage) with a reason; registered
 *   (compliance_exports: what, which tenant, who, when, why, status, checksum, expiry, downloads)
 *   and audited in the tenant's own stream and the platform's event feed.
 * - Generated on the queue: every tenant table's rows for that tenant (JSON lines per table), its
 *   identity-plane rows, and a manifest of its files (paths and sizes — file contents are not
 *   copied), into one zip on the private disk with its SHA-256. Credentials never leave: columns
 *   that hold passwords, tokens, secrets, hashes, MFA material or embeddings are excluded (listed).
 * - Downloaded only through the platform panel by a compliance operator (each download audited);
 *   never a public or signed URL. Deleted when it expires (platform.compliance_exports.retention_days).
 */
class ComplianceExportService
{
    /**
     * Columns never exported, matched by name.
     */
    public const string EXCLUDED_COLUMNS = '/(password|token|secret|hash|remember|recovery|app_authentication|signature|embedding|api_key|credential|encrypted)/i';

    public function __construct(
        private readonly PlatformAuthorization $authorization,
        private readonly PlatformEvents $events,
    ) {}

    public function request(Tenant $tenant, string $reason, User $operator): ComplianceExport
    {
        $this->authorization->authorize($operator, PlatformCapability::ComplianceManage);
        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException('A reason is required for a compliance export.');
        }

        if (in_array($tenant->status, [TenantStatus::Provisioning, TenantStatus::Deleted], true)) {
            throw new DomainException("A {$tenant->status->label()} tenant has no data to export.");
        }

        $export = ComplianceExport::query()->create([
            'tenant_id' => $tenant->getKey(),
            'status' => ComplianceExportStatus::Requested,
            'reason' => mb_substr($reason, 0, 255),
            'requested_by' => $operator->getKey(),
            'requested_at' => now(),
            'disk' => (string) config('platform.compliance_exports.disk', 'local'),
        ]);

        AuditLog::asPlatformOperator($operator, fn () => TenantContext::current()->run($tenant, fn () => AuditLog::record($export, 'compliance_export_requested', null, ['export_id' => $export->id], $export->reason)));
        $this->events->record('compliance.export_requested', PlatformEventSeverity::Warning, "Compliance export of {$tenant->slug} requested", $tenant, ['export_id' => $export->id, 'operator_user_id' => $operator->getKey()]);
        // Platform work: queued with no tenant (the job names its register entry).
        TenantContext::current()->runWithoutTenant(fn () => Bus::dispatch(new GenerateComplianceExport((int) $export->id)));

        return $export;
    }

    /**
     * Builds the artifact (queued). Idempotent: a ready, failed or expired export is not rebuilt.
     */
    public function generate(int $exportId): string
    {
        $claimed = DB::transaction(function () use ($exportId): ?ComplianceExport {
            /** @var ComplianceExport|null $export */
            $export = ComplianceExport::query()->whereKey($exportId)->lockForUpdate()->first();

            if ($export === null || $export->status !== ComplianceExportStatus::Requested) {
                return null;
            }

            $export->forceFill(['status' => ComplianceExportStatus::Running, 'started_at' => now()])->save();

            return $export;
        });

        if ($claimed === null) {
            return 'nothing to do';
        }

        $tenantId = (int) $claimed->tenant_id;
        $workspace = sys_get_temp_dir().'/compliance-export-'.$claimed->id.'-'.bin2hex(random_bytes(4));
        $local = $workspace.'/export.zip';

        try {
            File::ensureDirectoryExists($workspace.'/data');
            $zip = new ZipArchive;

            if ($zip->open($local, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('The export archive could not be created.');
            }

            $manifest = ['tenant_id' => $tenantId, 'export_id' => $claimed->id, 'generated_at' => now()->toIso8601String(), 'tables' => [], 'excluded_columns' => [], 'files' => []];

            foreach ($this->tables() as $table) {
                $columns = collect(Schema::getColumnListing($table));
                $excluded = $columns->filter(fn (string $column): bool => preg_match(self::EXCLUDED_COLUMNS, $column) === 1)->values()->all();
                $selected = $columns->diff($excluded)->values()->all();
                $file = $workspace.'/data/'.$table.'.jsonl';
                $handle = fopen($file, 'wb');
                $rows = 0;

                foreach ($this->rows($table, $selected, $tenantId) as $row) {
                    fwrite($handle, json_encode($row, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)."\n");
                    $rows++;
                }

                fclose($handle);
                $zip->addFile($file, "data/{$table}.jsonl");
                $manifest['tables'][$table] = $rows;

                if ($excluded !== []) {
                    $manifest['excluded_columns'][$table] = $excluded;
                }
            }

            $manifest['files'] = $this->fileManifest($tenantId);
            $zip->addFromString('manifest.json', (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            if ($zip->close() !== true) {
                throw new RuntimeException('The export archive could not be written.');
            }

            $path = 'platform/compliance-exports/'.$claimed->id.'/tenant-'.$tenantId.'-export-'.$claimed->id.'.zip';
            $stream = fopen($local, 'rb');
            Storage::disk($claimed->disk)->put($path, $stream);

            if (is_resource($stream)) {
                fclose($stream);
            }

            $claimed->forceFill([
                'status' => ComplianceExportStatus::Ready,
                'path' => $path,
                'bytes' => filesize($local),
                'sha256' => hash_file('sha256', $local),
                'manifest' => ['tables' => $manifest['tables'], 'excluded_columns' => $manifest['excluded_columns'], 'files' => count($manifest['files'])],
                'completed_at' => now(),
                'expires_at' => now()->addDays((int) config('platform.compliance_exports.retention_days', 7)),
            ])->save();

            $tenant = Tenant::query()->find($tenantId);
            TenantContext::current()->run($tenantId, fn () => AuditLog::record($claimed, 'compliance_export_ready', null, ['export_id' => $claimed->id, 'sha256' => $claimed->sha256, 'tables' => count($manifest['tables']), 'expires_at' => $claimed->expires_at->toIso8601String()]));
            $this->events->record('compliance.export_ready', PlatformEventSeverity::Info, 'Compliance export ready: '.($tenant?->slug ?? $tenantId), $tenant, ['export_id' => $claimed->id], "compliance.export_ready:{$claimed->id}");

            return 'ready';
        } catch (Throwable $e) {
            $claimed->forceFill(['status' => ComplianceExportStatus::Failed, 'error' => mb_substr($e::class.': '.$e->getMessage(), 0, 255)])->save();
            Log::error('platform.compliance_export_failed', ['export_id' => $claimed->id, 'exception' => $e::class]);
            $this->events->record('compliance.export_failed', PlatformEventSeverity::Critical, 'Compliance export failed', Tenant::query()->find($tenantId), ['export_id' => $claimed->id, 'error' => $e::class], "compliance.export_failed:{$claimed->id}");

            return 'failed';
        } finally {
            File::deleteDirectory($workspace);
        }
    }

    /**
     * One table's rows of the tenant, streamed: by id where there is one, else in a total order.
     *
     * @param  list<string>  $columns
     * @return iterable<object>
     */
    private function rows(string $table, array $columns, int $tenantId): iterable
    {
        $chunk = (int) config('platform.compliance_exports.chunk', 1000);
        // Members' identities are global (no tenant id): the tenant's are those it has memberships for.
        $query = $table === 'users'
            ? DB::table('users')->whereIn('id', DB::table('tenant_memberships')->where('tenant_id', $tenantId)->select('user_id'))->select($columns)
            : DB::table($table)->where('tenant_id', $tenantId)->select($columns);

        if (in_array('id', $columns, true)) {
            return $query->lazyById($chunk, 'id');
        }

        foreach ($columns as $column) {
            $query->orderBy($column);
        }

        return $query->lazy($chunk);
    }

    /**
     * A compliance operator downloads a ready export (audited, counted).
     */
    public function download(ComplianceExport $export, User $operator): StreamedResponse
    {
        $this->authorization->authorize($operator, PlatformCapability::ComplianceManage);
        $export = $export->fresh();

        if ($export->status !== ComplianceExportStatus::Ready || $export->expires_at === null || $export->expires_at->isPast() || ! Storage::disk($export->disk)->exists((string) $export->path)) {
            throw new DomainException('This export is not available.');
        }

        ComplianceExport::query()->whereKey($export->id)->update(['download_count' => DB::raw('download_count + 1'), 'last_downloaded_at' => now(), 'last_downloaded_by' => $operator->getKey(), 'updated_at' => now()]);
        AuditLog::asPlatformOperator($operator, fn () => TenantContext::current()->run((int) $export->tenant_id, fn () => AuditLog::record($export, 'compliance_export_downloaded', null, ['export_id' => $export->id, 'sha256' => $export->sha256])));

        return Storage::disk($export->disk)->download((string) $export->path, basename((string) $export->path));
    }

    /**
     * Deletes expired artifacts (platform:sweep); the register entry stays.
     */
    public function expireDue(): int
    {
        $expired = 0;

        // By id: each row leaves the filter as it expires, so offset pages would skip rows.
        ComplianceExport::query()->where('status', ComplianceExportStatus::Ready->value)->where('expires_at', '<=', now())->lazyById(200)->each(function (ComplianceExport $export) use (&$expired): void {
            $done = DB::transaction(function () use ($export): bool {
                /** @var ComplianceExport $locked */
                $locked = ComplianceExport::query()->whereKey($export->id)->lockForUpdate()->firstOrFail();

                if ($locked->status !== ComplianceExportStatus::Ready) {
                    return false;
                }

                if ($locked->path !== null) {
                    Storage::disk($locked->disk)->deleteDirectory(dirname((string) $locked->path));
                }

                $locked->forceFill(['status' => ComplianceExportStatus::Expired, 'expired_at' => now()])->save();
                TenantContext::current()->run((int) $locked->tenant_id, fn () => AuditLog::record($locked, 'compliance_export_expired', null, ['export_id' => $locked->id]));

                return true;
            });

            $expired += $done ? 1 : 0;
        });

        return $expired;
    }

    /**
     * Every table that holds this tenant's rows: tenant-owned (retained ones included — an export
     * is a copy), tenant-attributed, and the tenant's side of the identity plane (its members'
     * identities without credentials).
     *
     * @return list<string>
     */
    private function tables(): array
    {
        return array_values(array_unique([...TenantSchema::TENANT_TABLES, ...TenantSchema::NULLABLE_TENANT_TABLES, 'tenant_memberships', 'users', 'roles', 'model_has_roles', 'model_has_permissions']));
    }

    /**
     * The tenant's files: paths and sizes (not contents).
     *
     * @return list<array{disk: string, path: string, bytes: int}>
     */
    private function fileManifest(int $tenantId): array
    {
        $files = [];

        foreach (['local', 'public'] as $disk) {
            foreach (Storage::disk($disk)->allFiles(TenantStorage::ROOT.'/'.$tenantId) as $path) {
                $files[] = ['disk' => $disk, 'path' => $path, 'bytes' => (int) Storage::disk($disk)->size($path)];
            }
        }

        DB::table('exports')->where('tenant_id', $tenantId)->select(['id', 'file_disk'])->lazyById(500)
            ->each(function (object $export) use (&$files): void {
                $disk = filled($export->file_disk) ? (string) $export->file_disk : 'local';

                foreach (Storage::disk($disk)->allFiles('filament_exports/'.$export->id) as $path) {
                    $files[] = ['disk' => $disk, 'path' => $path, 'bytes' => (int) Storage::disk($disk)->size($path)];
                }
            });

        foreach (StorageAudit::REFERENCES as $reference) {
            DB::table($reference['table'])->where('tenant_id', $tenantId)->whereNotNull($reference['column'])->where($reference['column'], 'not like', TenantStorage::ROOT.'/'.$tenantId.'/%')
                ->select(array_values(array_filter(['id', $reference['column'], $reference['disk_column'] ?? null])))
                ->lazyById(500, 'id')
                ->each(function (object $row) use ($reference, &$files): void {
                    $path = (string) $row->{$reference['column']};
                    $disk = isset($reference['disk_column']) && filled($row->{$reference['disk_column']} ?? null) ? (string) $row->{$reference['disk_column']} : $reference['disk'];

                    if (isset($reference['fallback_disk']) && ! Storage::disk($disk)->exists($path)) {
                        $disk = $reference['fallback_disk'];
                    }

                    $files[] = ['disk' => $disk, 'path' => $path, 'bytes' => Storage::disk($disk)->exists($path) ? (int) Storage::disk($disk)->size($path) : 0];
                });
        }

        return $files;
    }
}
