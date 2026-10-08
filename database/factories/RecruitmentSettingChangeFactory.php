<?php

namespace Database\Factories;

use App\Models\RecruitmentSettingChange;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecruitmentSettingChange>
 */
class RecruitmentSettingChangeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'key' => 'sla_days_application_to_screening',
            'old_value' => '2',
            'new_value' => '3',
            'effective_from' => now(),
            'reason' => fake()->sentence(),
        ];
    }
}
