<?php

use App\Enums\JoiningStatus;
use App\Filament\Widgets\JoiningControlCenterWidget;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

test('the joining tracker page renders the control center with real data', function (): void {
    $recruiter = Employee::factory()->create();
    $application = CandidateApplication::factory()->create(['recruiter_id' => $recruiter->id]);
    CandidateJoining::factory()->create([
        'candidate_application_id' => $application->id,
        'status' => JoiningStatus::Expected,
        'expected_doj' => now()->addDay(),
    ]);

    $user = User::factory()->create(['employee_id' => $recruiter->id]);
    $user->assignRole('chro');

    actingAs($user)
        ->get('/admin/candidate-joinings')
        ->assertSuccessful()
        ->assertSee('Joining Pipeline')
        ->assertSee('Joining Risk')
        ->assertSee('Joining Tomorrow');
});

test('the risk groups reflect real riskLevel() output, not a fabricated score', function (): void {
    $recruiter = Employee::factory()->create();

    $overdue = CandidateJoining::factory()->create([
        'candidate_application_id' => CandidateApplication::factory()->create(['recruiter_id' => $recruiter->id])->id,
        'status' => JoiningStatus::Expected,
        'expected_doj' => now()->subDay(),
    ]);

    $onTrack = CandidateJoining::factory()->create([
        'candidate_application_id' => CandidateApplication::factory()->create(['recruiter_id' => $recruiter->id])->id,
        'status' => JoiningStatus::Confirmed,
        'expected_doj' => now()->addDays(20),
    ]);

    $user = User::factory()->create(['employee_id' => $recruiter->id]);
    $user->assignRole('chro');
    actingAs($user);

    $groups = Livewire::test(JoiningControlCenterWidget::class)->instance()->getRiskGroups();

    expect($groups['red']->pluck('id'))->toContain($overdue->id)
        ->and($groups['green']->pluck('id'))->toContain($onTrack->id)
        ->and($overdue->riskLevel())->toBe('red')
        ->and($onTrack->riskLevel())->toBe('green');
});

test('joining tomorrow only shows joinings visible to the hierarchy', function (): void {
    $manager = Employee::factory()->create();
    $ownRecruiter = Employee::factory()->reportingTo($manager)->create();
    $outsider = Employee::factory()->create();

    $visible = CandidateJoining::factory()->create([
        'candidate_application_id' => CandidateApplication::factory()->create(['recruiter_id' => $ownRecruiter->id])->id,
        'status' => JoiningStatus::Expected,
        'expected_doj' => now()->addDay(),
    ]);
    $visible->candidateApplication->candidate->update(['full_name' => 'Visible Tomorrow Joiner']);

    $hidden = CandidateJoining::factory()->create([
        'candidate_application_id' => CandidateApplication::factory()->create(['recruiter_id' => $outsider->id])->id,
        'status' => JoiningStatus::Expected,
        'expected_doj' => now()->addDay(),
    ]);
    $hidden->candidateApplication->candidate->update(['full_name' => 'Hidden Tomorrow Joiner']);

    $user = User::factory()->create(['employee_id' => $manager->id]);
    $user->assignRole('manager');

    actingAs($user)
        ->get('/admin/candidate-joinings')
        ->assertSuccessful()
        ->assertSee('Visible Tomorrow Joiner')
        ->assertDontSee('Hidden Tomorrow Joiner');
});
