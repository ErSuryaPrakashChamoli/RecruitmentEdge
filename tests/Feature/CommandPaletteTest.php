<?php

use App\Filament\Resources\Candidates\CandidateResource;
use App\Livewire\CommandPalette;
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

test('the command palette is rendered on the dashboard', function (): void {
    $user = User::factory()->create();
    $user->assignRole('chro');

    actingAs($user)
        ->get('/admin')
        ->assertSuccessful()
        ->assertSeeLivewire(CommandPalette::class);
});

test('a chro sees every permission-gated command', function (): void {
    $user = User::factory()->create();
    $user->assignRole('chro');
    actingAs($user);

    $labels = collect(Livewire::test(CommandPalette::class)->get('commands'))->pluck('label');

    expect($labels)->toContain('Create Candidate', 'Create Requisition', 'Schedule Interview', 'Open Incentive Dashboard');
});

test('a recruiter does not see commands they are not permitted to run', function (): void {
    $user = User::factory()->create();
    $user->assignRole('recruiter');
    actingAs($user);

    $labels = collect(Livewire::test(CommandPalette::class)->get('commands'))->pluck('label');

    expect($labels)->not->toContain('Create Requisition');
});

test('searching finds a matching candidate, scoped by hierarchy', function (): void {
    $manager = Employee::factory()->create();
    $ownRecruiter = Employee::factory()->reportingTo($manager)->create();
    $outsider = Employee::factory()->create();

    $visibleCandidate = Candidate::factory()->create(['full_name' => 'Visible Palette Candidate']);
    CandidateApplication::factory()->create(['candidate_id' => $visibleCandidate->id, 'recruiter_id' => $ownRecruiter->id]);

    $hiddenCandidate = Candidate::factory()->create(['full_name' => 'Hidden Palette Candidate']);
    CandidateApplication::factory()->create(['candidate_id' => $hiddenCandidate->id, 'recruiter_id' => $outsider->id]);

    $user = User::factory()->create(['employee_id' => $manager->id]);
    $user->assignRole('manager');
    actingAs($user);

    $results = Livewire::test(CommandPalette::class)
        ->set('search', 'Palette Candidate')
        ->get('results');

    $titles = collect($results)->pluck('title');

    expect($titles)->toContain('Visible Palette Candidate')
        ->not->toContain('Hidden Palette Candidate');
});

test('the search URL points to the candidate view page', function (): void {
    $user = User::factory()->create();
    $user->assignRole('chro');
    actingAs($user);

    $candidate = Candidate::factory()->create(['full_name' => 'Findable Person']);
    CandidateApplication::factory()->create(['candidate_id' => $candidate->id]);

    $results = Livewire::test(CommandPalette::class)
        ->set('search', 'Findable Person')
        ->get('results');

    expect(collect($results)->first()['url'])->toBe(CandidateResource::getUrl('view', ['record' => $candidate]));
});
