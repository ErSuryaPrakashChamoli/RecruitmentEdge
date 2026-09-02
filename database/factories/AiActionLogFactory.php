<?php

namespace Database\Factories;

use App\Models\AiActionLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiActionLog>
 */
class AiActionLogFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'tool_name' => 'move_candidates_stage',
            'risk_level' => 'write',
            'entity_type' => 'CandidateApplication',
            'entity_ids' => [],
            'input' => [],
            'result_summary' => fake()->sentence(),
            'status' => 'executed',
        ];
    }
}
