<?php

use App\Filament\Pages\RecruitmentReports;
use App\Models\Employee;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
});

test('the recruitment reports page renders successfully with no data', function (): void {
    $user = User::factory()->create();
    $user->assignRole('chro');

    actingAs($user)
        ->get('/admin/recruitment-reports')
        ->assertSuccessful()
        ->assertSee('No open or on-hold requisitions');
});

test('the recruitment reports page renders successfully for a scoped recruiter', function (): void {
    $recruiter = Employee::factory()->create();
    $user = User::factory()->create(['employee_id' => $recruiter->id]);
    $user->assignRole('recruiter');

    actingAs($user)->get('/admin/recruitment-reports')->assertSuccessful();
});

test('a user with reports.export can export the funnel and vacancy ageing as CSV', function (): void {
    $user = User::factory()->create();
    $user->assignRole('chro');
    actingAs($user);

    Livewire::test(RecruitmentReports::class)
        ->call('exportFunnel')
        ->assertFileDownloaded('recruitment-funnel.csv');

    Livewire::test(RecruitmentReports::class)
        ->call('exportVacancyAgeing')
        ->assertFileDownloaded('vacancy-ageing.csv');

    Livewire::test(RecruitmentReports::class)
        ->call('exportSourceRoi')
        ->assertFileDownloaded('source-roi.csv');
});

test('a user without reports.export cannot export', function (): void {
    Role::findOrCreate('no-export')->syncPermissions(['performance.view']);

    $user = User::factory()->create();
    $user->assignRole('no-export');
    actingAs($user);

    Livewire::test(RecruitmentReports::class)
        ->call('exportFunnel')
        ->assertStatus(403);
});
