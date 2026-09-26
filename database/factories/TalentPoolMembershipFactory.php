<?php

namespace Database\Factories;

use App\Enums\TalentPoolMemberSource;
use App\Models\Candidate;
use App\Models\TalentPool;
use App\Models\TalentPoolMembership;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TalentPoolMembership>
 */
class TalentPoolMembershipFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'talent_pool_id' => TalentPool::factory(),
            'candidate_id' => Candidate::factory(),
            'source' => TalentPoolMemberSource::Manual,
            'added_at' => now(),
        ];
    }
}
