<?php

use App\Models\Employee;
use App\Models\HiringOutcome;
use App\Models\HiringOutcomeSnapshot;

test('a hiring snapshot is immutable except for linking the employee once', function (): void {
    $snapshot = HiringOutcomeSnapshot::factory()->create();
    $employee = Employee::factory()->create();

    $snapshot->update(['employee_id' => $employee->id]);

    expect($snapshot->fresh()->employee_id)->toBe($employee->id)
        ->and(fn () => $snapshot->update(['employee_id' => Employee::factory()->create()->id]))->toThrow(LogicException::class)
        ->and(fn () => $snapshot->fresh()->update(['time_to_hire_days' => 1]))->toThrow(LogicException::class);
});

test('an outcome is immutable except for its current flag', function (): void {
    $outcome = HiringOutcome::factory()->create();

    $outcome->update(['is_current' => false]);

    expect($outcome->fresh()->is_current)->toBeFalse()
        ->and(fn () => $outcome->fresh()->update(['result' => 'active']))->toThrow(LogicException::class);
});
