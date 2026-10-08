<?php

namespace Database\Factories;

use App\Models\JobPosting;
use App\Models\RecruitmentRequisition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Creates a draft posting; publish it through JobDistributionService in tests of publishing.
 *
 * @extends Factory<JobPosting>
 */
class JobPostingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'requisition_id' => RecruitmentRequisition::factory(),
            'public_slug' => fake()->unique()->slug(4),
            'title' => fake()->jobTitle(),
            'summary' => fake()->sentence(),
            'description' => fake()->paragraphs(3, true),
        ];
    }
}
