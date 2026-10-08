<?php

namespace Database\Factories;

use App\Enums\SeparationReason;
use App\Models\Employee;
use App\Models\EmployeeSeparation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EmployeeSeparation>
 */
class EmployeeSeparationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'employee_id' => Employee::factory(),
            'separation_date' => now()->subDays(5)->toDateString(),
            'separation_reason' => SeparationReason::Resignation,
        ];
    }
}
