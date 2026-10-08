<?php

namespace Database\Seeders;

use App\Enums\AccessState;
use App\Enums\EmployeeStatus;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\Location;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Database\Seeder;

/**
 * Creates the first CHRO account so the application has someone able to log in and configure
 * everything else (users, roles, hierarchy, settings) from a fresh install.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $location = Location::query()->where('code', 'HO')->firstOrFail();
        $department = Department::query()->where('code', 'TA')->firstOrFail();
        $designation = Designation::query()->where('code', 'DSG-CHRO')->firstOrFail();

        $employee = Employee::query()->firstOrCreate(
            ['employee_code' => 'EMP-000001'],
            [
                'first_name' => 'Fynn',
                'last_name' => 'Edge',
                'email' => 'fynnedge@gmail.com',
                'department_id' => $department->id,
                'designation_id' => $designation->id,
                'location_id' => $location->id,
                'reports_to_id' => null,
                'date_of_joining' => now(),
                'status' => EmployeeStatus::Active,
            ],
        );

        $user = User::query()->firstOrCreate(
            ['email' => 'fynnedge@gmail.com'],
            [
                'name' => 'Fynn Edge',
                'password' => 'password',
            ],
        );

        // SaaS-2: the identity is global; its access to this tenant and its employee record here
        // are its membership (without one the seeded CHRO could reach no tenant at all).
        TenantMembership::query()->firstOrCreate(
            ['tenant_id' => TenantContext::current()->requireId(), 'user_id' => $user->id],
            ['employee_id' => $employee->id, 'status' => AccessState::Active, 'is_default' => true, 'joined_at' => now()],
        );

        $user->assignRole('chro');

        $this->command?->warn('CHRO login seeded: fynnedge@gmail.com / password — change this password immediately after first login.');
    }
}
