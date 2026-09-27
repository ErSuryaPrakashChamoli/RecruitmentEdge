<?php

use App\Enums\IncentiveCalculationStatus;
use App\Enums\IncentivePayoutType;
use App\Enums\IncentiveSlabUpgradeMode;
use App\Enums\IncentiveTriggerEvent;
use App\Enums\JoiningStatus;
use App\Filament\Resources\RecruiterIncentiveCalculations\Pages\ViewRecruiterIncentiveCalculation;
use App\Models\AuditLog;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\Employee;
use App\Models\RecruiterIncentiveCalculation;
use App\Models\RecruitmentIncentiveRule;
use App\Models\RecruitmentIncentiveSlab;
use App\Models\User;
use App\Services\IncentiveApprovalService;
use App\Services\RecruiterIncentiveCalculator;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * Phase 8.6 incentive governance (D8.6-014/015/016): every calculation freezes the rule and slab it
 * was priced with; a rule that has priced incentives keeps its terms and bands (new terms = a new
 * rule); a re-price is audited; approved and paid amounts never change.
 */
beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->calculator = app(RecruiterIncentiveCalculator::class);
    $this->recruiter = Employee::factory()->create();
});

function pricingJoining(Employee $recruiter): CandidateJoining
{
    return CandidateJoining::factory()->create([
        'candidate_application_id' => CandidateApplication::factory()->create(['recruiter_id' => $recruiter->id])->id,
        'status' => JoiningStatus::Joined,
        'actual_doj' => now(),
    ]);
}

function countSlabRule(IncentiveSlabUpgradeMode $mode = IncentiveSlabUpgradeMode::Incremental): RecruitmentIncentiveRule
{
    $rule = RecruitmentIncentiveRule::factory()->create([
        'name' => 'Joining bonus',
        'trigger_event' => IncentiveTriggerEvent::Joining,
        'payout_type' => IncentivePayoutType::SlabByCount,
        'achievement_metric' => null,
        'slab_upgrade_mode' => $mode,
        'effective_from' => now()->subMonth(),
    ]);
    RecruitmentIncentiveSlab::factory()->create(['incentive_rule_id' => $rule->id, 'achievement_min' => 1, 'achievement_max' => 1.5, 'amount' => 1000]);
    RecruitmentIncentiveSlab::factory()->create(['incentive_rule_id' => $rule->id, 'achievement_min' => 2, 'achievement_max' => null, 'amount' => 1500]);

    return $rule;
}

test('a calculation freezes the rule, slab and basis it was priced with', function (): void {
    $rule = countSlabRule();
    $calculation = $this->calculator->calculateForJoining(pricingJoining($this->recruiter))->sole();

    expect($calculation->pricing_snapshot['rule'])->toMatchArray(['id' => $rule->id, 'name' => 'Joining bonus', 'payout_type' => 'slab_by_count', 'slab_upgrade_mode' => 'incremental'])
        ->and($calculation->pricing_snapshot['slab'])->toMatchArray(['min' => 1.0, 'max' => 1.5, 'amount' => 1000.0])
        ->and($calculation->pricing_snapshot['basis'])->toBe(['achievement' => null, 'occurrence_count' => 1]);
});

test('FAILURE 5: once a rule has priced an incentive its slabs cannot change, so a pending calculation is never silently re-priced', function (): void {
    $rule = countSlabRule();
    $calculation = $this->calculator->calculateForJoining(pricingJoining($this->recruiter))->sole();
    $slab = $rule->slabs()->first();

    expect(fn () => $slab->update(['amount' => 5000]))->toThrow(DomainException::class, 'slabs are locked')
        ->and(fn () => $slab->delete())->toThrow(DomainException::class, 'slabs are locked')
        ->and(fn () => RecruitmentIncentiveSlab::factory()->create(['incentive_rule_id' => $rule->id, 'achievement_min' => 50, 'achievement_max' => null, 'amount' => 9999]))->toThrow(DomainException::class, 'slabs are locked');

    $recalculated = $this->calculator->calculateForJoining($calculation->candidateApplication->joining ?? CandidateJoining::query()->where('candidate_application_id', $calculation->candidate_application_id)->sole())->sole();

    expect((float) $recalculated->amount)->toBe(1000.0)
        ->and($recalculated->status)->toBe(IncentiveCalculationStatus::PendingVerification);
});

