<?php

namespace Database\Factories;

use App\Enums\OutcomeInsightKind;
use App\Enums\OutcomeInsightStatus;
use App\Models\OutcomeInsight;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OutcomeInsight>
 */
class OutcomeInsightFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'kind' => OutcomeInsightKind::RoleDnaLearning,
            'status' => OutcomeInsightStatus::Review,
            'subject_key' => 'skill:laravel',
            'insight' => 'Among 5 comparable completed hires with an observed 90-day status, Laravel appeared in 4 of 4 observed active.',
            'evidence' => ['with_skill' => ['observed' => 4, 'active' => 4]],
            'sample_size' => 5,
            'sample_band' => 'limited',
            'period_start' => now()->subYear()->toDateString(),
            'period_end' => now()->toDateString(),
            'confidence' => 'low',
            'limitations' => 'Small, observational sample.',
            'source_refs' => [],
            'rule_version' => 'outcome-learning/1',
            'dedupe_key' => 'factory:'.fake()->unique()->uuid(),
            'last_recalculated_at' => now(),
        ];
    }
}
