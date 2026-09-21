<?php

namespace Database\Factories;

use App\Enums\IncentivePayoutType;
use App\Enums\IncentiveSlabUpgradeMode;
use App\Enums\IncentiveTriggerEvent;
use App\Enums\TargetMetric;
use App\Models\RecruitmentIncentiveRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecruitmentIncentiveRule>
 */
class RecruitmentIncentiveRuleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(3, true),
            'trigger_event' => IncentiveTriggerEvent::Joining,
            'achievement_metric' => TargetMetric::Joining,
            'payout_type' => IncentivePayoutType::SlabByAchievement,
            'slab_upgrade_mode' => IncentiveSlabUpgradeMode::Incremental,
            'effective_from' => now()->startOfMonth(),
            'is_active' => true,
        ];
    }

    /**
     * The same amount for every occurrence, no slabs.
     */
    public function fixed(float $amount = 2000): static
    {
        return $this->state(fn (): array => [
            'payout_type' => IncentivePayoutType::Fixed,
            'fixed_amount' => $amount,
            'achievement_metric' => null,
        ]);
    }

    /**
     * Slabs matched against the recruiter's occurrence count for the month.
     */
    public function slabByCount(IncentiveSlabUpgradeMode $mode = IncentiveSlabUpgradeMode::Incremental): static
    {
        return $this->state(fn (): array => [
            'payout_type' => IncentivePayoutType::SlabByCount,
            'slab_upgrade_mode' => $mode,
            'achievement_metric' => null,
        ]);
    }
}
