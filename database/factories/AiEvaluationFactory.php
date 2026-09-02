<?php

namespace Database\Factories;

use App\Models\AiEvaluation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiEvaluation>
 */
class AiEvaluationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->sentence(3),
            'category' => 'internal',
            'question' => fake()->sentence(8).'?',
            'expected_tool' => 'search_candidates',
        ];
    }
}
