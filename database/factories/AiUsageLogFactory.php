<?php

namespace Database\Factories;

use App\Models\AiUsageLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiUsageLog>
 */
class AiUsageLogFactory extends Factory
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
            'provider' => 'openai',
            'model' => 'gpt-5.6-terra',
            'request_type' => 'chat',
            'input_tokens' => fake()->numberBetween(100, 2000),
            'output_tokens' => fake()->numberBetween(50, 800),
            'cached_tokens' => 0,
            'cost' => fake()->randomFloat(6, 0.0001, 0.05),
            'latency_ms' => fake()->numberBetween(200, 4000),
            'status' => 'success',
        ];
    }
}
