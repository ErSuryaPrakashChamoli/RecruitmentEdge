<?php

namespace Database\Factories;

use App\Enums\RecruitmentCostType;
use App\Models\RecruitmentCost;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecruitmentCost>
 */
class RecruitmentCostFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'cost_type' => fake()->randomElement(RecruitmentCostType::cases()),
            'amount' => fake()->numberBetween(1000, 50000),
            'incurred_on' => now(),
        ];
    }
}
