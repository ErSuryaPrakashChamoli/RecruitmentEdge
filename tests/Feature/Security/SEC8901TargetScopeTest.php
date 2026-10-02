<?php

use App\Enums\TargetMetric;
use App\Enums\TargetPeriodType;
use App\Filament\Resources\RecruitmentDailyTargets\Pages\CreateRecruitmentDailyTarget;
use App\Filament\Resources\RecruitmentDailyTargets\Pages\EditRecruitmentDailyTarget;
use App\Filament\Resources\RecruitmentDailyTargets\Pages\ListRecruitmentDailyTargets;
use App\Filament\Resources\RecruitmentDailyTargets\RecruitmentDailyTargetResource;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Employee;
use App\Models\RecruitmentDailyTarget;
use App\Models\User;
use App\Services\RecruitmentTargetService;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\Testing\TestAction;
use Illuminate\Auth\Access\AuthorizationException;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

/**
 * Phase 8.9 (P89-SEC-001): managers and VP HR hold targets.configure but not hierarchy.view-all.
 * They may list and manage only recruiter targets inside their own hierarchy — never another
 * team's targets, nor department/designation targets — on every path: list, view, edit, delete,
 * bulk delete, create, and RecruitmentTargetService itself.
 */
beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);

    $this->managerEmployee = Employee::factory()->create();
    $this->ownRecruiter = Employee::factory()->create(['reports_to_id' => $this->managerEmployee->id]);
    $this->otherRecruiter = Employee::factory()->create();
    $this->manager = User::factory()->create(['employee_id' => $this->managerEmployee->id])->assignRole('manager');

    $this->ownTarget = RecruitmentDailyTarget::factory()->create(['employee_id' => $this->ownRecruiter->id, 'metric' => TargetMetric::Calls]);
    $this->otherTarget = RecruitmentDailyTarget::factory()->create(['employee_id' => $this->otherRecruiter->id, 'metric' => TargetMetric::Calls]);
    $this->departmentTarget = RecruitmentDailyTarget::factory()->create(['employee_id' => null, 'department_id' => Department::factory()->create()->id, 'metric' => TargetMetric::Calls]);
    $this->designationTarget = RecruitmentDailyTarget::factory()->create(['employee_id' => null, 'designation_id' => Designation::factory()->create()->id, 'metric' => TargetMetric::Calls]);
});

test('a manager lists only recruiter targets in their own hierarchy', function (): void {
    actingAs($this->manager);

    Livewire::test(ListRecruitmentDailyTargets::class)
        ->assertCanSeeTableRecords([$this->ownTarget])
        ->assertCanNotSeeTableRecords([$this->otherTarget, $this->departmentTarget, $this->designationTarget]);
});

test('vp hr without organisation-wide access is scoped the same way', function (): void {
    $vpEmployee = Employee::factory()->create();
    $teamRecruiter = Employee::factory()->create(['reports_to_id' => Employee::factory()->create(['reports_to_id' => $vpEmployee->id])->id]);
    $teamTarget = RecruitmentDailyTarget::factory()->create(['employee_id' => $teamRecruiter->id]);
    $vp = User::factory()->create(['employee_id' => $vpEmployee->id])->assignRole('vp_hr');

    expect($vp->can('hierarchy.view-all'))->toBeFalse()
        ->and($vp->can('targets.configure'))->toBeTrue()
        ->and(RecruitmentDailyTarget::query()->visibleTo($vp)->pluck('id')->all())->toBe([$teamTarget->id]);
});

test('the chro lists every target', function (): void {
    actingAs(User::factory()->create()->assignRole('chro'));

    Livewire::test(ListRecruitmentDailyTargets::class)
        ->assertCanSeeTableRecords([$this->ownTarget, $this->otherTarget, $this->departmentTarget, $this->designationTarget]);
});

test('a manager cannot open, edit or delete a target outside their scope', function (): void {
    actingAs($this->manager);

    get(RecruitmentDailyTargetResource::getUrl('view', ['record' => $this->otherTarget]))->assertNotFound();
    get(RecruitmentDailyTargetResource::getUrl('edit', ['record' => $this->otherTarget]))->assertNotFound();
    get(RecruitmentDailyTargetResource::getUrl('edit', ['record' => $this->departmentTarget]))->assertNotFound();

    expect($this->manager->can('view', $this->otherTarget))->toBeFalse()
        ->and($this->manager->can('update', $this->departmentTarget))->toBeFalse()
        ->and($this->manager->can('delete', $this->designationTarget))->toBeFalse();
});

