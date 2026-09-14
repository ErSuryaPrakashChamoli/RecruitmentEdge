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
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

test('document types cover the documents collected from a candidate', function (): void {
    expect(array_map(fn (DocumentType $type) => $type->value, DocumentType::cases()))
        ->toContain('resume', 'id_proof', 'education_certificate', 'experience_letter', 'relieving_letter', 'salary_slip', 'bank_details');
});

test('a document can be attached to a candidate before any joining exists', function (): void {
    $candidate = Candidate::factory()->create();

    $document = CandidateDocument::factory()->forCandidate($candidate)->create(['document_type' => DocumentType::Resume]);

    expect($document->candidate_joining_id)->toBeNull()
        ->and($candidate->documents()->pluck('id')->all())->toBe([$document->id]);
});

test('a document created from a joining gets the candidate filled in', function (): void {
    $joining = CandidateJoining::factory()->create();

    $document = CandidateDocument::factory()->create(['candidate_joining_id' => $joining->id]);

    expect($document->candidate_id)->toBe($joining->candidateApplication->candidate_id);
});

test('a recruiter can add a candidate-level document with status and remarks from the candidate page', function (): void {
    $recruiter = Employee::factory()->create();
    $candidate = Candidate::factory()->create();
    CandidateApplication::factory()->create(['candidate_id' => $candidate->id, 'recruiter_id' => $recruiter->id]);

    $user = User::factory()->create(['employee_id' => $recruiter->id]);
    $user->assignRole('recruiter');
    actingAs($user);

    Livewire::test(DocumentsRelationManager::class, ['ownerRecord' => $candidate, 'pageClass' => EditCandidate::class])
        ->callAction(TestAction::make('create')->table(), data: [
            'document_type' => DocumentType::Resume->value,
            'status' => DocumentStatus::Verified->value,
            'remarks' => 'Latest CV',
        ])
        ->assertHasNoFormErrors();

    $document = $candidate->documents()->sole();

    expect($document->candidate_joining_id)->toBeNull()
        ->and($document->status)->toBe(DocumentStatus::Verified)
        ->and($document->remarks)->toBe('Latest CV')
        ->and($document->verified_by)->toBe($recruiter->id);
});

test('the joining documents form saves status and remarks', function (): void {
    $user = User::factory()->create();
    $user->assignRole('chro');
    actingAs($user);

    $joining = CandidateJoining::factory()->create();

    Livewire::test(JoiningDocumentsRelationManager::class, ['ownerRecord' => $joining, 'pageClass' => EditCandidateJoining::class])
        ->callAction(TestAction::make('create')->table(), data: [
            'document_type' => DocumentType::IdProof->value,
            'status' => DocumentStatus::Rejected->value,
            'remarks' => 'Blurry scan',
        ])
        ->assertHasNoFormErrors();

    $document = $joining->documents()->sole();

    expect($document->status)->toBe(DocumentStatus::Rejected)
        ->and($document->remarks)->toBe('Blurry scan')
        ->and($document->candidate_id)->toBe($joining->candidateApplication->candidate_id);
});

test('candidate-level document access follows the candidate hierarchy', function (): void {
    $manager = Employee::factory()->create();
    $ownRecruiter = Employee::factory()->reportingTo($manager)->create();
    $outsider = Employee::factory()->create();

    $visibleCandidate = Candidate::factory()->create();
    CandidateApplication::factory()->create(['candidate_id' => $visibleCandidate->id, 'recruiter_id' => $ownRecruiter->id]);
    $hiddenCandidate = Candidate::factory()->create();
    CandidateApplication::factory()->create(['candidate_id' => $hiddenCandidate->id, 'recruiter_id' => $outsider->id]);

    $visibleDocument = CandidateDocument::factory()->forCandidate($visibleCandidate)->create();
    $hiddenDocument = CandidateDocument::factory()->forCandidate($hiddenCandidate)->create();

    $user = User::factory()->create(['employee_id' => $manager->id]);
    $user->assignRole('manager');

    expect($user->can('view', $visibleDocument))->toBeTrue()
        ->and($user->can('update', $visibleDocument))->toBeTrue()
        ->and($user->can('delete', $visibleDocument))->toBeTrue()
        ->and($user->can('view', $hiddenDocument))->toBeFalse()
        ->and($user->can('update', $hiddenDocument))->toBeFalse();
});

test('joining documents keep their joining-based policy and cannot be deleted', function (): void {
    $recruiter = Employee::factory()->create();
    $joining = CandidateJoining::factory()->create([
        'candidate_application_id' => CandidateApplication::factory()->create(['recruiter_id' => $recruiter->id])->id,
    ]);
    $document = CandidateDocument::factory()->create(['candidate_joining_id' => $joining->id]);

    $user = User::factory()->create(['employee_id' => $recruiter->id]);
    $user->assignRole('recruiter');

    expect($user->can('update', $document))->toBeTrue()
        ->and($user->can('delete', $document))->toBeFalse();
});

test('the candidate edit page renders with the documents tab', function (): void {
    $user = User::factory()->create();
    $user->assignRole('chro');

    $candidate = Candidate::factory()->create();
    CandidateDocument::factory()->forCandidate($candidate)->create(['document_type' => DocumentType::Resume]);

    actingAs($user)
        ->get("/admin/candidates/{$candidate->id}/edit")
        ->assertSuccessful()
        ->assertSee('Documents');
});
