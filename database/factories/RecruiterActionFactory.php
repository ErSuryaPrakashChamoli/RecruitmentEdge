<?php

namespace Database\Factories;

use App\Enums\ActionPriority;
use App\Enums\RecruiterActionStatus;
use App\Enums\RecruiterActionType;
use App\Models\Employee;
use App\Models\RecruiterAction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecruiterAction>
 */
class RecruiterActionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'action_type' => RecruiterActionType::FollowUp,
            'priority' => ActionPriority::Medium,
            'status' => RecruiterActionStatus::Open,
            'owner_id' => Employee::factory(),
            'reason' => fake()->sentence(),
            'due_at' => now()->addDay(),
        ];
    }

    public function priority(ActionPriority $priority): static
    {
        return $this->state(fn (): array => ['priority' => $priority]);
    }

    public function overdue(): static
    {
        return $this->state(fn (): array => ['due_at' => now()->subHours(3)]);
    }
}
