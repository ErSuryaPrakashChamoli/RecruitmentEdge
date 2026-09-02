<?php

namespace Database\Factories;

use App\Models\AiDocument;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiDocument>
 */
class AiDocumentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'category' => fake()->randomElement(['policy', 'sop', 'guideline', 'general']),
            'disk' => 'local',
            'file_path' => 'ai-documents/'.fake()->uuid().'.txt',
            'mime_type' => 'text/plain',
            'is_published' => true,
            'status' => 'pending',
        ];
    }
}
