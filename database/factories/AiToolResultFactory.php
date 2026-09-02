<?php

namespace Database\Factories;

use App\Models\AiToolCall;
use App\Models\AiToolResult;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiToolResult>
 */
class AiToolResultFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tool_call_id' => AiToolCall::factory(),
            'output' => ['results' => []],
            'success' => true,
        ];
    }
}
