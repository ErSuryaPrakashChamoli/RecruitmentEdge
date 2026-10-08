<?php

use App\Enums\ApplicationStatus;
use App\Enums\CandidateStage;
use App\Enums\IncentiveTriggerEvent;
use App\Enums\OfferStatus;
use App\Enums\ReferralIncentiveStatus;
use App\Enums\ReferralRelationship;
use App\Enums\ReferralStatus;
use App\Enums\RequisitionStatus;
use App\Events\ReferralSubmitted;
use App\Filament\Resources\EmployeeReferrals\EmployeeReferralResource;
use App\Filament\Resources\EmployeeReferrals\Pages\CreateEmployeeReferral;
use App\Filament\Resources\EmployeeReferrals\Pages\ListEmployeeReferrals;
use App\Filament\Resources\EmployeeReferrals\Pages\ViewEmployeeReferral;
use App\Filament\Resources\EmployeeReferrals\Widgets\ReferralStats;
use App\Models\AuditLog;
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\CandidateSource;
use App\Models\Employee;
use App\Models\EmployeeReferral;
use App\Models\Offer;
use App\Models\RecruiterIncentiveCalculation;
use App\Models\RecruitmentIncentiveRule;
use App\Models\RecruitmentRejectionReason;
use App\Models\RecruitmentRequisition;
use App\Models\User;
use App\Services\CandidateJoiningService;
use App\Services\DuplicateCandidateFoundException;
use App\Services\ReferralService;
use App\Services\StageTransitionService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Event;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    CandidateSource::factory()->create(['name' => 'Employee Referral', 'code' => CandidateSource::CODE_EMPLOYEE_REFERRAL]);
    $this->referrals = app(ReferralService::class);
    $this->requisition = RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Open]);
});

function referralUser(string $role, ?Employee $reportsTo = null): User
{
    $employee = $reportsTo !== null ? Employee::factory()->reportingTo($reportsTo)->create() : Employee::factory()->create();
    $user = User::factory()->create(['employee_id' => $employee->id]);
    $user->assignRole($role);

    return $user;
}

/**
 * @return array<string, mixed>
 */
function referredCandidateData(array $overrides = []): array
{
    return ['full_name' => 'Anita Rao', 'mobile' => '9811122233', 'email' => 'anita@example.com', ...$overrides];
}

test('submitting a referral for a new candidate creates the candidate as a referral and records the referral', function (): void {
    Event::fake([ReferralSubmitted::class]);
    $referrer = Employee::factory()->create();

    $referral = $this->referrals->submit($referrer, referredCandidateData(), ['relationship' => ReferralRelationship::FormerColleague->value, 'notes' => 'Great closer'], $this->requisition);

    expect($referral->status)->toBe(ReferralStatus::Submitted)
        ->and($referral->referral_code)->toStartWith('REF-')
        ->and($referral->incentive_status)->toBe(ReferralIncentiveStatus::Eligible)
        ->and($referral->candidate->referral_employee_id)->toBe($referrer->id)
        ->and($referral->candidate->source->name)->toBe('Employee Referral')
        ->and($referral->candidate->timelineEvents()->where('event_type', 'referral')->exists())->toBeTrue();
    Event::assertDispatched(ReferralSubmitted::class);
});

test('a referral for a candidate who already exists is refused until the existing candidate is chosen', function (): void {
    $existing = Candidate::factory()->create(['mobile' => '9811122233']);

    expect(fn () => $this->referrals->submit(Employee::factory()->create(), referredCandidateData(), ['relationship' => 'friend'], $this->requisition))
        ->toThrow(DuplicateCandidateFoundException::class);

    $referral = $this->referrals->submit(Employee::factory()->create(), referredCandidateData(), ['relationship' => 'friend'], $this->requisition, $existing);

    expect($referral->candidate_id)->toBe($existing->id)
        ->and(Candidate::query()->count())->toBe(1);
});

test('creating a new candidate over a duplicate with a justification is audited', function (): void {
    Candidate::factory()->create(['mobile' => '9811122233']);

    $referral = $this->referrals->submit(Employee::factory()->create(), referredCandidateData(), ['relationship' => 'friend'], $this->requisition, duplicateJustification: 'Shared family phone');

    expect(Candidate::query()->count())->toBe(2)
        ->and(AuditLog::query()->where('action', 'duplicate_override')->where('auditable_id', $referral->candidate_id)->exists())->toBeTrue();
});

test('the same candidate cannot be referred twice for one position while a referral is open', function (): void {
    $candidate = Candidate::factory()->create();
    $this->referrals->submit(Employee::factory()->create(), [], ['relationship' => 'friend'], $this->requisition, $candidate);

    $this->referrals->submit(Employee::factory()->create(), [], ['relationship' => 'friend'], $this->requisition, $candidate);
})->throws(DomainException::class, 'already been referred');

