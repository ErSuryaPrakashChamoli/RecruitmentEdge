<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Designation;
use App\Models\Location;
use Illuminate\Database\Seeder;

/**
 * Baseline organizational reference data so Administration screens and demo employees have
 * something real to select from. Not exhaustive — HR can add more from Administration.
 */
class OrganizationSeeder extends Seeder
{
    public function run(): void
    {
        Location::query()->firstOrCreate(
            ['code' => 'HO'],
            ['name' => 'Head Office', 'city' => 'Bengaluru', 'state' => 'Karnataka', 'country' => 'India', 'is_active' => true],
        );

        $department = Department::query()->firstOrCreate(
            ['code' => 'TA'],
            ['name' => 'Talent Acquisition', 'is_active' => true],
        );

        $designations = [
            'CHRO' => 'DSG-CHRO',
            'VP - HR' => 'DSG-VPHR',
            'Recruitment Manager' => 'DSG-MGR',
            'Assistant Manager - Recruitment' => 'DSG-AM',
            'Recruiter' => 'DSG-RCT',
        ];

        foreach ($designations as $name => $code) {
            Designation::query()->firstOrCreate(
                ['code' => $code],
                ['name' => $name, 'department_id' => $department->id, 'is_active' => true],
            );
        }
    }
}
