<?php

namespace Database\Factories;

use App\Models\Employee;
use App\Models\Interviewer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Interviewer>
 */
class InterviewerFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'is_active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['is_active' => false]);
    }
}
