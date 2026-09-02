<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Deliberately does NOT use WithoutModelEvents: EmployeeObserver relies on Eloquent's
     * created/updated events firing to keep the hierarchy closure table in sync.
     */
    public function run(): void
    {
        $this->call([
            RolePermissionSeeder::class,
            OrganizationSeeder::class,
            AdminUserSeeder::class,
            RecruitmentReferenceDataSeeder::class,
            AiEvaluationSeeder::class,
        ]);
    }
}
