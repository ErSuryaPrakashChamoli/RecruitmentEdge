<?php

use App\Enums\ActivityType;
use App\Enums\CandidateStage;
use App\Enums\Priority;
use App\Enums\RequisitionStatus;
use App\Filament\Resources\CandidateApplications\Pages\CreateCandidateApplication;
use App\Filament\Resources\Candidates\Pages\EditCandidate;
use App\Filament\Resources\Candidates\Pages\ListCandidates;
use App\Filament\Resources\Candidates\RelationManagers\ApplicationsRelationManager;
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\RecruitmentDailyActivity;
use App\Models\RecruitmentRequisition;
use App\Models\TalentPool;
use App\Models\TalentPoolMembership;
use App\Models\User;
use App\Services\Intelligence\TalentRediscoveryService;
use App\Services\RecruitmentActivityService;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Support\Exceptions\Halt;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/*
 * Phase 8.10 (P810-SEC-004): a new application brings its candidate into the recruiter's team's
 * scope, so every path that picks or attaches a candidate is bounded on the server by the user's
 * own candidate scope (Candidate::visibleTo) and team (HierarchyService) — not only in the UI.
 * Team A is the user's; team B belongs to another manager.
 */
beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);

    $this->managerA = Employee::factory()->create();
    $this->recruiterA = Employee::factory()->create(['reports_to_id' => $this->managerA->id]);
    $this->recruiterB = Employee::factory()->create(['reports_to_id' => Employee::factory()->create()->id]);
    $this->user = User::factory()->create(['employee_id' => $this->recruiterA->id])->assignRole('recruiter');

    $this->ownCandidate = Candidate::factory()->create(['full_name' => 'Own Candidate', 'mobile' => '9000000001', 'skills' => ['PHP', 'Laravel', 'SQL'], 'total_experience' => 4]);
    CandidateApplication::factory()->create(['candidate_id' => $this->ownCandidate->id, 'recruiter_id' => $this->recruiterA->id, 'current_stage' => CandidateStage::Interview1]);
    $this->otherCandidate = Candidate::factory()->create(['full_name' => 'Other Candidate', 'mobile' => '9000000002', 'skills' => ['PHP', 'Laravel', 'SQL'], 'total_experience' => 4]);
    CandidateApplication::factory()->create(['candidate_id' => $this->otherCandidate->id, 'recruiter_id' => $this->recruiterB->id, 'current_stage' => CandidateStage::Interview1]);

    $this->requisition = RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Open, 'manager_id' => $this->managerA->id, 'skills' => ['PHP', 'Laravel', 'SQL'], 'experience_min' => 2, 'experience_max' => 6, 'qualification' => null, 'location_id' => null, 'salary_min' => null, 'salary_max' => null]);
    $this->requisition->recruiters()->attach($this->recruiterA->id);
});

/**
 * @return array<string, mixed>
 */
function newApplicationData(Candidate $candidate, Employee $recruiter, RecruitmentRequisition $requisition): array
{
    return [
        'application_date' => now()->toDateString(),
        'candidate_id' => $candidate->id,
        'requisition_id' => $requisition->id,
        'recruiter_id' => $recruiter->id,
        'priority' => Priority::Medium->value,
    ];
}

test('the candidate picker lists and finds only candidates the user can see', function (): void {
    actingAs($this->user);

    $picker = Livewire::test(CreateCandidateApplication::class)->instance()->form->getFlatFields()['candidate_id'];

    expect($picker->getOptions())->toContain('Own Candidate · 9000000001')->not->toContain('Other Candidate · 9000000002')
        ->and($picker->getSearchResults('Candidate'))->toBe([$this->ownCandidate->id => 'Own Candidate · 9000000001'])
        ->and($picker->getSearchResults('9000000002'))->toBe([]);
});

test('a recruiter creates an application for their own candidate with themselves as recruiter', function (): void {
    actingAs($this->user);

    Livewire::test(CreateCandidateApplication::class)
        ->fillForm(newApplicationData($this->ownCandidate, $this->recruiterA, $this->requisition))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(CandidateApplication::query()->where('requisition_id', $this->requisition->id)->sole()->only(['candidate_id', 'recruiter_id']))
        ->toBe(['candidate_id' => $this->ownCandidate->id, 'recruiter_id' => $this->recruiterA->id]);
});

test('a submitted candidate from another team is refused and nothing is written', function (): void {
    actingAs($this->user);

    Livewire::test(CreateCandidateApplication::class)
        ->fillForm(newApplicationData($this->otherCandidate, $this->recruiterA, $this->requisition))
        ->call('create')
        ->assertHasFormErrors(['candidate_id']);

    expect(CandidateApplication::query()->where('requisition_id', $this->requisition->id)->exists())->toBeFalse()
        ->and(Candidate::query()->visibleTo($this->user)->whereKey($this->otherCandidate->id)->exists())->toBeFalse();
});

