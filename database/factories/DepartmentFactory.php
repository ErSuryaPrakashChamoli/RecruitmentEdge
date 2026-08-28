<?php

namespace Database\Factories;

use App\Models\Department;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Department>
 */
class DepartmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->randomElement([
                'Talent Acquisition', 'Engineering', 'Sales', 'Operations', 'Finance', 'Customer Support',
            ]),
            'code' => strtoupper(fake()->unique()->lexify('DEPT-???')),
            'is_active' => true,
        ];
    }
}
