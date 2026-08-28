<?php

namespace Database\Factories;

use App\Models\RecruiterIncentiveCalculation;
use App\Models\RecruiterIncentivePayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecruiterIncentivePayment>
 */
class RecruiterIncentivePaymentFactory extends Factory
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
            'amount' => fake()->numberBetween(500, 3000),
            'payment_date' => now(),
        ];
    }
}