test('a used rule keeps its terms and cannot be deleted; its name and end date stay editable', function (): void {
    $rule = countSlabRule();
    $this->calculator->calculateForJoining(pricingJoining($this->recruiter));
    $admin = User::factory()->create()->assignRole('vp_hr');

    expect(fn () => $rule->fresh()->update(['slab_upgrade_mode' => IncentiveSlabUpgradeMode::Retroactive]))->toThrow(DomainException::class, 'terms are locked')
        ->and(fn () => $rule->fresh()->update(['department_id' => null, 'employee_id' => $this->recruiter->id]))->toThrow(DomainException::class, 'terms are locked')
        ->and(fn () => $rule->fresh()->delete())->toThrow(DomainException::class, 'cannot be deleted')
        ->and($admin->can('delete', $rule->fresh()))->toBeFalse()
        ->and($admin->can('deleteAny', RecruitmentIncentiveRule::class))->toBeFalse();

    $rule->fresh()->update(['name' => 'Joining bonus (2026)', 'effective_to' => now()->endOfMonth()]);

    expect($rule->fresh()->name)->toBe('Joining bonus (2026)');
});

test('an unused rule and its slabs remain freely editable', function (): void {
    $rule = countSlabRule();
    $slab = $rule->slabs()->first();

    $slab->update(['amount' => 1200]);
    $rule->fresh()->update(['slab_upgrade_mode' => IncentiveSlabUpgradeMode::Retroactive]);

    expect((float) $slab->fresh()->amount)->toBe(1200.0)
        ->and(AuditLog::query()->where('auditable_type', RecruitmentIncentiveSlab::class)->where('auditable_id', $slab->id)->where('action', 'updated')->sole()->changes)
        ->toEqual(['amount' => 1200]);
});

test('FAILURE 6: the calculation view shows the pricing applied, not today\'s rule', function (): void {
    $rule = countSlabRule();
    $calculation = $this->calculator->calculateForJoining(pricingJoining($this->recruiter))->sole();
    $rule->fresh()->update(['name' => 'Renamed rule']);
    actingAs(User::factory()->create()->assignRole('chro'));

    Livewire::test(ViewRecruiterIncentiveCalculation::class, ['record' => $calculation->getRouteKey()])
        ->assertSee('Joining bonus')
        ->assertDontSee('Renamed rule')
        ->assertSee('exactly as applied');
});

test('a calculation priced before snapshots were kept says so and marks the current band', function (): void {
    $rule = countSlabRule();
    $calculation = $this->calculator->calculateForJoining(pricingJoining($this->recruiter))->sole();
    RecruiterIncentiveCalculation::query()->whereKey($calculation->id)->update(['pricing_snapshot' => null]);
    actingAs(User::factory()->create()->assignRole('chro'));

    expect($calculation->fresh()->pricedBandLabel())->toEndWith('(current rule)');

    Livewire::test(ViewRecruiterIncentiveCalculation::class, ['record' => $calculation->getRouteKey()])
        ->assertSee('Priced before pricing was recorded');
});

test('a retroactive re-price of a pending sibling is audited with the old and new amount', function (): void {
    countSlabRule(IncentiveSlabUpgradeMode::Retroactive);
    $first = $this->calculator->calculateForJoining(pricingJoining($this->recruiter))->sole();
    $this->calculator->calculateForJoining(pricingJoining($this->recruiter));

    $audit = AuditLog::query()->where('auditable_type', RecruiterIncentiveCalculation::class)->where('auditable_id', $first->id)->where('action', 'incentive_repriced')->sole();

    expect((float) $first->fresh()->amount)->toBe(1500.0)
        ->and($audit->old_values['amount'])->toEqual(1000.0)
        ->and($audit->changes['amount'])->toEqual(1500.0)
        ->and($first->fresh()->pricing_snapshot['slab']['amount'])->toEqual(1500.0);
});

test('approved and paid incentives keep their amount and pricing on recalculation', function (): void {
    countSlabRule();
    $joining = pricingJoining($this->recruiter);
    $calculation = $this->calculator->calculateForJoining($joining)->sole();
    $snapshot = $calculation->pricing_snapshot;
    app(IncentiveApprovalService::class)->approve($calculation);

    $again = $this->calculator->calculateForJoining($joining)->sole();

    expect($again->id)->toBe($calculation->id)
        ->and((float) $again->amount)->toBe(1000.0)
        ->and($again->pricing_snapshot)->toBe($snapshot)
        ->and($again->status)->toBe(IncentiveCalculationStatus::Approved);
});

test('an incentive rule\'s effective range must run forwards', function (): void {
    expect(fn () => RecruitmentIncentiveRule::factory()->create(['effective_from' => now(), 'effective_to' => now()->subDay()]))
        ->toThrow(DomainException::class, 'effective-to');
});
