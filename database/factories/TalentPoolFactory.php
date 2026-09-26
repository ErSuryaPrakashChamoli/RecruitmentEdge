<?php

namespace Database\Factories;

use App\Enums\TalentPoolStatus;
use App\Enums\TalentPoolVisibility;
use App\Models\Employee;
use App\Models\TalentPool;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TalentPool>
 */
class TalentPoolFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => ucwords(fake()->words(2, true)).' Pool',
            'slug' => fake()->unique()->slug(3),
            'description' => fake()->sentence(),
            'status' => TalentPoolStatus::Active,
            'visibility' => TalentPoolVisibility::Team,
            'owner_id' => Employee::factory(),
            'tags' => ['sales'],
        ];
    }

    public function visibility(TalentPoolVisibility $visibility): static
    {
        return $this->state(fn () => ['visibility' => $visibility]);
    }

    public function archived(): static
    {
        return $this->state(fn () => ['status' => TalentPoolStatus::Archived, 'archived_at' => now()]);
    }
}
