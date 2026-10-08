<?php

namespace Database\Factories;

use App\Models\CalendarConnection;
use App\Models\Employee;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CalendarConnection>
 */
class CalendarConnectionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'provider' => 'google_calendar',
            'account_email' => fake()->safeEmail(),
            'access_token' => 'access-'.fake()->uuid(),
            'refresh_token' => 'refresh-'.fake()->uuid(),
            'token_expires_at' => now()->addHour(),
            'calendar_id' => 'primary',
            'status' => 'active',
            'connected_at' => now(),
        ];
    }
}
