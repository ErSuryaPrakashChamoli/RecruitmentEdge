<?php

namespace Database\Factories;

use App\Enums\CommunicationChannel;
use App\Enums\CommunicationStatus;
use App\Enums\CommunicationTrigger;
use App\Models\Candidate;
use App\Models\CandidateCommunication;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Real messages are created by CommunicationService; this factory is for fixtures (webhooks,
 * analytics) only.
 *
 * @extends Factory<CandidateCommunication>
 */
class CandidateCommunicationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'candidate_id' => Candidate::factory(),
            'channel' => CommunicationChannel::Email,
            'direction' => 'outbound',
            'subject' => 'Hello',
            'body' => fake()->sentence(),
            'recipient' => fake()->safeEmail(),
            'trigger' => CommunicationTrigger::Manual,
            'idempotency_key' => 'fixture:'.Str::uuid(),
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (CandidateCommunication $communication): void {
            $communication->status ??= CommunicationStatus::Queued;
        });
    }
}
