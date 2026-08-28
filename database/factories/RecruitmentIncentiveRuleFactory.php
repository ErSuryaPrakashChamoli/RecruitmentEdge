<?php

namespace Database\Factories;

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
            'effective_from' => now()->startOfMonth(),
            'is_active' => true,
        ];
    }
}
