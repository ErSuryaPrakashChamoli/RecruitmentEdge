<?php

namespace Database\Factories;

use App\Enums\PlatformRole;
use App\Models\PlatformOperator;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlatformOperator>
 */
class PlatformOperatorFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'role' => PlatformRole::Support,
            'reason' => 'Platform support rota',
            'granted_at' => now(),
        ];
    }
}
