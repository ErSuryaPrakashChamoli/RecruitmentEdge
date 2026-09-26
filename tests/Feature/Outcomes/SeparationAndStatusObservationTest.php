<?php

use App\Enums\EmployeeStatus;
use App\Enums\OutcomeCaptureMode;
use App\Enums\OutcomeConfidence;
use App\Enums\OutcomeResult;
use App\Enums\OutcomeType;
use App\Enums\SeparationReason;
use App\Filament\Resources\EmployeeSeparations\Pages\CreateEmployeeSeparation;
use App\Filament\Resources\EmployeeSeparations\Pages\EditEmployeeSeparation;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeSeparation;
use App\Models\HiringOutcome;
use App\Models\HiringOutcomeSnapshot;
use App\Models\User;
use App\Services\Outcomes\OutcomeEvaluator;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->employee = Employee::factory()->create(['status' => EmployeeStatus::Active]);
});

function statusObservationSnapshot(Employee $employee, int $joinedDaysAgo, ?int $capturedDaysAgo = null): HiringOutcomeSnapshot
{
    return HiringOutcomeSnapshot::factory()->create([
        'employee_id' => $employee->id,
        'joined_on' => now()->subDays($joinedDaysAgo)->toDateString(),
        'captured_at' => now()->subDays($capturedDaysAgo ?? $joinedDaysAgo),
    ]);
}

function statusObservationOf(HiringOutcomeSnapshot $snapshot, OutcomeType $type): ?HiringOutcome
{
    return HiringOutcome::query()->where('hiring_outcome_snapshot_id', $snapshot->id)->where('outcome_type', $type)->current()->first();
}

test('a due checkpoint records the status observed that day, with medium confidence, and later ones wait', function (): void {
    $snapshot = statusObservationSnapshot($this->employee, 32);

    app(OutcomeEvaluator::class)->evaluate();
    $observed = statusObservationOf($snapshot, OutcomeType::StatusObserved30d);

    expect($observed->result)->toBe(OutcomeResult::Active)
        ->and($observed->confidence)->toBe(OutcomeConfidence::Medium)
        ->and($observed->details['method'])->toBe('employee_status_observation')
        ->and($observed->observation_end->toDateString())->toBe(now()->subDays(2)->toDateString())
        ->and(OutcomeType::StatusObserved30d->label())->toBe('30-day status observed')
        ->and(statusObservationOf($snapshot, OutcomeType::StatusObserved90d))->toBeNull();
});

test('an observation made well after its checkpoint has low confidence, and inactive is not treated as an exit', function (): void {
    $this->employee->update(['status' => EmployeeStatus::Inactive]);
    $snapshot = statusObservationSnapshot($this->employee, 60);

    app(OutcomeEvaluator::class)->evaluate();
    $observed = statusObservationOf($snapshot, OutcomeType::StatusObserved30d);

    expect($observed->result)->toBe(OutcomeResult::Inactive)
        ->and($observed->confidence)->toBe(OutcomeConfidence::Low)
        ->and($observed->result->label())->toContain('not confirmed as an exit');
});

test('a separation before a checkpoint marks that checkpoint, and later checkpoints are not observed', function (): void {
    $snapshot = statusObservationSnapshot($this->employee, 200);
    EmployeeSeparation::factory()->create(['employee_id' => $this->employee->id, 'separation_date' => now()->subDays(190)->toDateString()]);

    app(OutcomeEvaluator::class)->evaluate();

    expect(statusObservationOf($snapshot, OutcomeType::StatusObserved30d))
        ->result->toBe(OutcomeResult::SeparatedBeforeCheckpoint)
        ->confidence->toBe(OutcomeConfidence::High)
        ->and(statusObservationOf($snapshot, OutcomeType::StatusObserved90d)->result)->toBe(OutcomeResult::NotObserved)
        ->and(statusObservationOf($snapshot, OutcomeType::StatusObserved90d)->details['reason'])->toBe('separated_before_earlier_checkpoint')
        ->and(statusObservationOf($snapshot, OutcomeType::StatusObserved180d)->result)->toBe(OutcomeResult::NotObserved);
});

test('a separation recorded later supersedes an earlier active observation, keeping the history', function (): void {
    $snapshot = statusObservationSnapshot($this->employee, 95);
    app(OutcomeEvaluator::class)->evaluate();
    $before = statusObservationOf($snapshot, OutcomeType::StatusObserved90d);

    EmployeeSeparation::factory()->create(['employee_id' => $this->employee->id, 'separation_date' => now()->subDays(40)->toDateString()]);
    $after = statusObservationOf($snapshot, OutcomeType::StatusObserved90d);

    expect($before->result)->toBe(OutcomeResult::Active)
        ->and($after->result)->toBe(OutcomeResult::SeparatedBeforeCheckpoint)
        ->and($after->supersedes_id)->toBe($before->id)
        ->and($before->fresh()->is_current)->toBeFalse()
        ->and(statusObservationOf($snapshot, OutcomeType::StatusObserved30d)->result)->toBe(OutcomeResult::Active);
});

