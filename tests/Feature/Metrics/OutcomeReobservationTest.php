<?php

use App\Enums\OutcomeResult;
use App\Enums\OutcomeState;
use App\Enums\OutcomeType;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeSeparation;
use App\Models\HiringOutcome;
use App\Models\HiringOutcomeSnapshot;
use App\Models\User;
use App\Services\Identity\EmployeeLifecycleService;
use App\Services\Outcomes\OutcomeCalculator;
use App\Services\Outcomes\OutcomeEvaluator;
use App\Services\Outcomes\OutcomeService;
use Database\Seeders\RolePermissionSeeder;

/**
 * Phase 8.5 (DF-11, D44): the narrow Outcome Loop correction — a checkpoint voided because the
 * separation it cited was cancelled is observed again as a new version; nothing else changes.
 */
beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $chroEmployee = Employee::factory()->create();
    $this->vpEmployee = Employee::factory()->reportingTo($chroEmployee)->create();
    $this->vp = User::factory()->create(['employee_id' => $this->vpEmployee->id])->assignRole('vp_hr');
    $this->employee = Employee::factory()->reportingTo($this->vpEmployee)->create();
    $this->snapshot = HiringOutcomeSnapshot::factory()->create(['employee_id' => $this->employee->id, 'joined_on' => now()->subDays(40)->toDateString(), 'captured_at' => now()->subDays(40)]);
    $this->lifecycle = app(EmployeeLifecycleService::class);
});

function reobservationCheckpoint(HiringOutcomeSnapshot $snapshot): HiringOutcome
{
    return HiringOutcome::query()->where('dedupe_key', "snapshot:{$snapshot->id}:".OutcomeType::StatusObserved30d->value)->current()->sole();
}

test('cancelling a separation that is not yet effective voids the outcomes that cited it and observes again', function (): void {
    // The 30-day checkpoint is due today and the last working day is today: the separation is
    // cited by the checkpoint but only takes effect tomorrow.
    $snapshot = HiringOutcomeSnapshot::factory()->create(['employee_id' => $this->employee->id, 'joined_on' => now()->subDays(30)->toDateString(), 'captured_at' => now()->subDays(30)]);
    $separation = $this->lifecycle->recordSeparation($this->employee, $this->vp, ['separation_date' => now()->toDateString(), 'separation_reason' => 'resignation']);
    $cited = reobservationCheckpoint($snapshot);

    expect($cited->result)->toBe(OutcomeResult::SeparatedBeforeCheckpoint)
        ->and($separation->fresh()->effective_applied_at)->toBeNull();

    $this->lifecycle->cancelSeparation($separation, $this->vp, 'Resignation withdrawn');

    $current = reobservationCheckpoint($snapshot);
    $void = HiringOutcome::query()->where('supersedes_id', $cited->id)->sole();

    expect($void->state)->toBe(OutcomeState::Void)
        ->and($current->supersedes_id)->toBe($void->id)
        ->and($current->result)->toBe(OutcomeResult::Active)
        ->and(AuditLog::query()->where('action', 'outcome_reobserved')->where('auditable_id', $current->id)->exists())->toBeTrue();
});

test('a checkpoint voided for any other reason is never re-observed', function (): void {
    app(OutcomeCalculator::class)->statusObservation($this->snapshot, OutcomeType::StatusObserved30d);
    $observed = reobservationCheckpoint($this->snapshot);

    $chro = User::factory()->create()->assignRole('chro');
    $void = app(OutcomeService::class)->void($observed, 'Recorded against the wrong employee', $chro);

    app(OutcomeEvaluator::class)->evaluateEmployee($this->employee->id);
    app(OutcomeEvaluator::class)->evaluate();

    expect(reobservationCheckpoint($this->snapshot)->id)->toBe($void->id)
        ->and(app(OutcomeService::class)->isReobservable($void))->toBeFalse();
});

test('the daily evaluation picks up a checkpoint voided by a cancelled separation', function (): void {
    $separation = $this->lifecycle->recordSeparation($this->employee, $this->vp, ['separation_date' => now()->subDays(20)->toDateString(), 'separation_reason' => 'resignation']);
    $cited = reobservationCheckpoint($this->snapshot);

    // Simulate the state before Phase 8.5: cancelled and voided, but never observed again.
    lifecycleFixture(fn () => EmployeeSeparation::withoutEvents(fn () => $separation->forceFill(['cancelled_at' => now(), 'cancellation_reason' => 'Recorded in error'])->save()));
    app(OutcomeService::class)->void($cited, 'Separation cancelled: Recorded in error', $this->vp);

    expect(app(OutcomeEvaluator::class)->dueStatusObservations(OutcomeType::StatusObserved30d)->pluck('id')->all())->toContain($this->snapshot->id);

    app(OutcomeEvaluator::class)->evaluate();

    expect(reobservationCheckpoint($this->snapshot)->result)->toBe(OutcomeResult::Active);
});