test('a referral cannot target a position that is not open', function (): void {
    $closed = RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Closed]);

    $this->referrals->submit(Employee::factory()->create(), referredCandidateData(), ['relationship' => 'friend'], $closed);
})->throws(DomainException::class, 'not open');

test('accepting a referral puts the candidate into the pipeline and links the application', function (): void {
    $referral = $this->referrals->submit(Employee::factory()->create(), referredCandidateData(), ['relationship' => 'friend'], $this->requisition);
    $recruiter = Employee::factory()->create();

    $referral = $this->referrals->accept($referral, $recruiter);

    expect($referral->status)->toBe(ReferralStatus::InProcess)
        ->and($referral->candidateApplication->recruiter_id)->toBe($recruiter->id)
        ->and($referral->candidateApplication->requisition_id)->toBe($this->requisition->id)
        ->and($referral->candidateApplication->current_stage)->toBe(CandidateStage::Sourced);
});

test('accepting links an existing application instead of creating a second one', function (): void {
    $application = CandidateApplication::factory()->create(['requisition_id' => $this->requisition->id]);
    $referral = $this->referrals->submit(Employee::factory()->create(), [], ['relationship' => 'friend'], $this->requisition, $application->candidate);

    $this->referrals->accept($referral, Employee::factory()->create());

    expect($referral->fresh()->candidate_application_id)->toBe($application->id)
        ->and(CandidateApplication::query()->count())->toBe(1);
});

test('reviewer status changes follow the allowed transitions and rejection needs a reason', function (): void {
    $referral = $this->referrals->submit(Employee::factory()->create(), referredCandidateData(), ['relationship' => 'friend'], $this->requisition);

    $this->referrals->moveTo($referral, ReferralStatus::UnderReview);
    expect(fn () => $this->referrals->moveTo($referral, ReferralStatus::Rejected))->toThrow(DomainException::class, 'rejection reason');

    $this->referrals->moveTo($referral, ReferralStatus::Rejected, remarks: 'Not enough experience', reason: RecruitmentRejectionReason::factory()->create());
    expect($referral->fresh()->status)->toBe(ReferralStatus::Rejected);

    $this->referrals->moveTo($referral, ReferralStatus::Joined);
})->throws(DomainException::class, 'cannot move');

test('the referral follows its application through selection, offer, joining and pays the referral bonus', function (): void {
    $referrer = Employee::factory()->create();
    $rule = RecruitmentIncentiveRule::factory()->fixed(5000)->create(['trigger_event' => IncentiveTriggerEvent::ReferralJoining]);
    $recruiterRule = RecruitmentIncentiveRule::factory()->fixed(1000)->create(['trigger_event' => IncentiveTriggerEvent::Selection]);
    $referral = $this->referrals->accept(
        $this->referrals->submit($referrer, referredCandidateData(), ['relationship' => 'friend'], $this->requisition),
        Employee::factory()->create(),
    );
    $application = $referral->candidateApplication;
    $stages = app(StageTransitionService::class);

    $stages->transitionTo($application, CandidateStage::Selected);
    expect($referral->fresh()->status)->toBe(ReferralStatus::Selected);

    $stages->transitionTo($application, CandidateStage::OfferReleased);
    expect($referral->fresh()->status)->toBe(ReferralStatus::OfferReleased);

    // Phase 8.10 (P810-DI-02): joining is the joining record (accepted offer → joining → Mark Joined).
    $joinings = app(CandidateJoiningService::class);
    $joinings->markJoined($joinings->createForAcceptedOffer(Offer::factory()->create(['candidate_application_id' => $application->id, 'status' => OfferStatus::Accepted])), now());
    $referral->refresh();

    $calculation = RecruiterIncentiveCalculation::query()->where('incentive_rule_id', $rule->id)->sole();

    expect($referral->status)->toBe(ReferralStatus::Joined)
        ->and($referral->joining_date)->not->toBeNull()
        ->and($referral->incentive_status)->toBe(ReferralIncentiveStatus::Calculated)
        ->and($referral->incentive_calculation_id)->toBe($calculation->id)
        ->and($calculation->employee_id)->toBe($referrer->id)
        ->and((float) $calculation->amount)->toBe(5000.0)
        ->and(RecruiterIncentiveCalculation::query()->where('incentive_rule_id', $recruiterRule->id)->exists())->toBeFalse();
});

test('a referral bonus is not calculated for an ineligible referral', function (): void {
    RecruitmentIncentiveRule::factory()->fixed(5000)->create(['trigger_event' => IncentiveTriggerEvent::ReferralJoining]);
    $referral = $this->referrals->submit(Employee::factory()->create(), referredCandidateData(), ['relationship' => 'friend', 'incentive_eligible' => false], $this->requisition);
    $referral = $this->referrals->accept($referral, Employee::factory()->create());

    $joinings = app(CandidateJoiningService::class);
    $joinings->markJoined($joinings->createForAcceptedOffer(Offer::factory()->create(['candidate_application_id' => $referral->candidate_application_id, 'status' => OfferStatus::Accepted])), now());

    expect($referral->fresh()->incentive_status)->toBe(ReferralIncentiveStatus::NotEligible)
        ->and(RecruiterIncentiveCalculation::query()->count())->toBe(0);
});

