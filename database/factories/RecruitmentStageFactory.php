<?php

namespace Database\Factories;

use App\Enums\CandidateStage;
use App\Enums\StageType;
use App\Models\RecruitmentStage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecruitmentStage>
 */
class RecruitmentStageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->words(2, true),
            'code' => fake()->unique()->lexify('stage_??????'),
            'stage_type' => StageType::Custom,
            'milestone' => CandidateStage::Screened,
            'color' => 'gray',
            'is_skippable' => true,
            'sort_order' => fake()->numberBetween(1, 500),
            'is_active' => true,
        ];
    }

    public function milestone(CandidateStage $milestone): static
    {
        return $this->state(fn () => [
            'milestone' => $milestone,
            'stage_type' => StageType::forMilestone($milestone),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn () => ['is_active' => false]);
    }

    public function terminal(): static
    {
        return $this->state(fn () => ['is_terminal' => true, 'stage_type' => StageType::Terminal]);
    }

    public function required(): static
    {
        return $this->state(fn () => ['is_skippable' => false]);
    }
}