test('a submitted recruiter outside the user\'s team is refused', function (): void {
    actingAs($this->user);

    Livewire::test(CreateCandidateApplication::class)
        ->fillForm(newApplicationData($this->ownCandidate, $this->recruiterB, $this->requisition))
        ->call('create')
        ->assertHasFormErrors(['recruiter_id']);

    expect(CandidateApplication::query()->where('requisition_id', $this->requisition->id)->exists())->toBeFalse();
});

test('the server-side create guard refuses another team\'s candidate or recruiter even past the form', function (Closure $candidate, Closure $recruiter): void {
    actingAs($this->user);

    expect(fn () => CreateCandidateApplication::ensureCandidateAndRecruiterInScope($candidate()->id, $recruiter()->id))->toThrow(Halt::class);
})->with([
    'another team\'s candidate' => [fn () => test()->otherCandidate, fn () => test()->recruiterA],
    'another team\'s recruiter' => [fn () => test()->ownCandidate, fn () => test()->recruiterB],
]);

test('a manager reaches their own team\'s candidates but not another manager\'s', function (): void {
    $manager = User::factory()->create(['employee_id' => $this->managerA->id])->assignRole('manager');
    actingAs($manager);

    Livewire::test(CreateCandidateApplication::class)
        ->fillForm(newApplicationData($this->otherCandidate, $this->recruiterA, $this->requisition))
        ->call('create')
        ->assertHasFormErrors(['candidate_id']);

    Livewire::test(CreateCandidateApplication::class)
        ->fillForm(newApplicationData($this->ownCandidate, $this->recruiterA, $this->requisition))
        ->call('create')
        ->assertHasNoFormErrors();

    expect(CandidateApplication::query()->where('requisition_id', $this->requisition->id)->pluck('candidate_id')->all())->toBe([$this->ownCandidate->id]);
});

test('the candidate page\'s new-application action refuses a recruiter outside the user\'s team', function (): void {
    actingAs($this->user);
    $data = ['requisition_id' => $this->requisition->id, 'recruiter_id' => $this->recruiterB->id, 'priority' => Priority::Medium->value];

    Livewire::test(ApplicationsRelationManager::class, ['ownerRecord' => $this->ownCandidate, 'pageClass' => EditCandidate::class])
        ->callAction(TestAction::make('create')->table(), data: $data)
        ->assertHasFormErrors(['recruiter_id']);

    expect(CandidateApplication::query()->where('requisition_id', $this->requisition->id)->exists())->toBeFalse();

    Livewire::test(ApplicationsRelationManager::class, ['ownerRecord' => $this->ownCandidate, 'pageClass' => EditCandidate::class])
        ->callAction(TestAction::make('create')->table(), data: [...$data, 'recruiter_id' => $this->recruiterA->id])
        ->assertHasNoFormErrors();

    expect(CandidateApplication::query()->where('requisition_id', $this->requisition->id)->sole()->recruiter_id)->toBe($this->recruiterA->id);
});

test('a rediscovery suggestion from a wider-scoped colleague\'s run cannot be added by someone who cannot see the candidate', function (): void {
    $service = app(TalentRediscoveryService::class);
    $viewAll = User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole('chro');
    $result = $service->run($this->requisition, $viewAll)->results->firstWhere('candidate_id', $this->otherCandidate->id);
    $pool = TalentPool::factory()->create(['owner_id' => $this->recruiterA->id]);

    expect(fn () => $service->addToRequisition($result, $this->user))->toThrow(DomainException::class, 'outside your team')
        ->and(fn () => $service->addToPool($result, $pool, $this->user))->toThrow(DomainException::class, 'outside your team')
        ->and(CandidateApplication::query()->where('requisition_id', $this->requisition->id)->exists())->toBeFalse()
        ->and(TalentPoolMembership::query()->where('candidate_id', $this->otherCandidate->id)->exists())->toBeFalse();

    expect($service->addToRequisition($result->fresh(), $viewAll)->candidate_id)->toBe($this->otherCandidate->id);
});

test('a logged activity names only a candidate the user can see', function (): void {
    $service = app(RecruitmentActivityService::class);
    $data = ['recruiter_id' => $this->recruiterA->id, 'activity_type' => ActivityType::Call->value, 'activity_datetime' => now()->subMinute()];

    expect(fn () => $service->log($this->user, [...$data, 'candidate_id' => $this->otherCandidate->id]))->toThrow(DomainException::class, 'candidate you can see')
        ->and(RecruitmentDailyActivity::query()->exists())->toBeFalse()
        ->and($service->log($this->user, [...$data, 'candidate_id' => $this->ownCandidate->id])->candidate_id)->toBe($this->ownCandidate->id);
});

test('the bulk add-to-pool action acts only on candidates the user can see', function (): void {
    actingAs($this->user);
    $pool = TalentPool::factory()->create(['owner_id' => $this->recruiterA->id]);

    Livewire::test(ListCandidates::class)
        ->selectTableRecords([$this->ownCandidate->id, $this->otherCandidate->id])
        ->callAction(TestAction::make('addToTalentPool')->table()->bulk(), ['talent_pool_id' => $pool->id]);

    expect($pool->candidates()->pluck('candidates.id')->all())->toBe([$this->ownCandidate->id]);
});
