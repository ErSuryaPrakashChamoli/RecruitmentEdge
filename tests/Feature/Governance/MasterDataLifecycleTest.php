<?php

use App\Enums\RequisitionStatus;
use App\Filament\Resources\Departments\Pages\EditDepartment;
use App\Filament\Resources\Departments\Pages\ListDepartments;
use App\Filament\Resources\RecruitmentRequisitions\Pages\EditRecruitmentRequisition;
use App\Models\AuditLog;
use App\Models\CandidateSource;
use App\Models\Department;
use App\Models\Designation;
use App\Models\Location;
use App\Models\RecruitmentCost;
use App\Models\RecruitmentDailyTarget;
use App\Models\RecruitmentIncentiveRule;
use App\Models\RecruitmentRejectionReason;
use App\Models\RecruitmentRequisition;
use App\Models\User;
use App\Services\MasterDataLifecycleService;
use Database\Seeders\RecruitmentReferenceDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Forms\Components\Select;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * Phase 8.6 master-data lifecycle (D8.6-001…007): Active → Inactive → Archived with a reason,
 * never a permanent delete, archive refused while open work uses the record, history keeps its
 * labels, and inactive records can't be taken up by new work.
 */
beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->admin = User::factory()->create()->assignRole('chro');
    actingAs($this->admin);
    $this->lifecycle = app(MasterDataLifecycleService::class);
});

function masterDataRequisition(array $attributes = [], RequisitionStatus $status = RequisitionStatus::Open): RecruitmentRequisition
{
    return lifecycleFixture(fn () => RecruitmentRequisition::factory()->create([...$attributes, 'status' => $status]));
}

test('FAILURE 1: a department can never be permanently deleted, so its dependants survive', function (): void {
    $department = Department::factory()->create();
    $cost = RecruitmentCost::factory()->create(['department_id' => $department->id]);
    $rule = RecruitmentIncentiveRule::factory()->create(['department_id' => $department->id]);
    $target = RecruitmentDailyTarget::factory()->create(['department_id' => $department->id, 'employee_id' => null, 'designation_id' => null]);

    expect(fn () => $department->forceDelete())->toThrow(DomainException::class)
        ->and($this->admin->can('forceDelete', $department))->toBeFalse()
        ->and($this->admin->can('forceDeleteAny', Department::class))->toBeFalse()
        ->and(RecruitmentCost::query()->whereKey($cost->id)->exists())->toBeTrue()
        ->and(RecruitmentIncentiveRule::query()->whereKey($rule->id)->exists())->toBeTrue()
        ->and(RecruitmentDailyTarget::query()->whereKey($target->id)->exists())->toBeTrue()
        ->and(Department::withTrashed()->whereKey($department->id)->exists())->toBeTrue();

    Livewire::test(EditDepartment::class, ['record' => $department->getRouteKey()])
        ->assertActionDoesNotExist(ForceDeleteAction::class)
        ->assertActionDoesNotExist(DeleteAction::class);
});

test('every governed master-data type refuses a permanent delete', function (string $class): void {
    $record = $class::factory()->create();
    $record->delete();

    expect(fn () => $record->forceDelete())->toThrow(DomainException::class)
        ->and($class::withTrashed()->whereKey($record->id)->exists())->toBeTrue();
})->with([Department::class, Designation::class, Location::class, CandidateSource::class, RecruitmentRejectionReason::class]);

test('archiving is refused while open work uses the record and the refusal says what', function (): void {
    $department = Department::factory()->create();
    masterDataRequisition(['department_id' => $department->id]);
    RecruitmentIncentiveRule::factory()->create(['department_id' => $department->id, 'is_active' => true, 'effective_to' => null]);

    expect(fn () => $this->lifecycle->archive($this->admin, $department, 'Merged into Sales'))
        ->toThrow(DomainException::class, '1 open requisitions, 1 active incentive rules');

    expect($department->fresh()->trashed())->toBeFalse();
});

test('closed work never blocks archiving, and an archive is audited with actor and reason', function (): void {
    $department = Department::factory()->create();
    masterDataRequisition(['department_id' => $department->id], RequisitionStatus::Closed);
    RecruitmentIncentiveRule::factory()->create(['department_id' => $department->id, 'effective_from' => now()->subMonths(3), 'effective_to' => now()->subMonth()]);

    $this->lifecycle->archive($this->admin, $department, 'Business unit closed');

    $rows = AuditLog::query()->where('auditable_type', Department::class)->where('auditable_id', $department->id)->whereIn('action', ['updated', 'archived'])->get();

    expect(Department::query()->find($department->id))->toBeNull()
        ->and(Department::withTrashed()->find($department->id)->is_active)->toBeFalse()
        ->and($rows->pluck('action')->all())->toBe(['updated', 'archived'])
        ->and($rows->pluck('reason')->unique()->all())->toBe(['Business unit closed'])
        ->and($rows->pluck('user_id')->unique()->all())->toBe([$this->admin->id]);
});

