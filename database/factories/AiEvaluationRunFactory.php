<?php

namespace Database\Factories;

use App\Models\AiEvaluation;
use App\Models\AiEvaluationRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiEvaluationRun>
 */
class AiEvaluationRunFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'evaluation_id' => AiEvaluation::factory(),
            'passed' => true,
            'actual_output' => [],
            'run_at' => now(),
        ];
    }
}
