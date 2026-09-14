<?php

use App\Enums\OfferStatus;
use App\Filament\Resources\Candidates\CandidateResource;
use App\Livewire\CommandPalette;
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\Offer;
use App\Models\RecruitmentRequisition;
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

test('searching by a related candidate name finds applications through their relation', function (): void {
    $user = User::factory()->create();
    $user->assignRole('chro');
    actingAs($user);

    $candidate = Candidate::factory()->create(['full_name' => 'Relation Search Person']);
    CandidateApplication::factory()->create(['candidate_id' => $candidate->id]);

    $groups = collect(Livewire::test(CommandPalette::class)->set('search', 'Relation Search')->get('results'))->pluck('group');

    expect($groups)->toContain('Candidates', 'Applications');
});

test('searching finds requisitions and offers, scoped by hierarchy', function (): void {
    $manager = Employee::factory()->create();
    $ownRecruiter = Employee::factory()->reportingTo($manager)->create();
    $outsider = Employee::factory()->create();

    RecruitmentRequisition::factory()->create(['code' => 'REQ-ZQX-VISIBLE', 'manager_id' => $manager->id]);
    RecruitmentRequisition::factory()->create(['code' => 'REQ-ZQX-HIDDEN']);

    Offer::factory()->create([
        'offer_code' => 'OFR-ZQX-VISIBLE',
        'status' => OfferStatus::Draft,
        'candidate_application_id' => CandidateApplication::factory()->create(['recruiter_id' => $ownRecruiter->id])->id,
    ]);
    Offer::factory()->create([
        'offer_code' => 'OFR-ZQX-HIDDEN',
        'status' => OfferStatus::Draft,
        'candidate_application_id' => CandidateApplication::factory()->create(['recruiter_id' => $outsider->id])->id,
    ]);

    $user = User::factory()->create(['employee_id' => $manager->id]);
    $user->assignRole('manager');
    actingAs($user);

    $titles = collect(Livewire::test(CommandPalette::class)->set('search', 'ZQX')->get('results'))->pluck('title')->implode(' | ');

    expect($titles)->toContain('REQ-ZQX-VISIBLE', 'OFR-ZQX-VISIBLE')
        ->not->toContain('REQ-ZQX-HIDDEN')
        ->not->toContain('OFR-ZQX-HIDDEN');
});

test('jump-to navigation lists only the resources and pages the user can access', function (): void {
    $chro = User::factory()->create();
    $chro->assignRole('chro');
    actingAs($chro);

    $chroLabels = collect(Livewire::test(CommandPalette::class)->get('navigation'))->pluck('label');

    expect($chroLabels)->toContain('Audit Logs', 'Recruitment Reports', 'Candidates', 'Dashboard');

    $recruiter = User::factory()->create();
    $recruiter->assignRole('recruiter');
    actingAs($recruiter);

    $recruiterLabels = collect(Livewire::test(CommandPalette::class)->get('navigation'))->pluck('label');

    expect($recruiterLabels)->toContain('Candidates')
        ->not->toContain('Audit Logs');
});

test('jump-to navigation is filtered by the search term', function (): void {
    $user = User::factory()->create();
    $user->assignRole('chro');
    actingAs($user);

    $labels = collect(Livewire::test(CommandPalette::class)->set('search', 'audit')->get('navigation'))->pluck('label');

    expect($labels)->toContain('Audit Logs')
        ->not->toContain('Candidates')
        ->not->toContain('Dashboard');
});
