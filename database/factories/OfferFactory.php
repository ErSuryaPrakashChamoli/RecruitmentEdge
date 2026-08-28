<?php

namespace Database\Factories;

use App\Enums\OfferStatus;
use App\Models\CandidateApplication;
use App\Models\Offer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Offer>
 */
class OfferFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'offer_code' => 'OFR-'.now()->year.'-'.fake()->unique()->numerify('######'),
            'candidate_application_id' => CandidateApplication::factory(),
            'offered_ctc' => fake()->numberBetween(400000, 1500000),
            'fixed_salary' => fake()->numberBetween(350000, 1200000),
            'offer_date' => now(),
            'offer_expiry' => now()->addWeek(),
            'status' => OfferStatus::Draft,
            'expected_joining_date' => now()->addWeeks(3),
        ];
    }
}
