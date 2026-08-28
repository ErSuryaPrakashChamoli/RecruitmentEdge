<?php

namespace Database\Factories;

use App\Models\AiKnowledgeArticle;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AiKnowledgeArticle>
 */
class AiKnowledgeArticleFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $title = fake()->unique()->sentence(4);

        return [
            'title' => $title,
            'category' => fake()->randomElement(['policy', 'process', 'faq', 'general']),
            'content' => fake()->paragraphs(3, true),
            'is_published' => true,
        ];
    }
}
