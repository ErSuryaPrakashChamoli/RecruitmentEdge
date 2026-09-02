<?php

namespace Database\Factories;

use App\Models\AiDocumentChunk;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiDocumentChunk>
 */
class AiDocumentChunkFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'source_type' => 'knowledge_article',
            'source_id' => 1,
            'chunk_index' => 0,
            'content' => fake()->paragraph(),
            'embedding' => array_fill(0, 8, 0.0),
            'token_count' => fake()->numberBetween(50, 500),
        ];
    }
}