test('deactivate, archive and restore need a reason; restore returns the record as inactive', function (): void {
    $location = Location::factory()->create();

    expect(fn () => $this->lifecycle->deactivate($this->admin, $location, '  '))->toThrow(DomainException::class, 'reason');
    expect(fn () => $this->lifecycle->archive($this->admin, $location, null))->toThrow(DomainException::class, 'reason');

    $this->lifecycle->archive($this->admin, $location, 'Office closed');
    expect(fn () => $this->lifecycle->restore($this->admin, Location::withTrashed()->find($location->id), ''))->toThrow(DomainException::class, 'reason');

    $restored = $this->lifecycle->restore($this->admin, Location::withTrashed()->find($location->id), 'Office reopened');

    expect($restored->trashed())->toBeFalse()
        ->and($restored->is_active)->toBeFalse()
        ->and(AuditLog::query()->where('auditable_type', Location::class)->where('auditable_id', $location->id)->where('action', 'restored')->value('reason'))->toBe('Office reopened');
});

test('only settings.manage may change master data, whatever the path', function (): void {
    $manager = User::factory()->create()->assignRole('vp_hr');
    $source = CandidateSource::factory()->create();

    expect(fn () => $this->lifecycle->deactivate($manager, $source, 'try'))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->lifecycle->archive($manager, $source, 'try'))->toThrow(AuthorizationException::class)
        ->and($manager->can('deleteAny', CandidateSource::class))->toBeFalse()
        ->and($this->admin->can('deleteAny', CandidateSource::class))->toBeFalse()
        ->and($this->admin->can('restoreAny', CandidateSource::class))->toBeFalse();

    expect($source->fresh()->is_active)->toBeTrue();
});

test('the table archives through the lifecycle with a reason and offers no bulk delete', function (): void {
    $department = Department::factory()->create();

    Livewire::test(ListDepartments::class)
        ->assertTableBulkActionDoesNotExist('delete')
        ->assertTableBulkActionDoesNotExist('forceDelete')
        ->callTableAction('archiveMasterData', $department, data: ['reason' => 'Obsolete'])
        ->assertHasNoTableActionErrors();

    expect(Department::withTrashed()->find($department->id)->trashed())->toBeTrue();
});

test('a code cannot change once created', function (): void {
    $designation = Designation::factory()->create(['code' => 'DES-1']);

    expect(fn () => $designation->update(['code' => 'DES-2']))->toThrow(DomainException::class)
        ->and($designation->fresh()->code)->toBe('DES-1');

    $designation->fresh()->update(['name' => 'Renamed']);
    expect($designation->fresh()->name)->toBe('Renamed');
});

test('history keeps the label of archived master data', function (): void {
    $requisition = masterDataRequisition([], RequisitionStatus::Closed);
    $department = $requisition->department;

    $this->lifecycle->archive($this->admin, $department, 'Reorganised');

    $fresh = $requisition->fresh();

    expect($fresh->department?->name)->toBe($department->name)
        ->and($fresh->department->displayName())->toBe("{$department->name} (archived)");
});

test('inactive or archived master data cannot be taken up by new records, but history stays valid', function (): void {
    $requisition = masterDataRequisition();
    $inactive = Department::factory()->create(['is_active' => false]);

    expect(fn () => masterDataRequisition(['department_id' => $inactive->id]))->toThrow(ValidationException::class)
        ->and(fn () => $requisition->update(['department_id' => $inactive->id]))->toThrow(ValidationException::class);

    // The requisition's own department is deactivated: its other fields can still be edited.
    $this->lifecycle->deactivate($this->admin, $requisition->department, 'Being wound down');
    $requisition->fresh()->update(['openings' => 7]);

    expect($requisition->fresh()->openings)->toBe(7);
});

test('the requisition form lists only active departments plus the one the record already has', function (): void {
    $requisition = masterDataRequisition();
    $active = Department::factory()->create(['name' => 'Active Dept']);
    Department::factory()->create(['name' => 'Inactive Dept', 'is_active' => false]);
    $this->lifecycle->deactivate($this->admin, $requisition->department, 'Winding down');

    Livewire::test(EditRecruitmentRequisition::class, ['record' => $requisition->getRouteKey()])
        ->assertFormFieldExists('department_id', fn (Select $field): bool => array_key_exists($active->id, $field->getOptions())
            && array_key_exists($requisition->department_id, $field->getOptions())
            && ! in_array('Inactive Dept', $field->getOptions(), true));
});

test('the sources the application relies on cannot be deactivated or archived', function (): void {
    $website = CandidateSource::factory()->create(['code' => CandidateSource::CODE_WEBSITE, 'name' => 'Website']);

    expect(fn () => $this->lifecycle->deactivate($this->admin, $website, 'x'))->toThrow(DomainException::class, 'career site')
        ->and(fn () => $this->lifecycle->archive($this->admin, $website, 'x'))->toThrow(DomainException::class, 'career site');
});

test('re-running the reference seeder never recreates or restores an archived source', function (): void {
    $this->seed(RecruitmentReferenceDataSeeder::class);
    $agency = CandidateSource::query()->where('code', 'SRC-012')->sole();
    $this->lifecycle->archive($this->admin, $agency, 'No agencies any more');

    $this->seed(RecruitmentReferenceDataSeeder::class);

    expect(CandidateSource::withTrashed()->where('code', 'SRC-012')->count())->toBe(1)
        ->and(CandidateSource::withTrashed()->where('code', 'SRC-012')->sole()->trashed())->toBeTrue();
});
