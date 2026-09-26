<?php

namespace Database\Factories;

use App\Enums\SchedulingChannel;
use App\Models\InterviewSlotBooking;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Bookings are created through InterviewSchedulingService::book(); this factory exists for
 * completeness and must be given slot/application/candidate explicitly.
 *
 * @extends Factory<InterviewSlotBooking>
 */
class InterviewSlotBookingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'channel' => SchedulingChannel::CandidatePortal,
            'booked_at' => now(),
        ];
    }
}
