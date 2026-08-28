<?php

namespace Database\Factories;

use App\Enums\EmploymentType;
use App\Enums\Priority;
use App\Enums\RequisitionStatus;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Location;
use App\Models\RecruitmentRequisition;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RecruitmentRequisition>
 */
class RecruitmentRequisitionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'code' => 'REQ-'.now()->year.'-'.fake()->unique()->numerify('######'),
            'department_id' => Department::factory(),
            'designation_id' => Designation::factory(),
            'location_id' => Location::factory(),
            'openings' => fake()->numberBetween(1, 10),
            'employment_type' => fake()->randomElement(EmploymentType::cases()),
            'salary_min' => fake()->numberBetween(300000, 600000),
            'salary_max' => fake()->numberBetween(600001, 1200000),
            'experience_min' => fake()->randomFloat(1, 0, 3),
            'experience_max' => fake()->randomFloat(1, 3, 10),
            'qualification' => fake()->randomElement(['Any Graduate', "Bachelor's Degree", "Master's Degree"]),
            'skills' => fake()->randomElements(['PHP', 'Laravel', 'Sales', 'Communication', 'Excel', 'SQL'], 3),
            'shift' => fake()->randomElement(['Day', 'Night', 'Rotational']),
            'priority' => fake()->randomElement(Priority::cases()),
            'target_joining_date' => fake()->dateTimeBetween('now', '+2 months'),
            'opening_date' => now(),
            'status' => RequisitionStatus::Draft,
        ];
    }
}
