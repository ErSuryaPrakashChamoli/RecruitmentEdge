<?php

use App\Enums\TargetMetric;
use App\Enums\TargetPeriodType;
use App\Filament\Resources\RecruitmentDailyTargets\Pages\CreateRecruitmentDailyTarget;
use App\Models\Department;
use App\Models\Employee;
use App\Models\RecruitmentDailyTarget;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);

    $user = User::factory()->create();
    $user->assignRole('chro');
    actingAs($user);
});

test('a weekly target can be created for a single scope', function (): void {
    $recruiter = Employee::factory()->create();

    Livewire::test(CreateRecruitmentDailyTarget::class)
        ->fillForm([
            'employee_id' => $recruiter->id,
            'metric' => TargetMetric::Calls->value,
            'period_type' => TargetPeriodType::Weekly->value,
            'target_value' => 150,
            'effective_from' => now()->toDateString(),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(RecruitmentDailyTarget::query()->sole())
        ->employee_id->toBe($recruiter->id)
        ->period_type->toBe(TargetPeriodType::Weekly);
});

test('the form rejects a target with more than one scope or no scope', function (Closure $scope, array $erroredFields): void {
    Livewire::test(CreateRecruitmentDailyTarget::class)
        ->fillForm([
            ...$scope(),
            'metric' => TargetMetric::Calls->value,
            'period_type' => TargetPeriodType::Daily->value,
            'target_value' => 10,
            'effective_from' => now()->toDateString(),
        ])
        ->call('create')
        ->assertHasFormErrors($erroredFields);

    expect(RecruitmentDailyTarget::query()->count())->toBe(0);
})->with([
    'two scopes' => [fn (): array => ['employee_id' => Employee::factory()->create()->id, 'department_id' => Department::factory()->create()->id], ['employee_id', 'department_id']],
    'no scope' => [fn (): array => [], ['employee_id', 'department_id', 'designation_id']],
]);
