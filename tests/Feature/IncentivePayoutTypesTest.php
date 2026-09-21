<?php

use App\Enums\IncentivePayoutType;
use App\Enums\IncentiveSlabUpgradeMode;
use App\Enums\JoiningStatus;
use App\Enums\TargetMetric;
use App\Enums\TargetPeriodType;
use App\Filament\Resources\RecruitmentIncentiveRules\Pages\EditRecruitmentIncentiveRule;
use App\Filament\Resources\RecruitmentIncentiveRules\RelationManagers\SlabsRelationManager;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\Employee;
use App\Models\RecruiterIncentiveCalculation;
use App\Models\RecruitmentDailyTarget;
use App\Models\RecruitmentIncentiveRule;
use App\Models\RecruitmentIncentiveSlab;
use App\Models\User;
use App\Services\IncentiveApprovalService;
use App\Services\RecruiterIncentiveCalculator;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->recruiter = Employee::factory()->create();
});

/**
 * Marks a new candidate of the recruiter as joined today and returns the calculation it produced.
 */
function joinCandidateForIncentive(Employee $recruiter): ?RecruiterIncentiveCalculation
{
    $application = CandidateApplication::factory()->create(['recruiter_id' => $recruiter->id]);
    $joining = CandidateJoining::factory()->create([
        'candidate_application_id' => $application->id,
        'status' => JoiningStatus::Joined,
        'actual_doj' => now(),
    ]);

    return app(RecruiterIncentiveCalculator::class)->calculateForJoining($joining)->first();
}

/**
 * 1–3 joinings pay ₹2,000 each; 4 or more pay ₹3,000 each.
 */
function joiningCountSlabs(RecruitmentIncentiveRule $rule): void
{
    RecruitmentIncentiveSlab::factory()->create(['incentive_rule_id' => $rule->id, 'achievement_min' => 1, 'achievement_max' => 3, 'amount' => 2000]);
    RecruitmentIncentiveSlab::factory()->create(['incentive_rule_id' => $rule->id, 'achievement_min' => 4, 'achievement_max' => null, 'amount' => 3000]);
}

test('a fixed-rate rule pays the same amount for every joining without a slab', function (): void {
    RecruitmentIncentiveRule::factory()->fixed(1500)->create();

    $first = joinCandidateForIncentive($this->recruiter);
    $second = joinCandidateForIncentive($this->recruiter);

    expect((float) $first->amount)->toBe(1500.0)
        ->and((float) $second->amount)->toBe(1500.0)
        ->and($second->incentive_slab_id)->toBeNull();
});

test('a fixed-rate rule cannot be saved without an amount', function (): void {
    expect(fn () => RecruitmentIncentiveRule::factory()->create([
        'payout_type' => IncentivePayoutType::Fixed,
        'fixed_amount' => null,
    ]))->toThrow(DomainException::class);
});

test('a slab-by-count rule in incremental mode pays each joining the rate of the band it falls in', function (): void {
    $rule = RecruitmentIncentiveRule::factory()->slabByCount()->create();
    joiningCountSlabs($rule);

    $calculations = collect(range(1, 4))->map(fn () => joinCandidateForIncentive($this->recruiter));

    expect($calculations->map(fn (RecruiterIncentiveCalculation $calculation) => (float) $calculation->fresh()->amount)->all())
        ->toBe([2000.0, 2000.0, 2000.0, 3000.0])
        ->and($calculations->last()->occurrence_count)->toBe(4)
        ->and($calculations->last()->incentiveSlab->bandLabel())->toBe('4+ joinings');
});

test('a slab-by-count rule in retroactive mode re-prices the month and tops up an approved joining once', function (): void {
    $rule = RecruitmentIncentiveRule::factory()->slabByCount(IncentiveSlabUpgradeMode::Retroactive)->create();
    joiningCountSlabs($rule);

    $first = joinCandidateForIncentive($this->recruiter);
    app(IncentiveApprovalService::class)->approve($first);
    $second = joinCandidateForIncentive($this->recruiter);
    $third = joinCandidateForIncentive($this->recruiter);
    $fourth = joinCandidateForIncentive($this->recruiter);
    joinCandidateForIncentive($this->recruiter);

    expect((float) $fourth->amount)->toBe(3000.0)
        ->and((float) $second->fresh()->amount)->toBe(3000.0)
        ->and((float) $third->fresh()->amount)->toBe(3000.0)
        ->and((float) $first->fresh()->amount)->toBe(2000.0)
        ->and($first->fresh()->effectiveAmount())->toBe(3000.0)
        ->and($first->adjustments()->count())->toBe(1);
});

test('a slab-by-achievement rule in retroactive mode upgrades earlier joinings when achievement rises', function (): void {
    RecruitmentDailyTarget::factory()->create([
        'employee_id' => $this->recruiter->id,
        'metric' => TargetMetric::Joining,
        'period_type' => TargetPeriodType::Monthly,
        'target_value' => 2,
        'effective_from' => now()->startOfMonth(),
    ]);

    $rule = RecruitmentIncentiveRule::factory()->create(['slab_upgrade_mode' => IncentiveSlabUpgradeMode::Retroactive]);
    RecruitmentIncentiveSlab::factory()->create(['incentive_rule_id' => $rule->id, 'achievement_min' => 0, 'achievement_max' => 99, 'amount' => 500]);
    RecruitmentIncentiveSlab::factory()->create(['incentive_rule_id' => $rule->id, 'achievement_min' => 100, 'achievement_max' => null, 'amount' => 2000]);

    $first = joinCandidateForIncentive($this->recruiter);

    expect((float) $first->amount)->toBe(500.0);

    $second = joinCandidateForIncentive($this->recruiter);

    expect((float) $second->amount)->toBe(2000.0)
        ->and((float) $first->fresh()->amount)->toBe(2000.0);
});

test('the slabs table is hidden for fixed-rate rules and speaks in joinings for count rules', function (): void {
    $this->seed(RolePermissionSeeder::class);
    $user = User::factory()->create(['employee_id' => $this->recruiter->id]);
    $user->assignRole('chro');
    actingAs($user);

    $fixed = RecruitmentIncentiveRule::factory()->fixed()->create();
    $count = RecruitmentIncentiveRule::factory()->slabByCount()->create();

    expect(SlabsRelationManager::canViewForRecord($fixed, EditRecruitmentIncentiveRule::class))->toBeFalse()
        ->and(SlabsRelationManager::canViewForRecord($count, EditRecruitmentIncentiveRule::class))->toBeTrue();

    Livewire::test(SlabsRelationManager::class, ['ownerRecord' => $count, 'pageClass' => EditRecruitmentIncentiveRule::class])
        ->assertSee('Joinings from')
        ->assertSee('Amount per joining');
});

test('an admin can switch a rule to a fixed rate, which requires an amount', function (): void {
    $this->seed(RolePermissionSeeder::class);
    $user = User::factory()->create(['employee_id' => $this->recruiter->id]);
    $user->assignRole('chro');
    actingAs($user);

    $rule = RecruitmentIncentiveRule::factory()->create();

    Livewire::test(EditRecruitmentIncentiveRule::class, ['record' => $rule->getKey()])
        ->fillForm(['payout_type' => IncentivePayoutType::Fixed->value, 'fixed_amount' => null])
        ->call('save')
        ->assertHasFormErrors(['fixed_amount' => 'required'])
        ->fillForm(['fixed_amount' => 2500])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($rule->fresh()->payout_type)->toBe(IncentivePayoutType::Fixed)
        ->and((float) $rule->fresh()->fixed_amount)->toBe(2500.0);
});
