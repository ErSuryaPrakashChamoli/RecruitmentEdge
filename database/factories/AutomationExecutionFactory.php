<?php

namespace Database\Factories;

use App\Enums\AutomationExecutionStatus;
use App\Models\AutomationExecution;
use App\Models\AutomationRule;
use App\Models\CandidateApplication;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AutomationExecution>
 */
class AutomationExecutionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'automation_rule_id' => AutomationRule::factory(),
            'trigger' => 'candidate.stage_changed',
            'subject_type' => (new CandidateApplication)->getMorphClass(),
            'subject_id' => CandidateApplication::factory(),
            'idempotency_key' => 'test:'.Str::uuid(),
            'status' => AutomationExecutionStatus::Pending,
            'scheduled_for' => now(),
            'triggered_at' => now(),
        ];
    }

    public function failed(string $reason = 'Something failed'): static
    {
        return $this->state(fn (): array => ['status' => AutomationExecutionStatus::Failed])
            ->afterCreating(fn (AutomationExecution $execution) => $execution->forceFill(['failure_reason' => $reason, 'completed_at' => now()])->save());
    }
}
