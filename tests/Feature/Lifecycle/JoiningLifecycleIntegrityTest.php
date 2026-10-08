<?php

use App\Enums\JoiningStatus;
use App\Filament\Resources\CandidateJoinings\Pages\ListCandidateJoinings;
use App\Models\AuditLog;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\Employee;
use App\Models\User;
use App\Services\CandidateJoiningService;
use App\Services\EmployeeConversionService;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->vpHr = Employee::factory()->create();
    $this->vpHrUser = User::factory()->create(['employee_id' => $this->vpHr->id])->assignRole('vp_hr');
    $this->recruiter = Employee::factory()->reportingTo($this->vpHr)->create();
    $this->joining = CandidateJoining::factory()->create([
        'candidate_application_id' => CandidateApplication::factory()->create(['recruiter_id' => $this->recruiter->id])->id,
        'status' => JoiningStatus::Joined,
        'actual_doj' => now()->subDay(),
    ]);
    $this->conversion = app(EmployeeConversionService::class);
});

test('a joining\'s status, dates of record and links cannot be written outside CandidateJoiningService', function (string $attribute, Closure $value): void {
    expect(fn () => $this->joining->update([$attribute => $value->call($this)]))->toThrow(LogicException::class, 'CandidateJoiningService');
})->with([
    'status' => ['status', fn () => JoiningStatus::Dropout],
    'actual joining date' => ['actual_doj', fn () => now()->subWeek()],
    'offer' => ['offer_id', fn () => null],
]);

test('a pending joining can be cancelled with a reason; a completed join cannot', function (): void {
    $pending = CandidateJoining::factory()->create(['status' => JoiningStatus::Expected]);
    $service = app(CandidateJoiningService::class);

    expect(fn () => $service->cancel($pending, ' '))->toThrow(DomainException::class, 'reason');

    $service->cancel($pending, 'Application rejected after reference check', $this->vpHr);

    expect($pending->fresh()->status)->toBe(JoiningStatus::Cancelled)
        ->and($pending->fresh()->remarks)->toContain('Application rejected after reference check')
        ->and(AuditLog::query()->where('auditable_id', $pending->id)->where('action', 'updated')->latest('id')->first()->changes['status'])->toBe('cancelled')
        ->and(fn () => $service->cancel($this->joining, 'x'))->toThrow(DomainException::class, 'already Joined');
});

test('conversion needs employees.convert — users.manage or joining access alone is not enough', function (): void {
    $role = Role::findOrCreate('user_admin_only');
    $role->givePermissionTo(['users.manage', 'joining.confirm', 'hierarchy.view-all']);
    $userAdmin = User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole('user_admin_only');
    $manager = User::factory()->create(['employee_id' => Employee::factory()->reportingTo($this->vpHr)->create()->id])->assignRole('manager');

    expect(fn () => $this->conversion->convert($this->joining, $userAdmin))->toThrow(DomainException::class, 'employees.convert')
        ->and(fn () => $this->conversion->convert($this->joining, $manager))->toThrow(DomainException::class, 'employees.convert');
});

test('an authorised converter in scope converts once, audited; outside the hierarchy it is refused', function (): void {
    $outsider = User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole('vp_hr');

    expect(fn () => $this->conversion->convert($this->joining, $outsider))->toThrow(DomainException::class, 'employees.convert');

    $employee = $this->conversion->convert($this->joining, $this->vpHrUser, $this->vpHr->id);

    expect(AuditLog::query()->where('action', 'employee_converted')->sole()->changes)->toMatchArray(['employee_id' => $employee->id, 'by_user_id' => $this->vpHrUser->id])
        ->and(fn () => $this->conversion->convert($this->joining->fresh(), $this->vpHrUser, $this->vpHr->id))->toThrow(DomainException::class, 'already been converted')
        ->and(Employee::query()->where('candidate_id', $this->joining->candidateApplication->candidate_id)->count())->toBe(1);
});

test('the convert action is only offered to users with employees.convert', function (): void {
    $managerEmployee = Employee::factory()->reportingTo($this->vpHr)->create();
    lifecycleFixture(fn () => $this->recruiter->update(['reports_to_id' => $managerEmployee->id]));
    actingAs(User::factory()->create(['employee_id' => $managerEmployee->id])->assignRole('manager'));
    Livewire::test(ListCandidateJoinings::class)->assertCanSeeTableRecords([$this->joining])->assertActionHidden(TestAction::make('convertToEmployee')->table($this->joining));

    actingAs($this->vpHrUser);
    Livewire::test(ListCandidateJoinings::class)->assertActionVisible(TestAction::make('convertToEmployee')->table($this->joining));
});

test('the reporting hierarchy cannot form a cycle', function (): void {
    $lead = Employee::factory()->reportingTo($this->vpHr)->create();
    $member = Employee::factory()->reportingTo($lead)->create();

    // Phase 8.4: the model-level backstop behind HierarchyIntegrityService (which checks first).
    expect(fn () => lifecycleFixture(fn () => $this->vpHr->update(['reports_to_id' => $member->id])))->toThrow(DomainException::class, 'own reporting line')
        ->and(fn () => lifecycleFixture(fn () => $lead->update(['reports_to_id' => $lead->id])))->toThrow(DomainException::class, 'own reporting line')
        ->and($this->vpHr->fresh()->reports_to_id)->toBeNull();

    lifecycleFixture(fn () => $member->update(['reports_to_id' => $this->vpHr->id]));

    expect($member->fresh()->reports_to_id)->toBe($this->vpHr->id);
});
