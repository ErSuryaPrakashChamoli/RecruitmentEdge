<?php

namespace Database\Factories;

use App\Models\AiConversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiConversation>
 */
class AiConversationFactory extends Factory
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
            'context_type' => null,
            'context_id' => null,
            'title' => fake()->sentence(4),
            'model' => 'gpt-5.6-terra',
            'status' => 'active',
            'last_message_at' => now(),
        ];
    }
}