test('a dropout marks the referral as did not join and forfeits the bonus', function (): void {
    $referral = $this->referrals->accept($this->referrals->submit(Employee::factory()->create(), referredCandidateData(), ['relationship' => 'friend'], $this->requisition), Employee::factory()->create());

    app(StageTransitionService::class)->dropout($referral->candidateApplication, RecruitmentRejectionReason::factory()->create());

    expect($referral->fresh()->status)->toBe(ReferralStatus::DidNotJoin)
        ->and($referral->fresh()->incentive_status)->toBe(ReferralIncentiveStatus::Forfeited);
});

test('a referrer sees their own referrals, their manager sees the team, others do not', function (): void {
    $manager = referralUser('manager');
    $employee = referralUser('employee', $manager->employee);
    $outsider = referralUser('recruiter');
    $referral = $this->referrals->submit($employee->employee, referredCandidateData(), ['relationship' => 'friend']);

    expect($employee->can('view', $referral))->toBeTrue()
        ->and($manager->can('view', $referral))->toBeTrue()
        ->and(EmployeeReferral::query()->visibleTo($outsider)->whereKey($referral->id)->exists())->toBe($outsider->can('view', $referral));
});

test('a referrer cannot review their own referral', function (): void {
    $manager = referralUser('manager');
    $referral = $this->referrals->submit($manager->employee, referredCandidateData(), ['relationship' => 'friend'], $this->requisition);

    expect($manager->can('review', $referral))->toBeFalse()
        ->and(referralUser('chro')->can('review', $referral))->toBeTrue();
});

test('an employee cannot open a referral outside their scope', function (): void {
    $referral = $this->referrals->submit(Employee::factory()->create(), referredCandidateData(), ['relationship' => 'friend'], $this->requisition);
    actingAs(referralUser('employee'));

    $this->get(EmployeeReferralResource::getUrl('view', ['record' => $referral]))->assertNotFound();
});

test('an employee submits a referral from the form and is steered to the existing candidate on a duplicate', function (): void {
    $existing = Candidate::factory()->create(['full_name' => 'Anita Rao', 'mobile' => '9811122233']);
    actingAs(referralUser('employee'));

    Livewire::test(CreateEmployeeReferral::class)
        ->fillForm([...referredCandidateData(), 'requisition_id' => $this->requisition->id, 'relationship' => 'friend'])
        ->call('create')
        ->assertNotified('This candidate is already in our system')
        ->assertSet('duplicateMatches.0.id', $existing->id)
        ->assertSet('duplicateMatches.0.mobile', 'XXXXXXX233')
        ->fillForm([...referredCandidateData(), 'requisition_id' => $this->requisition->id, 'relationship' => 'friend', 'existing_candidate_choice' => (string) $existing->id])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(EmployeeReferral::query()->sole()->candidate_id)->toBe($existing->id)
        ->and(Candidate::query()->count())->toBe(1);
});

test('a reviewer accepts a referral from the view page', function (): void {
    $chro = referralUser('chro');
    actingAs($chro);
    $referral = $this->referrals->submit(Employee::factory()->create(), referredCandidateData(), ['relationship' => 'friend'], $this->requisition);

    Livewire::test(ViewEmployeeReferral::class, ['record' => $referral->id])
        ->callAction('acceptReferral', ['requisition_id' => $this->requisition->id, 'recruiter_id' => $chro->employee_id])
        ->assertNotified('Referral accepted into the pipeline');

    expect($referral->fresh()->status)->toBe(ReferralStatus::InProcess)
        ->and($referral->fresh()->candidateApplication->status)->toBe(ApplicationStatus::Active);
});

test('the referral dashboard shows counts and conversion for visible referrals', function (): void {
    actingAs(referralUser('chro'));
    EmployeeReferral::factory()->status(ReferralStatus::Joined)->create();
    EmployeeReferral::factory()->status(ReferralStatus::Rejected)->create();
    EmployeeReferral::factory()->create();

    Livewire::test(ListEmployeeReferrals::class)->assertOk();
    Livewire::test(ReferralStats::class)
        ->assertSee('Referral conversion')
        ->assertSee('50%');
});

test('the requisition recruiters are notified of a new referral', function (): void {
    $recruiter = referralUser('recruiter');
    $this->requisition->recruiters()->attach($recruiter->employee_id);

    $this->referrals->submit(Employee::factory()->create(), referredCandidateData(), ['relationship' => 'friend'], $this->requisition);

    expect($recruiter->notifications()->sole()->data['title'])->toBe('[Referrals] New employee referral');
});
