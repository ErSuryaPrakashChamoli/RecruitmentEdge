<?php

namespace Database\Factories;

use App\Enums\TenantStatus;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Tenant>
 */
class TenantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'slug' => fake()->unique()->slug(2),
            'name' => fake()->company(),
            'status' => TenantStatus::Active,
            'timezone' => 'Asia/Kolkata',
            'locale' => 'en',
            'currency' => 'INR',
            'country' => 'IN',
        ];
    }

    public function status(TenantStatus $status): static
    {
        return $this->state(fn (): array => ['status' => $status]);
    }
}
