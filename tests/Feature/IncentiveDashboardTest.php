<?php

use App\Enums\IncentiveCalculationStatus;
use App\Enums\TargetMetric;
use App\Enums\TargetPeriodType;
use App\Filament\Pages\IncentiveDashboard;
use App\Filament\Widgets\IncentiveDashboardStats;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\RecruiterIncentiveCalculation;
use App\Models\RecruitmentDailyTarget;
use App\Models\RecruitmentIncentiveRule;
use App\Models\RecruitmentIncentiveSlab;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

test('the incentive dashboard renders the scorecard for a recruiter with real calculations', function (): void {
    $recruiter = Employee::factory()->create();
    RecruiterIncentiveCalculation::factory()->create([
        'employee_id' => $recruiter->id,
        'period_start' => now()->startOfMonth(),
        'period_end' => now()->endOfMonth(),
        'status' => IncentiveCalculationStatus::PendingVerification,
    ]);

    $user = User::factory()->create(['employee_id' => $recruiter->id]);
    $user->assignRole('recruiter');

    actingAs($user)
        ->get('/admin/incentive-dashboard')
        ->assertSuccessful()
        ->assertSee('My Incentive Scorecard');
});

test('the scorecard slab progress reflects the real slab and target data, not an invented figure', function (): void {
    $recruiter = Employee::factory()->create();

    RecruitmentDailyTarget::factory()->create([
        'employee_id' => $recruiter->id,
        'metric' => TargetMetric::Joining,
        'period_type' => TargetPeriodType::Monthly,
        'target_value' => 40,
        'effective_from' => now()->startOfMonth(),
    ]);

    $rule = RecruitmentIncentiveRule::factory()->create([
        'achievement_metric' => TargetMetric::Joining,
    ]);
    $lowSlab = RecruitmentIncentiveSlab::factory()->create([
        'incentive_rule_id' => $rule->id,
        'achievement_min' => 0,
        'achievement_max' => 49.99,
        'amount' => 500,
    ]);
    $highSlab = RecruitmentIncentiveSlab::factory()->create([
        'incentive_rule_id' => $rule->id,
        'achievement_min' => 50,
        'achievement_max' => null,
        'amount' => 1500,
    ]);

    $calculation = RecruiterIncentiveCalculation::factory()->create([
        'incentive_rule_id' => $rule->id,
        'incentive_slab_id' => $lowSlab->id,
        'employee_id' => $recruiter->id,
        'achievement' => 25.0,
        'amount' => $lowSlab->amount,
        'period_start' => now()->startOfMonth(),
        'period_end' => now()->endOfMonth(),
    ]);

    $user = User::factory()->create(['employee_id' => $recruiter->id]);
    $user->assignRole('recruiter');
    actingAs($user);

    $scorecard = Livewire::test(IncentiveDashboard::class)->instance()->getMyScorecard();
    $row = $scorecard->firstWhere('calculation.id', $calculation->id);

    expect($row['target'])->toBe(40)
        ->and($row['slabProgress']['current']->id)->toBe($lowSlab->id)
        ->and($row['slabProgress']['next']->id)->toBe($highSlab->id)
        ->and($row['slabProgress']['remaining'])->toBe(25.0)
        ->and($row['slabProgress']['nextAmount'])->toBe(1500.0)
        ->and($row['slabProgress']['topBandReached'])->toBeFalse();
});

test('the open-ended top band shows a top band reached state instead of a fake progress bar', function (): void {
    $recruiter = Employee::factory()->create();

    $rule = RecruitmentIncentiveRule::factory()->create(['achievement_metric' => TargetMetric::Joining]);
    RecruitmentIncentiveSlab::factory()->create(['incentive_rule_id' => $rule->id, 'achievement_min' => 0, 'achievement_max' => 49.99, 'amount' => 500]);
    $topSlab = RecruitmentIncentiveSlab::factory()->create(['incentive_rule_id' => $rule->id, 'achievement_min' => 50, 'achievement_max' => null, 'amount' => 1500]);

    $calculation = RecruiterIncentiveCalculation::factory()->create([
        'incentive_rule_id' => $rule->id,
        'incentive_slab_id' => $topSlab->id,
        'employee_id' => $recruiter->id,
        'achievement' => 60.0,
        'amount' => 1500,
    ]);

    $user = User::factory()->create(['employee_id' => $recruiter->id]);
    $user->assignRole('recruiter');
    actingAs($user);

    $row = Livewire::test(IncentiveDashboard::class)->instance()->getMyScorecard()->firstWhere('calculation.id', $calculation->id);

    expect($row['slabProgress']['topBandReached'])->toBeTrue()
        ->and($row['slabProgress']['progressPct'])->toBeNull()
        ->and($row['slabProgress']['next'])->toBeNull();

    $this->get('/admin/incentive-dashboard')
        ->assertSuccessful()
        ->assertSee('Top band reached');
});

