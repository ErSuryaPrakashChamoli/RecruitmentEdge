<?php

namespace Database\Factories;

use App\Enums\InterviewMode;
use App\Enums\InterviewSlotStatus;
use App\Models\InterviewAvailabilitySlot;
use App\Models\Interviewer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Builds a future, bookable single-seat slot. Prefer InterviewSchedulingService::createSlots() in
 * tests of slot creation itself.
 *
 * @extends Factory<InterviewAvailabilitySlot>
 */
class InterviewAvailabilitySlotFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $start = now()->addDays(2)->setTime(10, 0);

        return [
            // Slots belong to listed interviewers (the slot form offers only those; Phase 8.6 D8.6-009).
            'interviewer_id' => fn (): int => Interviewer::factory()->create()->employee_id,
            'starts_at' => $start,
            'ends_at' => $start->copy()->addMinutes(45),
            'timezone' => 'Asia/Kolkata',
            'capacity' => 1,
            'mode' => InterviewMode::VideoCall,
            'meeting_link' => 'https://meet.example.com/abc',
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (InterviewAvailabilitySlot $slot): void {
            $slot->status ??= InterviewSlotStatus::Available;
            $slot->booked_count ??= 0;
        });
    }

    public function startingAt(\DateTimeInterface $start): static
    {
        return $this->state(fn () => ['starts_at' => $start, 'ends_at' => (clone $start)->modify('+45 minutes')]);
    }

    public function status(InterviewSlotStatus $status): static
    {
        return $this->afterMaking(fn (InterviewAvailabilitySlot $slot) => $slot->status = $status);
    }
}
