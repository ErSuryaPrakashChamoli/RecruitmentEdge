<?php

use App\Enums\AutomationRuleStatus;
use App\Enums\IncentivePayoutType;
use App\Enums\OfferStatus;
use App\Enums\TargetMetric;
use App\Enums\TargetPeriodType;
use App\Models\AutomationRule;
use App\Models\Employee;
use App\Models\Offer;
use App\Models\RecruiterIncentiveCalculation;
use App\Models\RecruitmentDailyTarget;
use App\Models\RecruitmentIncentiveRule;
use App\Services\Governance\GovernanceAuditor;
use Database\Seeders\RecruitmentReferenceDataSeeder;
use Illuminate\Support\Facades\DB;

/**
 * Phase 8.6 (D8.6-029): governance:audit finds the damage earlier versions allowed, and never
 * writes anything.
 */
function governanceFinding(string $check): ?array
{
    return collect(app(GovernanceAuditor::class)->run())->firstWhere('check', $check);
}

test('it reports pre-8.6 damage: orphan automation scope, lost slabs, overlapping targets and unissued letters', function (): void {
    $rule = AutomationRule::factory()->create(['status' => AutomationRuleStatus::Active]);
    DB::table('automation_rules')->where('id', $rule->id)->update(['scope_type' => 'department', 'scope_id' => 999999]);

    $slabRule = RecruitmentIncentiveRule::factory()->create(['payout_type' => IncentivePayoutType::SlabByCount, 'achievement_metric' => null]);
    RecruiterIncentiveCalculation::factory()->create(['incentive_rule_id' => $slabRule->id, 'incentive_slab_id' => null]);

    $recruiter = Employee::factory()->create();
    $target = RecruitmentDailyTarget::factory()->create(['employee_id' => $recruiter->id, 'metric' => TargetMetric::Calls, 'period_type' => TargetPeriodType::Daily, 'effective_from' => '2026-01-01']);
    DB::table('recruitment_daily_targets')->insert([...collect($target->getAttributes())->except(['id', 'created_at', 'updated_at'])->all(), 'effective_from' => '2026-03-01', 'created_at' => now(), 'updated_at' => now()]);

    lifecycleFixture(fn () => Offer::factory()->create(['status' => OfferStatus::Released]));

    expect(governanceFinding('automation_scope_missing')['count'])->toBe(1)
        ->and(governanceFinding('automation_scope_missing')['level'])->toBe(GovernanceAuditor::ERROR)
        ->and(governanceFinding('calculation_slab_lost')['count'])->toBe(1)
        ->and(governanceFinding('overlapping_targets')['count'])->toBe(1)
        ->and(governanceFinding('released_offers_without_issued_letter')['count'])->toBe(1);

    $this->artisan('governance:audit')->assertFailed();
});

test('it reports configuration drift without revealing the value', function (): void {
    config(['metrics.min_sample' => 4321]);

    $finding = governanceFinding('config_drift_metrics');

    expect($finding['level'])->toBe(GovernanceAuditor::ERROR)
        ->and(str_contains($finding['detail'], '4321'))->toBeFalse();
});

test('it only reads — no insert, update or delete is issued', function (): void {
    $this->seed(RecruitmentReferenceDataSeeder::class);
    AutomationRule::factory()->create();
    $writes = [];
    DB::listen(function ($query) use (&$writes): void {
        if (preg_match('/^\s*(insert|update|delete|replace|alter|create|drop)\b/i', $query->sql)) {
            $writes[] = $query->sql;
        }
    });

    $this->artisan('governance:audit', ['--json' => true])->assertSuccessful();

    expect($writes)->toBe([]);
});