test('a manager bulk delete removes only targets in their scope', function (): void {
    actingAs($this->manager);

    Livewire::test(ListRecruitmentDailyTargets::class)
        ->selectTableRecords([$this->ownTarget, $this->otherTarget, $this->departmentTarget])
        ->callAction(TestAction::make(DeleteBulkAction::getDefaultName())->table()->bulk());

    expect(RecruitmentDailyTarget::query()->whereKey($this->ownTarget->id)->exists())->toBeFalse()
        ->and(RecruitmentDailyTarget::query()->whereKey($this->otherTarget->id)->exists())->toBeTrue()
        ->and(RecruitmentDailyTarget::query()->whereKey($this->departmentTarget->id)->exists())->toBeTrue();
});

test('the service refuses to delete targets outside the actor scope even when handed them', function (): void {
    $deleted = app(RecruitmentTargetService::class)->deleteMany($this->manager, [$this->ownTarget, $this->otherTarget, $this->departmentTarget, $this->designationTarget]);

    expect($deleted)->toBe(1)
        ->and(RecruitmentDailyTarget::query()->pluck('id')->all())->toEqualCanonicalizing([$this->otherTarget->id, $this->departmentTarget->id, $this->designationTarget->id]);

    expect(fn () => app(RecruitmentTargetService::class)->delete($this->manager, $this->otherTarget))->toThrow(AuthorizationException::class);
});

test('the service refuses to create or move a target outside the actor scope', function (Closure $scope): void {
    $attributes = [...$scope($this), 'metric' => TargetMetric::Offers, 'period_type' => TargetPeriodType::Monthly, 'target_value' => 5, 'effective_from' => now()->toDateString()];

    expect(fn () => app(RecruitmentTargetService::class)->create($this->manager, $attributes))->toThrow(AuthorizationException::class)
        ->and(fn () => app(RecruitmentTargetService::class)->update($this->manager, $this->ownTarget, ['employee_id' => null, ...$scope($this)]))->toThrow(AuthorizationException::class)
        ->and($this->ownTarget->fresh()->employee_id)->toBe($this->ownRecruiter->id);
})->with([
    'another team recruiter' => [fn ($test): array => ['employee_id' => $test->otherRecruiter->id]],
    'a department' => [fn ($test): array => ['department_id' => Department::factory()->create()->id]],
    'a designation' => [fn ($test): array => ['designation_id' => Designation::factory()->create()->id]],
]);

test('a manager can still create, edit and delete targets for their own recruiters', function (): void {
    actingAs($this->manager);

    Livewire::test(CreateRecruitmentDailyTarget::class)
        ->fillForm([
            'employee_id' => $this->ownRecruiter->id,
            'metric' => TargetMetric::Offers->value,
            'period_type' => TargetPeriodType::Monthly->value,
            'target_value' => 4,
            'effective_from' => now()->toDateString(),
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $created = RecruitmentDailyTarget::query()->where('metric', TargetMetric::Offers)->sole();
    expect($created->created_by)->toBe($this->managerEmployee->id);

    Livewire::test(EditRecruitmentDailyTarget::class, ['record' => $created->getRouteKey()])
        ->fillForm(['target_value' => 6])
        ->call('save')
        ->assertHasNoFormErrors()
        ->callAction(DeleteAction::class);

    expect(RecruitmentDailyTarget::query()->whereKey($created->id)->exists())->toBeFalse();
});

test('the recruiter picker offers only the manager team and hides group scopes', function (): void {
    actingAs($this->manager);

    Livewire::test(CreateRecruitmentDailyTarget::class)
        ->assertFormFieldIsHidden('department_id')
        ->assertFormFieldIsHidden('designation_id')
        ->fillForm([
            'employee_id' => $this->otherRecruiter->id,
            'metric' => TargetMetric::Offers->value,
            'period_type' => TargetPeriodType::Monthly->value,
            'target_value' => 4,
            'effective_from' => now()->toDateString(),
        ])
        ->call('create')
        ->assertHasFormErrors(['employee_id']);

    expect(RecruitmentDailyTarget::query()->where('metric', TargetMetric::Offers)->exists())->toBeFalse();
});