test('the scorecard stats show the viewer\'s own totals separately from the team totals', function (): void {
    $manager = Employee::factory()->create();
    $recruiter = Employee::factory()->reportingTo($manager)->create();

    RecruiterIncentiveCalculation::factory()->create(['employee_id' => $manager->id, 'amount' => 1000, 'status' => IncentiveCalculationStatus::PendingVerification]);
    $teamCalculation = RecruiterIncentiveCalculation::factory()->create(['employee_id' => $recruiter->id, 'amount' => 5000, 'status' => IncentiveCalculationStatus::PendingVerification]);
    $teamCalculation->adjustments()->create(['adjustment_type' => 'correction', 'amount_delta' => -500, 'reason' => 'Correction']);

    $managerUser = User::factory()->create(['employee_id' => $manager->id]);
    $managerUser->assignRole('manager');
    actingAs($managerUser);

    $stats = incentiveStatValues();

    expect($stats['My Pending Verification'])->toBe('₹1,000.00')
        ->and($stats['My Earned (This Month)'])->toBe('₹1,000.00')
        ->and($stats['Team Pending Verification'])->toBe('₹5,500.00');

    $recruiterUser = User::factory()->create(['employee_id' => $recruiter->id]);
    $recruiterUser->assignRole('recruiter');
    actingAs($recruiterUser);

    $recruiterStats = incentiveStatValues();

    expect($recruiterStats['My Pending Verification'])->toBe('₹4,500.00')
        ->and(array_keys($recruiterStats))->not->toContain('Team Pending Verification');
});

test('scorecard and team rows link to the calculation view and rule names link to the rule', function (): void {
    $chroEmployee = Employee::factory()->create();
    $recruiter = Employee::factory()->reportingTo($chroEmployee)->create();
    CandidateApplication::factory()->create(['recruiter_id' => $recruiter->id]);

    $ownCalculation = RecruiterIncentiveCalculation::factory()->create(['employee_id' => $chroEmployee->id]);
    $teamCalculation = RecruiterIncentiveCalculation::factory()->create(['employee_id' => $recruiter->id]);

    $user = User::factory()->create(['employee_id' => $chroEmployee->id]);
    $user->assignRole('chro');

    actingAs($user)
        ->get('/admin/incentive-dashboard')
        ->assertSuccessful()
        ->assertSee("/admin/recruiter-incentive-calculations/{$ownCalculation->id}", false)
        ->assertSee("/admin/recruiter-incentive-calculations/{$teamCalculation->id}", false)
        ->assertSee("/admin/recruitment-incentive-rules/{$ownCalculation->incentive_rule_id}/edit", false);
});

test('rule names are not linked for viewers who cannot configure rules', function (): void {
    $recruiter = Employee::factory()->create();
    $calculation = RecruiterIncentiveCalculation::factory()->create(['employee_id' => $recruiter->id]);

    $user = User::factory()->create(['employee_id' => $recruiter->id]);
    $user->assignRole('recruiter');

    actingAs($user)
        ->get('/admin/incentive-dashboard')
        ->assertSuccessful()
        ->assertSee("/admin/recruiter-incentive-calculations/{$calculation->id}", false)
        ->assertDontSee("/admin/recruitment-incentive-rules/{$calculation->incentive_rule_id}/edit", false);
});

/**
 * @return array<string, string>
 */
function incentiveStatValues(): array
{
    $widget = Livewire::test(IncentiveDashboardStats::class)->instance();
    $stats = (fn (): array => $this->getStats())->call($widget);

    return collect($stats)->mapWithKeys(fn ($stat) => [(string) $stat->getLabel() => (string) $stat->getValue()])->all();
}

test('the team incentive view only appears for viewers who manage more than themselves', function (): void {
    $manager = Employee::factory()->create();
    $recruiter = Employee::factory()->reportingTo($manager)->create();
    CandidateApplication::factory()->create(['recruiter_id' => $recruiter->id]);

    $managerUser = User::factory()->create(['employee_id' => $manager->id]);
    $managerUser->assignRole('manager');

    $recruiterUser = User::factory()->create(['employee_id' => $recruiter->id]);
    $recruiterUser->assignRole('recruiter');

    actingAs($managerUser)
        ->get('/admin/incentive-dashboard')
        ->assertSuccessful()
        ->assertSee('Team Incentive');

    actingAs($recruiterUser)
        ->get('/admin/incentive-dashboard')
        ->assertSuccessful()
        ->assertDontSee('Team Incentive');
});

test('the team incentive numbers respect hierarchy and never leak another team\'s recruiter', function (): void {
    $manager = Employee::factory()->create();
    $ownRecruiter = Employee::factory()->reportingTo($manager)->create(['first_name' => 'Visible', 'last_name' => 'Recruiter']);
    $outsider = Employee::factory()->create(['first_name' => 'Hidden', 'last_name' => 'Recruiter']);

    CandidateApplication::factory()->create(['recruiter_id' => $ownRecruiter->id]);
    RecruiterIncentiveCalculation::factory()->create([
        'employee_id' => $ownRecruiter->id,
        'period_start' => now()->startOfMonth(),
        'period_end' => now()->endOfMonth(),
    ]);

    CandidateApplication::factory()->create(['recruiter_id' => $outsider->id]);
    RecruiterIncentiveCalculation::factory()->create([
        'employee_id' => $outsider->id,
        'period_start' => now()->startOfMonth(),
        'period_end' => now()->endOfMonth(),
    ]);

    $managerUser = User::factory()->create(['employee_id' => $manager->id]);
    $managerUser->assignRole('manager');

    actingAs($managerUser)
        ->get('/admin/incentive-dashboard')
        ->assertSuccessful()
        ->assertSee('Visible Recruiter')
        ->assertDontSee('Hidden Recruiter');
});
