<?php

namespace Database\Factories;

use App\Enums\FeedbackRecommendation;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\InterviewFeedback;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<InterviewFeedback>
 */
class InterviewFeedbackFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'interview_id' => Interview::factory(),
            'interviewer_id' => Employee::factory(),
            'score' => fake()->randomFloat(1, 1, 10),
            'recommendation' => fake()->randomElement(FeedbackRecommendation::cases()),
            'feedback' => fake()->paragraph(),
        ];
    }
}
