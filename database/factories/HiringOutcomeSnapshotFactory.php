<?php

namespace Database\Factories;

use App\Enums\OutcomeCaptureMode;
use App\Models\CandidateApplication;
use App\Models\HiringOutcomeSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<HiringOutcomeSnapshot>
 */
class HiringOutcomeSnapshotFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'candidate_application_id' => CandidateApplication::factory(),
            'joined_on' => now()->subDays(40)->toDateString(),
            'facts' => ['experience_band' => '3-5_years'],
            'capture_mode' => OutcomeCaptureMode::ObservedGoingForward,
            'rules_version' => HiringOutcomeSnapshot::RULES_VERSION,
            'captured_at' => now(),
        ];
    }
}
