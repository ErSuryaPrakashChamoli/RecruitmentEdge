<?php

namespace Database\Factories;

use App\Models\PlatformOperator;
use App\Models\SupportAccessGrant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SupportAccessGrant>
 */
class SupportAccessGrantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'platform_operator_id' => PlatformOperator::factory(),
            'reason' => 'Ticket #4711: export failing',
            'starts_at' => now(),
            'expires_at' => now()->addHour(),
        ];
    }
}
