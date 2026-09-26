<?php

namespace Database\Factories;

use App\Enums\CampaignStatus;
use App\Models\RecruitmentCampaign;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecruitmentCampaign>
 */
class RecruitmentCampaignFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => ucwords(fake()->words(3, true)),
            'code' => strtoupper(fake()->unique()->bothify('CMP-####')),
            'status' => CampaignStatus::Active,
            'starts_on' => now()->subWeek()->toDateString(),
            'ends_on' => now()->addMonth()->toDateString(),
            'budget' => 100000,
            'target_hires' => 5,
        ];
    }
}
