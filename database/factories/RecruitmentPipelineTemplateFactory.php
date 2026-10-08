<?php

namespace Database\Factories;

use App\Models\RecruitmentPipelineTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Creates the template row only; build stage lists through PipelineTemplateService::create() so
 * its validation and versioning apply.
 *
 * @extends Factory<RecruitmentPipelineTemplate>
 */
class RecruitmentPipelineTemplateFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->words(3, true);

        return [
            'name' => ucwords($name),
            'slug' => fake()->unique()->slug(3),
            'description' => fake()->sentence(),
            'is_active' => true,
            'is_default' => false,
        ];
    }
}
