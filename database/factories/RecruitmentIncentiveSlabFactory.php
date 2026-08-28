<?php

namespace Database\Factories;

use App\Models\RecruitmentIncentiveRule;
use App\Models\RecruitmentIncentiveSlab;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecruitmentIncentiveSlab>
 */
class RecruitmentIncentiveSlabFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'incentive_rule_id' => RecruitmentIncentiveRule::factory(),
            'achievement_min' => 0,
            'achievement_max' => null,
            'amount' => fake()->numberBetween(500, 3000),
        ];
    }
}
