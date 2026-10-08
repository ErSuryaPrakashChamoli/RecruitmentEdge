<?php

namespace Database\Factories;

use App\Enums\AccessState;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Identity\StaffAccessService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use WeakMap;

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
     * SaaS-2: the employee link asked for in create(['employee_id' => …]) — kept beside the model
     * until it is created, then written to its membership (the identity has no employee column).
     *
     * @var WeakMap<User, int>|null
     */
    private static ?WeakMap $employeeLinks = null;

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
     * @param  array<string, mixed>  $attributes
     */
    public function newModel(array $attributes = []): User
    {
        $employeeId = Arr::pull($attributes, 'employee_id');

        /** @var User $user */
        $user = parent::newModel($attributes);

        if ($employeeId !== null) {
            self::$employeeLinks ??= new WeakMap;
            self::$employeeLinks[$user] = (int) $employeeId;
        }

        return $user;
    }

    /**
     * SaaS-1/2: a staff identity created inside a tenant is an Active member of that tenant (linked
     * to its employee, if one was given) — the state an accepted invitation leaves.
     */
    public function configure(): static
    {
        return $this->afterCreating(function (User $user): void {
            $tenantId = TenantContext::current()->id();
            $employeeId = self::$employeeLinks[$user] ?? null;

            if ($tenantId === null) {
                return;
            }

            TenantMembership::query()->firstOrCreate(
                ['tenant_id' => $tenantId, 'user_id' => $user->getKey()],
                ['employee_id' => $employeeId, 'status' => AccessState::Active, 'joined_at' => now()],
            );

            StaffAccessService::invalidateDecisions();
            $user->unsetRelation('memberships');
        });
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
