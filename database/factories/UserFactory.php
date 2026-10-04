<?php

namespace Database\Factories;

use App\Enums\TenantMembershipStatus;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    /**
     * SaaS-1: a staff identity created inside a tenant is a member of that tenant (linked to its
     * employee, if any) — the way IdentityProvisioningService provisions staff.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (User $user): void {
            $tenantId = TenantContext::current()->id();

            if ($tenantId === null) {
                return;
            }

            TenantMembership::query()->firstOrCreate(
                ['tenant_id' => $tenantId, 'user_id' => $user->getKey()],
                ['employee_id' => $user->employee_id, 'status' => TenantMembershipStatus::Active],
            );
        });
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
