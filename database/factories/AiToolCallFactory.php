<?php

namespace Database\Factories;

use App\Models\AiMessage;
use App\Models\AiToolCall;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiToolCall>
 */
class AiToolCallFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'message_id' => AiMessage::factory(),
            'tool_name' => 'search_candidates',
            'arguments' => ['query' => fake()->word()],
            'risk_level' => 'read',
            'status' => 'pending',
            'requires_confirmation' => false,
        ];
    }
}
