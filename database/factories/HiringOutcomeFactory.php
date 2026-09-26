<?php

namespace Database\Factories;

use App\Enums\OutcomeCaptureMode;
use App\Enums\OutcomeConfidence;
use App\Enums\OutcomeResult;
use App\Enums\OutcomeState;
use App\Enums\OutcomeType;
use App\Models\HiringOutcome;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HiringOutcome>
 */
class HiringOutcomeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'outcome_type' => OutcomeType::Joined,
            'category' => OutcomeType::Joined->category(),
            'state' => OutcomeState::Observed,
            'result' => OutcomeResult::Occurred,
            'confidence' => OutcomeConfidence::High,
            'capture_mode' => OutcomeCaptureMode::ObservedGoingForward,
            'observed_at' => now(),
            'rule_version' => 'outcome-rules/1',
            'dedupe_key' => 'factory:'.fake()->unique()->uuid(),
            'version' => 1,
            'is_current' => true,
        ];
    }
}
