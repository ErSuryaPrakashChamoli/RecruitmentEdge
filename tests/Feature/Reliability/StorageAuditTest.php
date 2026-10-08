<?php

use App\Filament\Exports\CandidateExporter;
use App\Models\Candidate;
use App\Models\CandidateDocument;
use App\Models\Employee;
use App\Models\Export;
use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

/**
 * Phase 8.9 (D8.9-017, storage scope): the storage audit reports growth per area, files no record
 * references and records whose file is gone — and never deletes anything (removing orphans is a
 * retention decision, SEC-88-02).
 *
 * @return array{areas: array<int, array<string, mixed>>, references: array<int, array<string, mixed>>, deleted: int}
 */
function storageAuditReport(): array
{
    Artisan::call('storage:audit', ['--json' => true]);

    return json_decode(Artisan::output(), true);
}

test('it reports referenced, unreferenced and transient files per area, and records whose file is missing', function (): void {
    Storage::fake('local');
    Storage::fake('public');

    $candidate = Candidate::factory()->create(['resume_path' => 'resumes/kept.pdf']);
    Storage::disk('local')->put('resumes/kept.pdf', str_repeat('a', 100));
    Storage::disk('local')->put('resumes/left-behind.pdf', str_repeat('b', 40));
    Storage::disk('local')->put('livewire-tmp/upload.tmp', 'x');
    CandidateDocument::factory()->forCandidate($candidate)->create(['file_path' => 'candidate-documents/gone.pdf']);
    $candidate->delete();

    Employee::factory()->create(['photo_path' => 'employee-photos/me.jpg']);
    Storage::disk('public')->put('employee-photos/me.jpg', 'jpg');

    $report = storageAuditReport();
    $areas = collect($report['areas'])->keyBy(fn (array $area): string => "{$area['disk']}:{$area['area']}");
    $references = collect($report['references'])->keyBy('reference');

    // A soft-deleted candidate still owns its resume; only the file nobody references is reported.
    expect($areas['local:resumes'])->toMatchArray(['files' => 2, 'bytes' => 140, 'unreferenced_files' => 1, 'unreferenced_bytes' => 40])
        ->and($areas['local:livewire-tmp'])->toMatchArray(['files' => 1, 'transient' => true, 'unreferenced_files' => 0])
        ->and($areas['public:employee-photos'])->toMatchArray(['files' => 1, 'unreferenced_files' => 0])
        ->and($references['candidate_documents.file_path'])->toMatchArray(['records' => 1, 'missing' => 1])
        ->and($references['candidates.resume_path'])->toMatchArray(['records' => 1, 'missing' => 0])
        ->and($report['deleted'])->toBe(0);

    expect(Storage::disk('local')->exists('resumes/left-behind.pdf'))->toBeTrue()
        ->and(Storage::disk('local')->exists('livewire-tmp/upload.tmp'))->toBeTrue();
});

test('an export file belongs to its export record', function (): void {
    Storage::fake('local');
    Storage::fake('public');
    $user = User::factory()->create();
    $export = Export::query()->forceCreate([
        'user_id' => $user->id, 'exporter' => CandidateExporter::class,
        'file_disk' => 'local', 'total_rows' => 1, 'processed_rows' => 1, 'successful_rows' => 1,
    ]);
    // SaaS-1: the export's files are under its tenant (Export::getFileDirectory()).
    Storage::disk('local')->put($export->getFileDirectory().'/0000000000000001.csv', 'a,b');
    Storage::disk('local')->put('filament_exports/999999/0000000000000001.csv', 'a,b');

    $area = collect(storageAuditReport()['areas'])->firstWhere('area', 'filament_exports');

    expect($area)->toMatchArray(['files' => 2, 'unreferenced_files' => 1]);
});
