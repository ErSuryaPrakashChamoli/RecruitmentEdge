<?php

namespace Database\Factories;

use App\Enums\OfferRevisionStatus;
use App\Models\Offer;
use App\Models\OfferRevision;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OfferRevision>
 */
class OfferRevisionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'offer_id' => Offer::factory(),
            'revision' => 2,
            'status' => OfferRevisionStatus::Pending,
            'offered_ctc' => 1200000,
            'fixed_salary' => 1000000,
            'reason' => 'Matched a competing offer',
        ];
    }
}
