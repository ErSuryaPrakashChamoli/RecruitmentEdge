<?php

namespace Database\Factories;

use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->city(),
            'code' => strtoupper(fake()->unique()->lexify('LOC-???')),
            'city' => fake()->city(),
            'state' => fake()->state(),
            'country' => fake()->country(),
            'is_active' => true,
        ];
    }
}
