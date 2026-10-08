<?php

namespace Database\Factories;

use App\Enums\InvitationStatus;
use App\Models\TenantInvitation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<TenantInvitation>
 */
class TenantInvitationFactory extends Factory
{
    /**
     * Define the model's default state. The token behind token_hash is random and unknown; tests
     * that open a link create invitations through TenantInvitationService, which returns the token.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'email' => fake()->unique()->safeEmail(),
            'name' => fake()->name(),
            'role_ids' => [],
            'source' => 'administrator',
            'token_hash' => hash('sha256', Str::random(64)),
            'status' => InvitationStatus::Pending,
            'expires_at' => now()->addDays(3),
            'last_sent_at' => now(),
            'send_count' => 1,
        ];
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subMinute()]);
    }
}
