<?php

use App\Enums\CandidateStage;
use App\Enums\EmployeeStatus;
use App\Enums\IncentiveBeneficiary;
use App\Enums\IncentiveTriggerEvent;
use App\Enums\ReferralIncentiveStatus;
use App\Enums\RequisitionStatus;
use App\Filament\Pages\IncentiveDashboard;
use App\Models\AuditLog;
use App\Models\CandidateSource;
use App\Models\Employee;
use App\Models\EmployeeReferral;
use App\Models\RecruiterIncentiveCalculation;
use App\Models\RecruitmentIncentiveRule;
use App\Models\RecruitmentRequisition;
use App\Models\User;
use App\Services\IncentiveStatementService;
use App\Services\RecruiterIncentiveCalculator;
use App\Services\ReferralService;
use App\Services\StageTransitionService;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    CandidateSource::factory()->create(['name' => 'Employee Referral', 'code' => CandidateSource::CODE_EMPLOYEE_REFERRAL]);
    $this->referralRule = RecruitmentIncentiveRule::factory()->fixed(5000)->create(['trigger_event' => IncentiveTriggerEvent::ReferralJoining]);
    $this->joiningRule = RecruitmentIncentiveRule::factory()->fixed(1500)->create(['trigger_event' => IncentiveTriggerEvent::Joining]);
    $this->requisition = RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Open]);
});

function acceptedReferral(Employee $referrer, Employee $recruiter, bool $eligible = true): EmployeeReferral
{
    $service = app(ReferralService::class);
    $referral = $service->submit($referrer, ['full_name' => 'Kiran Rao', 'mobile' => '98'.random_int(10000000, 99999999)], ['relationship' => 'friend', 'incentive_eligible' => $eligible], test()->requisition);

    return $service->accept($referral, $recruiter);
}

function joinReferral(EmployeeReferral $referral): EmployeeReferral
{
    app(StageTransitionService::class)->transitionTo($referral->candidateApplication, CandidateStage::Joined);

    return $referral->fresh();
}

test('a referral bonus is priced by the existing engine with the referrer as an explicit employee-referrer beneficiary', function (): void {
    $referrer = Employee::factory()->create();
    $referral = joinReferral(acceptedReferral($referrer, Employee::factory()->create()));

    $bonus = RecruiterIncentiveCalculation::query()->where('incentive_rule_id', $this->referralRule->id)->sole();

    expect($bonus->beneficiary_type)->toBe(IncentiveBeneficiary::EmployeeReferrer)
        ->and($bonus->employee_id)->toBe($referrer->id)
        ->and($bonus->employee_referral_id)->toBe($referral->id)
        ->and(AuditLog::query()->where('action', 'referral_incentive_evaluated')->where('auditable_id', $referral->id)->sole()->getAttribute('changes')['result'])->toBe('calculated');
});

test('recruiter incentives on the same joining stay recruiter incentives', function (): void {
    $recruiter = Employee::factory()->create();
    RecruitmentIncentiveRule::factory()->fixed(1000)->create(['trigger_event' => IncentiveTriggerEvent::Selection]);
    $referral = acceptedReferral(Employee::factory()->create(), $recruiter);

    app(RecruiterIncentiveCalculator::class)->calculateForSelection($referral->candidateApplication);

    expect(RecruiterIncentiveCalculation::query()->where('employee_id', $recruiter->id)->sole()->beneficiary_type)->toBe(IncentiveBeneficiary::Recruiter);
});

test('a referrer who is the application\'s own recruiter gets no referral bonus, and why is audited', function (): void {
    $recruiter = Employee::factory()->create();
    $referral = joinReferral(acceptedReferral($recruiter, $recruiter));

    expect($referral->incentive_status)->toBe(ReferralIncentiveStatus::NotEligible)
        ->and(RecruiterIncentiveCalculation::query()->where('incentive_rule_id', $this->referralRule->id)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'referral_incentive_ineligible')->sole()->getAttribute('changes')['reason'])->toContain('recruiter on this application');
});

test('an inactive referrer gets no referral bonus', function (): void {
    $referrer = Employee::factory()->create();
    $referral = acceptedReferral($referrer, Employee::factory()->create());
    lifecycleFixture(fn () => $referrer->update(['status' => EmployeeStatus::Inactive]));

    expect(joinReferral($referral)->incentive_status)->toBe(ReferralIncentiveStatus::NotEligible);
});

test('a referral bonus never appears in the referrer\'s recruiter statement, only in their referral statement', function (): void {
    $referrer = Employee::factory()->create();
    joinReferral(acceptedReferral($referrer, Employee::factory()->create()));
    $service = app(IncentiveStatementService::class);

    expect($service->periodStatement($referrer, now())['calculations'])->toBeEmpty()
        ->and($service->periodStatement($referrer, now(), IncentiveBeneficiary::EmployeeReferrer)['calculations'])->toHaveCount(1);
});

test('a referral bonus is excluded from the incentive dashboard scorecard of a referrer who is also a recruiter', function (): void {
    $employee = Employee::factory()->create();
    $user = User::factory()->create(['employee_id' => $employee->id]);
    $user->assignRole('recruiter');
    joinReferral(acceptedReferral($employee, Employee::factory()->create()));
    actingAs($user);

    expect(Livewire::test(IncentiveDashboard::class)->instance()->getMyScorecard())->toBeEmpty();
});

test('changing bonus eligibility needs a reason, is audited, and prices an already-joined referral', function (): void {
    $referral = joinReferral(acceptedReferral(Employee::factory()->create(), Employee::factory()->create(), eligible: false));
    $service = app(ReferralService::class);

    expect(fn () => $service->setIncentiveEligibility($referral, true, ''))->toThrow(DomainException::class, 'reason is required');

    $service->setIncentiveEligibility($referral, true, 'Policy exception approved by CHRO', Employee::factory()->create());

    $audit = AuditLog::query()->where('action', 'referral_incentive_override')->sole();

    expect($referral->fresh()->incentive_status)->toBe(ReferralIncentiveStatus::Calculated)
        ->and($audit->old_values)->toMatchArray(['incentive_eligible' => false])
        ->and($audit->getAttribute('changes'))->toMatchArray(['incentive_eligible' => true, 'reason' => 'Policy exception approved by CHRO']);
});

test('eligibility cannot be changed once the bonus is calculated', function (): void {
    $referral = joinReferral(acceptedReferral(Employee::factory()->create(), Employee::factory()->create()));

    app(ReferralService::class)->setIncentiveEligibility($referral, false, 'Changed my mind');
})->throws(DomainException::class, 'already calculated');
