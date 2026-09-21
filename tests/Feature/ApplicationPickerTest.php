<?php

use App\Enums\ApplicationStatus;
use App\Filament\Resources\Interviews\Pages\CreateInterview;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Forms\Components\Select;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);

    $this->recruiter = Employee::factory()->create();
    $this->application = CandidateApplication::factory()->create(['recruiter_id' => $this->recruiter->id]);
    $this->application->candidate->update(['full_name' => 'Pickable Interview Candidate', 'mobile' => '9000011111']);

    $user = User::factory()->create(['employee_id' => $this->recruiter->id]);
    $user->assignRole('recruiter');
    actingAs($user);
});

function createInterviewApplicationPicker(): Select
{
    return Livewire::test(CreateInterview::class)->instance()->form->getFlatFields()['candidate_application_id'];
}

test('the interview application picker shows the candidate name and mobile and searches by either', function (): void {
    $byMobile = createInterviewApplicationPicker()->getSearchResults('9000011111');

    expect($byMobile)->toHaveCount(1)
        ->and($byMobile[$this->application->id])
        ->toContain('Pickable Interview Candidate')
        ->toContain('9000011111')
        ->toContain($this->application->application_code)
        ->and(createInterviewApplicationPicker()->getSearchResults('Pickable Interview'))->toHaveKey($this->application->id);
});

test('the interview application picker only offers active applications within the hierarchy', function (): void {
    CandidateApplication::factory()->create(['recruiter_id' => $this->recruiter->id, 'status' => ApplicationStatus::Rejected])
        ->candidate->update(['full_name' => 'Pickable Rejected Candidate']);
    CandidateApplication::factory()->create(['recruiter_id' => Employee::factory()->create()->id])
        ->candidate->update(['full_name' => 'Pickable Outsider Candidate']);

    expect(array_keys(createInterviewApplicationPicker()->getSearchResults('Pickable')))->toBe([$this->application->id]);
});

test('an out-of-scope application cannot be submitted on the interview form', function (): void {
    $outsiderApplication = CandidateApplication::factory()->create(['recruiter_id' => Employee::factory()->create()->id]);

    Livewire::test(CreateInterview::class)
        ->fillForm([
            'candidate_application_id' => $outsiderApplication->id,
            'interviewer_id' => $this->recruiter->id,
            'scheduled_at' => now()->addDay(),
            'mode' => 'video_call',
        ])
        ->call('create')
        ->assertHasFormErrors(['candidate_application_id']);

    expect(Interview::query()->count())->toBe(0);
});
