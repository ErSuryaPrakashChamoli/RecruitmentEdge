<?php

use App\Enums\DocumentStatus;
use App\Enums\DocumentType;
use App\Filament\Resources\CandidateJoinings\Pages\EditCandidateJoining;
use App\Filament\Resources\CandidateJoinings\RelationManagers\DocumentsRelationManager as JoiningDocumentsRelationManager;
use App\Filament\Resources\Candidates\Pages\EditCandidate;
use App\Filament\Resources\Candidates\RelationManagers\DocumentsRelationManager;
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\CandidateDocument;
use App\Models\CandidateJoining;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * SEC-88-06 (owner decision 2026-10-01, A): staff uploads accept only the approved types and
 * sizes, and a submitted path can never point a record at another stored file. Malware scanning
 * (D8.8-029) is deferred.
 */
beforeEach(function (): void {
    Storage::fake('local');
    $this->seed(RolePermissionSeeder::class);
    $this->recruiter = Employee::factory()->create();
    $this->candidate = Candidate::factory()->create();
    $this->application = CandidateApplication::factory()->create(['candidate_id' => $this->candidate->id, 'recruiter_id' => $this->recruiter->id]);
    $user = User::factory()->create(['employee_id' => $this->recruiter->id])->assignRole('recruiter');
    $this->actingAs($user, 'web');
});

function sec8806AddDocument(Candidate $candidate, mixed $file): mixed
{
    return Livewire::test(DocumentsRelationManager::class, ['ownerRecord' => $candidate, 'pageClass' => EditCandidate::class])
        ->callAction(TestAction::make('create')->table(), data: [
            'document_type' => DocumentType::Resume->value,
            'status' => DocumentStatus::Pending->value,
            'file_path' => $file,
        ]);
}

test('a candidate document accepts PDF, Word and images up to 10 MB', function (): void {
    foreach ([['cv.pdf', 'application/pdf'], ['cv.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'], ['id.jpg', 'image/jpeg'], ['id.png', 'image/png']] as [$name, $mime]) {
        sec8806AddDocument($this->candidate, UploadedFile::fake()->create($name, 200, $mime))->assertHasNoFormErrors();
    }

    sec8806AddDocument($this->candidate, UploadedFile::fake()->create('scan.pdf', 10240, 'application/pdf'))->assertHasNoFormErrors();

    expect($this->candidate->documents()->count())->toBe(5);
});

test('a candidate document refuses other types and files over 10 MB', function (): void {
    sec8806AddDocument($this->candidate, UploadedFile::fake()->create('page.html', 5, 'text/html'))->assertHasFormErrors(['file_path']);
    sec8806AddDocument($this->candidate, UploadedFile::fake()->create('image.svg', 5, 'image/svg+xml'))->assertHasFormErrors(['file_path']);
    sec8806AddDocument($this->candidate, UploadedFile::fake()->create('tool.exe', 5, 'application/x-msdownload'))->assertHasFormErrors(['file_path']);
    sec8806AddDocument($this->candidate, UploadedFile::fake()->create('huge.pdf', 10241, 'application/pdf'))->assertHasFormErrors(['file_path']);

    expect($this->candidate->documents()->count())->toBe(0);
});

test('a document cannot be pointed at another stored file; its own file is kept when edited', function (): void {
    $other = Candidate::factory()->create();
    Storage::disk('local')->put('candidate-documents/other-candidate-passport.pdf', '%PDF other');
    CandidateDocument::factory()->create(['candidate_id' => $other->id, 'candidate_joining_id' => null, 'file_path' => 'candidate-documents/other-candidate-passport.pdf']);

    // The form holds an upload's state as [key => path]; a tampered request submits another file's path.
    sec8806AddDocument($this->candidate, ['tampered' => 'candidate-documents/other-candidate-passport.pdf'])->assertHasFormErrors(['file_path']);

    Storage::disk('local')->put('candidate-documents/own.pdf', '%PDF own');
    $own = CandidateDocument::factory()->create(['candidate_id' => $this->candidate->id, 'candidate_joining_id' => null, 'file_path' => 'candidate-documents/own.pdf', 'remarks' => null]);

    Livewire::test(DocumentsRelationManager::class, ['ownerRecord' => $this->candidate, 'pageClass' => EditCandidate::class])
        ->callAction(TestAction::make('edit')->table($own), data: ['remarks' => 'Checked'])
        ->assertHasNoFormErrors();

    expect($own->fresh()->remarks)->toBe('Checked')
        ->and($own->fresh()->file_path)->toBe('candidate-documents/own.pdf')
        ->and($this->candidate->documents()->count())->toBe(1);
});

test('a joining document has the same limits', function (): void {
    $joining = CandidateJoining::factory()->create(['candidate_application_id' => $this->application->id]);

    Livewire::test(JoiningDocumentsRelationManager::class, ['ownerRecord' => $joining, 'pageClass' => EditCandidateJoining::class])
        ->callAction(TestAction::make('create')->table(), data: [
            'document_type' => DocumentType::IdProof->value,
            'status' => DocumentStatus::Pending->value,
            'file_path' => UploadedFile::fake()->create('page.html', 5, 'text/html'),
        ])
        ->assertHasFormErrors(['file_path']);

    expect($joining->documents()->count())->toBe(0);
});

test('a candidate resume refuses files over 5 MB', function (): void {
    Livewire::test(EditCandidate::class, ['record' => $this->candidate->getKey()])
        ->fillForm(['resume_path' => UploadedFile::fake()->create('cv.pdf', 5121, 'application/pdf')])
        ->call('save')
        ->assertHasFormErrors(['resume_path']);

    expect($this->candidate->fresh()->resume_path)->toBeNull();
});
