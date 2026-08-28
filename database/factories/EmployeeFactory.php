<?php

namespace Database\Factories;

use App\Enums\EmployeeStatus;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Employee>
 */
class EmployeeFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_code' => 'EMP-'.fake()->unique()->numerify('######'),
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'mobile' => fake()->numerify('##########'),
            'department_id' => Department::factory(),
            'designation_id' => Designation::factory(),
            'location_id' => Location::factory(),
            'reports_to_id' => null,
            'date_of_joining' => fake()->dateTimeBetween('-3 years', 'now'),
            'status' => EmployeeStatus::Active,
        ];
    }

    public function reportingTo(Employee $manager): static
    {
        return $this->state(fn (array $attributes): array => [
            'reports_to_id' => $manager->id,
        ]);
    }
}
