<?php

namespace Database\Factories;

use App\Enums\IncentiveAdjustmentType;
use App\Models\RecruiterIncentiveAdjustment;
use App\Models\RecruiterIncentiveCalculation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecruiterIncentiveAdjustment>
 */
class RecruiterIncentiveAdjustmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'recruiter_incentive_calculation_id' => RecruiterIncentiveCalculation::factory(),
            'adjustment_type' => IncentiveAdjustmentType::Correction,
            'amount_delta' => fake()->randomFloat(2, -500, 500),
            'reason' => fake()->sentence(),
        ];
    }
}
