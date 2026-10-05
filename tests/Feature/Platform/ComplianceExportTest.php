<?php

use App\Enums\ComplianceExportStatus;
use App\Filament\Platform\Pages\ComplianceExports;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\ComplianceExport;
use App\Services\Platform\ComplianceExportService;
use App\Services\Tenancy\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Feature\Platform\PlatformWorld;

/*
 * SaaS-5: a compliance export is registered (what, tenant, who, when, why, status, checksum,
 * expiry), generated on the queue into one private archive of the tenant's rows — never another
 * tenant's, never a credential — downloaded only by compliance operators (audited), and deleted
 * when it expires.
 */
beforeEach(function (): void {
    $this->world = PlatformWorld::build($this->tenant);
    $this->exports = app(ComplianceExportService::class);
    $this->mine = Candidate::factory()->create(['full_name' => 'Ours Candidate']);
    $this->theirs = TenantContext::current()->run($this->world->identity->beta, fn () => Candidate::factory()->create(['full_name' => 'Theirs Candidate']));
    Storage::disk('local')->put("tenants/{$this->tenant->id}/candidate-documents/cv.pdf", 'cv');
});

/**
 * @return array{manifest: array<string, mixed>, tables: array<string, list<array<string, mixed>>>}
 */
function complianceArchive(ComplianceExport $export): array
{
    $local = tempnam(sys_get_temp_dir(), 'export-test');
    file_put_contents($local, Storage::disk($export->disk)->get($export->path));
    $zip = new ZipArchive;
    $zip->open($local);
    $tables = [];

    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);

        if (str_starts_with($name, 'data/')) {
            $tables[basename($name, '.jsonl')] = array_map(fn (string $line): array => json_decode($line, true), array_values(array_filter(explode("\n", (string) $zip->getFromIndex($i)))));
        }
    }

    $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
    $zip->close();
    unlink($local);

    return ['manifest' => $manifest, 'tables' => $tables];
}

test('an export holds only the tenant\'s own rows and files, without credentials, with its checksum and expiry', function (): void {
    $export = $this->exports->request($this->tenant, 'DSAR #12 from a candidate', $this->world->compliance)->fresh();

    expect($export->status)->toBe(ComplianceExportStatus::Ready)
        ->and($export->requested_by)->toBe($this->world->compliance->id)
        ->and($export->sha256)->toBe(hash('sha256', Storage::disk('local')->get($export->path)))
        ->and($export->expires_at->isSameDay(now()->addDays(7)))->toBeTrue()
        ->and(str_starts_with($export->path, 'platform/compliance-exports/'))->toBeTrue();

    $archive = complianceArchive($export);

    expect(collect($archive['tables']['candidates'])->pluck('full_name')->all())->toBe(['Ours Candidate'])
        ->and(collect($archive['tables'])->except('users')->flatten(1)->pluck('tenant_id')->unique()->values()->all())->toBe([$this->tenant->id])
        ->and(collect($archive['tables']['users'])->pluck('email'))->toContain('chief@acme.test')->not->toContain('chief@beta.test', 'admin@platform.test')
        ->and($archive['manifest']['excluded_columns'])->toHaveKey('users')
        ->and(collect($archive['tables']['tenant_memberships'])->pluck('user_id'))->toContain($this->world->identity->adminA->id)
        ->and(collect($archive['manifest']['files'])->pluck('path'))->toContain("tenants/{$this->tenant->id}/candidate-documents/cv.pdf")
        ->and(json_encode($archive))->not->toContain('Theirs');

    foreach ($archive['tables'] as $table => $rows) {
        foreach ($rows as $row) {
            expect(array_keys($row))->each->not->toMatch(ComplianceExportService::EXCLUDED_COLUMNS);
        }
    }

    expect(TenantContext::current()->run($this->tenant, fn () => AuditLog::query()->whereIn('action', ['compliance_export_requested', 'compliance_export_ready'])->count()))->toBe(2);
});

test('only a compliance operator requests or downloads an export, each download audited', function (): void {
    expect(fn () => $this->exports->request($this->tenant, 'x', $this->world->administrator))->toThrow(DomainException::class, 'platform.compliance.manage')
        ->and(fn () => $this->exports->request($this->tenant, ' ', $this->world->compliance))->toThrow(DomainException::class, 'reason');

    $export = $this->exports->request($this->tenant, 'Exit request', $this->world->compliance);

    expect(fn () => $this->exports->download($export, $this->world->support))->toThrow(DomainException::class, 'platform.compliance.manage');

    Filament::setCurrentPanel('platform');
    $this->actingAs($this->world->compliance);
    Livewire::test(ComplianceExports::class)->assertCanSeeTableRecords([$export])->callTableAction('download', $export)->assertFileDownloaded(basename($export->fresh()->path));

    expect($export->fresh()->download_count)->toBe(1)
        ->and($export->fresh()->last_downloaded_by)->toBe($this->world->compliance->id)
        ->and(AuditLog::query()->where('action', 'compliance_export_downloaded')->sole()->user_id)->toBe($this->world->compliance->id);
});

test('an expired export\'s artifact is deleted and can no longer be downloaded; the register entry stays', function (): void {
    $export = $this->exports->request($this->tenant, 'Exit request', $this->world->compliance)->fresh();
    $this->travel(8)->days();

    expect(fn () => $this->exports->download($export, $this->world->compliance))->toThrow(DomainException::class, 'not available');

    $this->artisan('platform:sweep')->assertSuccessful();

    expect($export->fresh()->status)->toBe(ComplianceExportStatus::Expired)
        ->and($export->fresh()->expired_at)->not->toBeNull()
        ->and(Storage::disk('local')->exists($export->path))->toBeFalse()
        ->and(ComplianceExport::query()->count())->toBe(1);
});

test('a generated export is never rebuilt, and a register entry is never deleted', function (): void {
    $export = $this->exports->request($this->tenant, 'Exit request', $this->world->compliance)->fresh();

    expect($this->exports->generate($export->id))->toBe('nothing to do')
        ->and($export->fresh()->sha256)->toBe($export->sha256)
        ->and(fn () => $export->delete())->toThrow(LogicException::class);
});

test('every expired export is expired in one sweep, however many there are', function (): void {
    $rows = array_map(fn (int $i): array => ['tenant_id' => $this->tenant->id, 'status' => ComplianceExportStatus::Ready->value, 'reason' => 'Bulk', 'requested_by' => $this->world->compliance->id, 'requested_at' => now(), 'disk' => 'local', 'expires_at' => now()->subDay(), 'created_at' => now(), 'updated_at' => now()], range(1, 1005));

    foreach (array_chunk($rows, 250) as $chunk) {
        ComplianceExport::query()->insert($chunk);
    }

    expect($this->exports->expireDue())->toBe(1005)
        ->and(ComplianceExport::query()->where('status', ComplianceExportStatus::Ready->value)->count())->toBe(0);
});
