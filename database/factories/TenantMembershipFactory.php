<?php

namespace Database\Factories;

use App\Enums\AccessState;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenantMembership>
 */
class TenantMembershipFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'user_id' => User::factory(),
            'status' => AccessState::Active,
            'joined_at' => now(),
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn (): array => ['status' => AccessState::Suspended]);
    }

    public function revoked(): static
    {
        return $this->state(fn (): array => ['status' => AccessState::Revoked]);
    }
}
