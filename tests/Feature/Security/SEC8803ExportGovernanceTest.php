<?php

use App\Filament\Exports\CandidateExporter;
use App\Filament\Resources\Candidates\Pages\ListCandidates;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\CandidateSource;
use App\Models\Employee;
use App\Models\Export;
use App\Models\User;
use App\Services\Identity\StaffAccessService;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * SEC-88-03 (D8.8-EXPORT-001, owner decision 2026-10-01, A): every table export is capped at
 * 10,000 rows and audited (started or refused); its file can be downloaded only by its owner,
 * within 24 hours of completion, behind the staff-access and MFA checks, and every download is
 * audited. Stored-file expiry is not decided (deferred with retention) and is not tested here.
 */
beforeEach(function (): void {
    Storage::fake('local');
    Bus::fake();
    $this->seed(RolePermissionSeeder::class);
    $this->chroEmployee = Employee::factory()->create();
    $this->chro = User::factory()->create(['employee_id' => $this->chroEmployee->id])->assignRole('chro');
});

function sec8803CompletedExport(User $owner, mixed $completedAt = null): Export
{
    $export = new Export;
    $export->user()->associate($owner);
    $export->exporter = CandidateExporter::class;
    $export->total_rows = 1;
    $export->file_disk = 'local';
    $export->file_name = 'export-candidates';
    $export->completed_at = $completedAt ?? now();
    $export->save();

    Storage::disk('local')->put($export->getFileDirectory().'/headers.csv', "Full name\n");
    Storage::disk('local')->put($export->getFileDirectory().'/0000000000000001.csv', "Asha Verma\n");

    return $export;
}

function sec8803DownloadUrl(Export $export): string
{
    return route('filament.exports.download', ['export' => $export, 'format' => 'csv'], absolute: false);
}

test('starting an export records who asked, which export, the columns, the search and the row count', function (): void {
    Candidate::factory()->count(2)->create();
    Candidate::factory()->create(['full_name' => 'Zyxquorine Verma']);
    $this->actingAs($this->chro, 'web');

    Livewire::test(ListCandidates::class)
        ->searchTable('Zyxquorine')
        ->callAction(TestAction::make('export')->table());

    $export = Export::query()->sole();
    $row = AuditLog::query()->where('action', 'export_requested')->sole();

    expect($row->auditable_type)->toBe($export->getMorphClass())
        ->and($row->auditable_id)->toBe($export->getKey())
        ->and($row->user_id)->toBe($this->chro->id)
        ->and($row->changes['exporter'])->toBe('CandidateExporter')
        ->and($row->changes['rows'])->toBe(1)
        ->and($row->changes['search'])->toBe('Zyxquorine')
        ->and($row->changes['columns'])->toContain('full_name');
});

test('an export of more than 10,000 rows is refused, nothing is queued, and the refusal is audited', function (): void {
    $source = CandidateSource::factory()->create();
    $now = now();

    foreach (array_chunk(range(1, 10_001), 1_000) as $chunk) {
        DB::table('candidates')->insert(array_map(fn (int $i): array => [
            'tenant_id' => $this->tenant->id,
            'candidate_code' => 'CAP-'.$i,
            'full_name' => 'Bulk Candidate '.$i,
            'mobile' => '90000'.str_pad((string) $i, 5, '0', STR_PAD_LEFT),
            'source_id' => $source->id,
            'created_at' => $now,
            'updated_at' => $now,
        ], $chunk));
    }

    $this->actingAs($this->chro, 'web');

    Livewire::test(ListCandidates::class)->callAction(TestAction::make('export')->table());

    $row = AuditLog::query()->where('action', 'export_refused')->sole();

    expect(Export::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'export_requested')->count())->toBe(0)
        ->and($row->auditable_id)->toBe($this->chro->id)
        ->and($row->changes['reason'])->toBe('row_cap')
        ->and($row->changes['max_rows'])->toBe(10_000)
        ->and($row->changes['exporter'])->toBe('CandidateExporter');
    Bus::assertNothingDispatched();
});

test('only the owner can download an export file, only within 24 hours, and each download is audited', function (): void {
    $export = sec8803CompletedExport($this->chro);
    $someoneElse = User::factory()->create(['employee_id' => Employee::factory()->reportingTo($this->chroEmployee)->create()->id])->assignRole('recruiter');

    $download = $this->actingAs($this->chro, 'web')->get(sec8803DownloadUrl($export));
    $download->assertOk();

    expect($download->streamedContent())->toContain('Asha Verma');

    $this->actingAs($someoneElse, 'web')->get(sec8803DownloadUrl($export))->assertForbidden();

    $this->travel(23)->hours();
    $this->actingAs($this->chro, 'web')->get(sec8803DownloadUrl($export))->assertOk();

    $this->travel(2)->hours();
    $this->actingAs($this->chro, 'web')->get(sec8803DownloadUrl($export))->assertForbidden();

    $downloads = AuditLog::query()->where('action', 'export_downloaded')->get();

    expect($downloads)->toHaveCount(2)
        ->and($downloads->pluck('user_id')->unique()->all())->toBe([$this->chro->id])
        ->and($downloads->first()->auditable_id)->toBe($export->getKey())
        ->and($downloads->first()->changes['format'])->toBe('csv');
});

test('a suspended owner cannot download their export', function (): void {
    $vpEmployee = Employee::factory()->reportingTo($this->chroEmployee)->create();
    $vp = User::factory()->create(['employee_id' => $vpEmployee->id])->assignRole('vp_hr');
    $recruiter = User::factory()->create(['employee_id' => Employee::factory()->reportingTo($vpEmployee)->create()->id])->assignRole('recruiter');
    $export = sec8803CompletedExport($recruiter);

    app(StaffAccessService::class)->suspend($recruiter, $vp, 'Investigation');

    $response = $this->actingAs($recruiter->fresh(), 'web')->get(sec8803DownloadUrl($export));

    // SaaS-1: a suspended login cannot act in any tenant, so the download's tenant check answers
    // first, with a 404 that does not reveal the export.
    expect($response->status())->toBeIn([401, 403, 404])
        ->and(AuditLog::query()->where('action', 'export_downloaded')->count())->toBe(0);
});

test('when MFA is required, an owner who has not enrolled is sent to enrolment instead of the file', function (): void {
    config(['identity.mfa.enforce' => true]);
    $manager = User::factory()->create(['employee_id' => Employee::factory()->reportingTo($this->chroEmployee)->create()->id])->assignRole('manager');
    $export = sec8803CompletedExport($manager);

    $this->actingAs($manager, 'web')->get(sec8803DownloadUrl($export))
        ->assertRedirect(Filament::getPanel('admin')->getSetUpRequiredMultiFactorAuthenticationUrl());

    expect(AuditLog::query()->where('action', 'export_downloaded')->count())->toBe(0);
});