test('no retention is inferred for checkpoints that passed before observation began, or without an employee record', function (): void {
    $backfilled = statusObservationSnapshot($this->employee, 400, capturedDaysAgo: 1);
    $noEmployee = HiringOutcomeSnapshot::factory()->create(['joined_on' => now()->subDays(40)->toDateString(), 'captured_at' => now()->subDays(40)]);

    app(OutcomeEvaluator::class)->evaluate();

    expect(statusObservationOf($backfilled, OutcomeType::StatusObserved180d)->result)->toBe(OutcomeResult::NotObserved)
        ->and(statusObservationOf($backfilled, OutcomeType::StatusObserved180d)->details['reason'])->toBe('checkpoint_before_observation_started')
        ->and(statusObservationOf($noEmployee, OutcomeType::StatusObserved30d)->details['reason'])->toBe('no_employee_record');
});

test('historical audit-log status changes are never used as retention evidence', function (): void {
    AuditLog::query()->create(['auditable_type' => Employee::class, 'auditable_id' => $this->employee->id, 'action' => 'updated', 'old_values' => ['status' => 'active'], 'changes' => ['status' => 'inactive']]);
    $snapshot = statusObservationSnapshot($this->employee, 400, capturedDaysAgo: 1);

    app(OutcomeEvaluator::class)->evaluate();

    expect(HiringOutcome::query()->where('hiring_outcome_snapshot_id', $snapshot->id)->where('category', 'retention')->pluck('result')->unique()->values()->all())->toBe([OutcomeResult::NotObserved])
        ->and(HiringOutcome::query()->where('source_type', (new AuditLog)->getMorphClass())->exists())->toBeFalse();
});

test('evaluating twice records no duplicate observations', function (): void {
    statusObservationSnapshot($this->employee, 200);
    app(OutcomeEvaluator::class)->evaluate();
    $first = HiringOutcome::query()->orderBy('id')->pluck('id')->all();

    app(OutcomeEvaluator::class)->evaluate();

    expect(HiringOutcome::query()->orderBy('id')->pluck('id')->all())->toBe($first)
        ->and(HiringOutcome::query()->where('capture_mode', OutcomeCaptureMode::BackfilledDeterministic)->count())->toBe(0);
});

test('HR records and corrects a separation; every change is audited without the notes', function (): void {
    $hr = User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole('chro');
    actingAs($hr);

    Livewire::test(CreateEmployeeSeparation::class)
        ->fillForm(['employee_id' => $this->employee->id, 'separation_date' => now()->subDay()->toDateString(), 'separation_reason' => 'resignation', 'notes' => 'PRIVATE-SEPARATION-NOTE'])
        ->call('create')->assertHasNoFormErrors();
    $separation = EmployeeSeparation::query()->sole();

    Livewire::test(EditEmployeeSeparation::class, ['record' => $separation->id])
        ->fillForm(['separation_reason' => 'contract_end'])
        ->call('save')->assertHasNoFormErrors();

    $audits = AuditLog::query()->where('auditable_type', EmployeeSeparation::class)->orderBy('id')->get();

    expect($separation->created_by)->toBe($hr->id)
        ->and($separation->fresh()->separation_reason)->toBe(SeparationReason::ContractEnd)
        ->and($separation->fresh()->notes)->toBe('PRIVATE-SEPARATION-NOTE')
        ->and($audits->pluck('action')->all())->toBe(['created', 'updated'])
        ->and($audits->last()->old_values['separation_reason'])->toBe('resignation')
        ->and($audits->last()->changes['separation_reason'])->toBe('contract_end')
        ->and(json_encode($audits->toArray()))->not->toContain('PRIVATE-SEPARATION-NOTE');
});

test('separation access follows permissions and the hierarchy', function (): void {
    $manager = Employee::factory()->create();
    $managerUser = User::factory()->create(['employee_id' => $manager->id])->assignRole('manager');
    $team = Employee::factory()->reportingTo($manager)->create();
    $teamSeparation = EmployeeSeparation::factory()->create(['employee_id' => $team->id]);
    $otherSeparation = EmployeeSeparation::factory()->create();
    $recruiter = User::factory()->create(['employee_id' => Employee::factory()->reportingTo($manager)->create()->id])->assignRole('recruiter');

    actingAs($recruiter);
    get('/admin/employee-separations')->assertForbidden();

    actingAs($managerUser);
    get('/admin/employee-separations')->assertOk();
    get("/admin/employee-separations/{$otherSeparation->id}/edit")->assertNotFound();
    get("/admin/employee-separations/{$teamSeparation->id}/edit")->assertForbidden();
    get('/admin/employee-separations/create')->assertForbidden();
});

test('a tampered employee id outside the hierarchy cannot be given a separation', function (): void {
    $hrEmployee = Employee::factory()->create();
    $vp = User::factory()->create(['employee_id' => $hrEmployee->id])->assignRole('vp_hr');
    $outsider = Employee::factory()->create();
    actingAs($vp);

    Livewire::test(CreateEmployeeSeparation::class)
        ->set('data.employee_id', $outsider->id)
        ->set('data.separation_date', now()->subDay()->toDateString())
        ->set('data.separation_reason', 'resignation')
        ->call('create');

    expect(EmployeeSeparation::query()->where('employee_id', $outsider->id)->exists())->toBeFalse();
});

test('the separation view page shows the record, including its notes, read-only', function (): void {
    $separation = EmployeeSeparation::factory()->create(['notes' => 'Handover complete']);
    actingAs(User::factory()->create(['employee_id' => Employee::factory()->create()->id])->assignRole('chro'));

    get("/admin/employee-separations/{$separation->id}")->assertOk()->assertSee('Handover complete');
});
