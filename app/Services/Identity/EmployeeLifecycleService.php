<?php

namespace App\Services\Identity;

use App\Enums\AccessState;
use App\Enums\EmployeeStatus;
use App\Enums\OutcomeState;
use App\Events\EmployeeSeparated;
use App\Events\SeparationCancelled;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeSeparation;
use App\Models\HiringOutcome;
use App\Models\User;
use App\Services\HierarchyService;
use App\Services\Lifecycle\LifecycleGuard;
use App\Services\Outcomes\OutcomeService;
use Closure;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Phase 8.4: the only writer of employment state (Employee.status) and of the separation
 * lifecycle. Access follows employment through StaffAccessService:
 *
 * - deactivate(): employment Inactive → access Suspended (roles dormant); reactivate() lifts a
 *   suspension that employment caused — never one an administrator imposed separately.
 * - recordSeparation(): a separation may be recorded ahead of the last working day. Recording it
 *   alone changes nothing about access; it becomes effective the day after its separation_date.
 * - applyEffectiveSeparation(): employment Separated → access Revoked, idempotent, run hourly by
 *   identity:enforce-separations and on the spot by the access gate. The last effective CHRO is
 *   never separated (refused and audited).
 */
class EmployeeLifecycleService
{
    public function __construct(
        private readonly StaffAccessService $access,
        private readonly AuthorityGuard $authority,
        private readonly HierarchyService $hierarchy,
    ) {}

    /**
     * Whether a separation has taken effect: not cancelled and its last working day has passed.
     */
    public static function isEffective(EmployeeSeparation $separation, ?Carbon $at = null): bool
    {
        return $separation->cancelled_at === null
            && $separation->separation_date->copy()->startOfDay()->lt(($at ?? now())->copy()->startOfDay());
    }

    public function deactivate(Employee $employee, User $actor, string $reason): Employee
    {
        $this->assertCanManageEmployment($actor, $employee);

        return $this->protectingChro($employee, 'employment_deactivated', $actor, fn () => DB::transaction(function () use ($employee, $actor, $reason): Employee {
            $locked = $this->lockEmployee($employee);

            if ($locked->status !== EmployeeStatus::Active) {
                throw new DomainException('Only an active employee can be made inactive.');
            }

            $this->setStatus($locked, EmployeeStatus::Inactive, $reason, $actor);

            if ($user = $this->userOf($locked)) {
                $this->access->suspend($user, null, $reason, 'employment');
            }

            return $locked;
        }));
    }

    public function reactivate(Employee $employee, User $actor, string $reason): Employee
    {
        $this->assertCanManageEmployment($actor, $employee);

        return DB::transaction(function () use ($employee, $actor, $reason): Employee {
            $locked = $this->lockEmployee($employee);

            if ($locked->status !== EmployeeStatus::Inactive) {
                throw new DomainException('Only an inactive employee can be reactivated. A separated employee returns through rehire.');
            }

            $this->setStatus($locked, EmployeeStatus::Active, $reason, $actor);
            $user = $this->userOf($locked);

            if ($user !== null && $user->access_status === AccessState::Suspended && $user->access_source === 'employment') {
                $this->access->restore($user, null, $reason, 'employment');
            }

            return $locked;
        });
    }

    /**
     * Rehire: a separated employee record becomes current again (restored if it was deleted). The
     * separation stays in history; the new employment starts with the new joining. Called by
     * candidate conversion, which has authorised the rehire (employees.convert in scope).
     */
    public function rehire(Employee $employee, User $actor, string $reason): Employee
    {
        return DB::transaction(function () use ($employee, $actor, $reason): Employee {
            $locked = $this->lockEmployee($employee);

            if ($locked->status !== EmployeeStatus::Separated) {
                throw new DomainException('Only a separated employee can be rehired.');
            }

            if ($locked->trashed()) {
                $locked->restore();
            }

            $this->setStatus($locked, EmployeeStatus::Active, $reason, $actor);
            AuditLog::record($locked, 'employee_rehired', null, ['previous_separation_id' => $locked->separations()->whereNull('cancelled_at')->latest('id')->value('id'), 'by_user_id' => $actor->id]);
            Log::info('identity.rehire', ['employee_id' => $locked->id, 'actor_id' => $actor->id]);

            $employee->setRawAttributes($locked->getAttributes(), true);

            return $employee;
        });
    }

    /**
     * @param  array{separation_date: mixed, separation_reason: mixed, notes?: ?string, revoke_access_now?: bool}  $data
     */
    public function recordSeparation(Employee $employee, User $actor, array $data): EmployeeSeparation
    {
        if (! $actor->can('employees.separation.manage') || ! $this->hierarchy->canView($actor, $employee)) {
            throw new DomainException('Recording a separation needs employees.separation.manage for an employee in your hierarchy.');
        }

        if ($actor->employee_id !== null && (int) $actor->employee_id === (int) $employee->id) {
            throw new DomainException('You cannot record your own separation.');
        }

        $date = Carbon::parse($data['separation_date'])->startOfDay();

        if ($employee->date_of_joining !== null && $date->lt($employee->date_of_joining->copy()->startOfDay())) {
            throw new DomainException('The last working day cannot be before the date of joining.');
        }

        return $this->protectingChro($employee, 'separation', $actor, fn () => DB::transaction(function () use ($employee, $actor, $data, $date): EmployeeSeparation {
            $locked = $this->lockEmployee($employee);

            if ($locked->trashed() || $locked->status === EmployeeStatus::Separated) {
                throw new DomainException('This employee is already separated.');
            }

            if ($this->openSeparation($locked) !== null) {
                throw new DomainException('This employee already has a separation recorded. Correct or cancel it instead.');
            }

            if (($user = $this->userOf($locked)) !== null) {
                $this->authority->assertEffectiveChroRemainsWithout($user);
            }

            $separation = EmployeeSeparation::query()->create([
                'employee_id' => $locked->id,
                'separation_date' => $date->toDateString(),
                'separation_reason' => $data['separation_reason'],
                'notes' => $data['notes'] ?? null,
                'created_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            Log::info('identity.separation_recorded', ['employee_id' => $locked->id, 'separation_id' => $separation->id, 'effective' => self::isEffective($separation)]);

            if (self::isEffective($separation)) {
                $this->applyEffectiveSeparation($separation);
            } elseif (($data['revoke_access_now'] ?? false) && $user !== null) {
                // Leaving before the last working day is recorded: access ends now, employment
                // ends on the date.
                $this->access->revoke($user, null, 'Access ended ahead of the last working day', 'separation_immediate');
            }

            return $separation->refresh();
        }));
    }

    /**
     * Corrects a recorded separation. Before it takes effect the date may move (a future date keeps
     * access until then); once applied only the reason and notes can be corrected — a wrong
     * effective separation is cancelled instead.
     *
     * @param  array{separation_date?: mixed, separation_reason?: mixed, notes?: ?string}  $data
     */
    public function correctSeparation(EmployeeSeparation $separation, User $actor, array $data): EmployeeSeparation
    {
        if (! $actor->can('update', $separation)) {
            throw new DomainException('Correcting a separation needs employees.separation.manage for an employee in your hierarchy.');
        }

        return $this->protectingChro($separation->employee()->withTrashed()->first(), 'separation', $actor, fn () => DB::transaction(function () use ($separation, $actor, $data): EmployeeSeparation {
            /** @var EmployeeSeparation $locked */
            $locked = EmployeeSeparation::query()->whereKey($separation->id)->lockForUpdate()->firstOrFail();

            if ($locked->cancelled_at !== null) {
                throw new DomainException('A cancelled separation cannot be corrected.');
            }

            $changes = array_intersect_key($data, array_flip(['separation_date', 'separation_reason', 'notes']));

            if (array_key_exists('separation_date', $changes)) {
                $newDate = Carbon::parse($changes['separation_date'])->toDateString();

                if ($newDate !== $locked->separation_date->toDateString() && $locked->effective_applied_at !== null) {
                    throw new DomainException('This separation has already taken effect; its date can no longer change. Cancel it instead.');
                }

                $changes['separation_date'] = $newDate;
            }

            LifecycleGuard::allow(fn () => $locked->forceFill([...$changes, 'updated_by' => $actor->id])->save());

            if ($locked->effective_applied_at === null && self::isEffective($locked)) {
                $this->applyEffectiveSeparation($locked);
            }

            return $locked->refresh();
        }));
    }

    /**
     * Cancels a separation (withdrawn resignation, recorded in error). Needs
     * employees.separation.cancel in scope and a reason; the record is kept, marked cancelled.
     *
     * - Not yet effective: nothing else changes — the person never lost anything.
     * - Already effective: employment is Active again, but access is NOT restored automatically
     *   (it stays Revoked; an administrator restores it deliberately, which grants the base role
     *   only); paused automation stays paused; outcomes recorded from this separation are voided
     *   through OutcomeService (history kept) and re-evaluated.
     * - A separation followed by a rehire can no longer be cancelled.
     */
    public function cancelSeparation(EmployeeSeparation $separation, User $actor, string $reason): EmployeeSeparation
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new DomainException('A reason is required to cancel a separation.');
        }

        $employee = Employee::withTrashed()->find($separation->employee_id);

        if (! $actor->can('employees.separation.cancel') || ! $this->hierarchy->canView($actor, $employee)) {
            throw new DomainException('Cancelling a separation needs employees.separation.cancel for an employee in your hierarchy.');
        }

        if ($actor->employee_id !== null && (int) $actor->employee_id === (int) $separation->employee_id) {
            throw new DomainException('You cannot cancel your own separation.');
        }

        return DB::transaction(function () use ($separation, $actor, $reason): EmployeeSeparation {
            /** @var EmployeeSeparation $locked */
            $locked = EmployeeSeparation::query()->whereKey($separation->id)->lockForUpdate()->firstOrFail();
            $employee = $this->lockEmployee($locked->employee()->withTrashed()->firstOrFail());

            if ($locked->cancelled_at !== null) {
                throw new DomainException('This separation is already cancelled.');
            }

            $wasEffective = $locked->effective_applied_at !== null;

            if ($wasEffective && $employee->status !== EmployeeStatus::Separated) {
                throw new DomainException('This person has been rehired since; the separation is part of their history and cannot be cancelled.');
            }

            LifecycleGuard::allow(fn () => $locked->forceFill(['cancelled_at' => now(), 'cancelled_by' => $actor->id, 'cancellation_reason' => mb_substr($reason, 0, 255), 'updated_by' => $actor->id])->save());

            if ($wasEffective) {
                $this->setStatus($employee, EmployeeStatus::Active, 'Separation cancelled: '.$reason, $actor);
                $this->voidOutcomesFrom($locked, $actor, $reason);
            }

            $user = $this->userOf($employee);

            AuditLog::record($locked, 'separation_cancelled', null, [
                'employee_id' => $employee->id,
                'was_effective' => $wasEffective,
                'reason' => $reason,
                'access_status' => $user?->access_status?->value,
                'access_review_required' => $user !== null && $user->access_status !== AccessState::Active,
                'by_user_id' => $actor->id,
            ]);
            Log::info('identity.separation_cancelled', ['employee_id' => $employee->id, 'separation_id' => $locked->id, 'was_effective' => $wasEffective, 'actor_id' => $actor->id]);

            SeparationCancelled::dispatch($employee->id, $locked->id, $wasEffective, $actor->id);

            return $locked->refresh();
        });
    }

    /**
     * Outcomes recorded from a separation that turned out not to have happened are voided — a new,
     * audited version; the original stays in history (Phase 8.2 correction semantics).
     */
    private function voidOutcomesFrom(EmployeeSeparation $separation, User $actor, string $reason): void
    {
        $outcomes = app(OutcomeService::class);

        HiringOutcome::query()
            ->current()
            ->where('source_type', $separation->getMorphClass())
            ->where('source_id', $separation->id)
            ->where('state', '!=', OutcomeState::Void->value)
            ->get()
            ->each(fn (HiringOutcome $outcome) => $outcomes->void($outcome, 'Separation cancelled: '.$reason, $actor));
    }

    /**
     * Applies an effective separation: employment Separated, access Revoked, handoff announced.
     * Idempotent — false when there was nothing to do (not yet effective, cancelled, applied).
     */
    public function applyEffectiveSeparation(EmployeeSeparation $separation): bool
    {
        return DB::transaction(function () use ($separation): bool {
            /** @var EmployeeSeparation|null $locked */
            $locked = EmployeeSeparation::query()->whereKey($separation->id)->lockForUpdate()->first();

            if ($locked === null || $locked->effective_applied_at !== null || ! self::isEffective($locked)) {
                return false;
            }

            $employee = Employee::withTrashed()->whereKey($locked->employee_id)->lockForUpdate()->firstOrFail();
            $user = $this->userOf($employee);

            if ($user !== null) {
                $this->authority->assertEffectiveChroRemainsWithout($user);
            }

            if ($employee->status !== EmployeeStatus::Separated) {
                $this->setStatus($employee, EmployeeStatus::Separated, 'Separation effective', null);
            }

            // A query update: the explicit separation_effective row below is the audit record.
            EmployeeSeparation::query()->whereKey($locked->id)->update(['effective_applied_at' => now()]);

            if ($user !== null) {
                $this->access->revoke($user, null, 'Separation effective (last working day '.$locked->separation_date->toDateString().')', 'separation');
            }

            AuditLog::record($locked, 'separation_effective', null, ['employee_id' => $employee->id, 'user_id' => $user?->id, 'last_working_day' => $locked->separation_date->toDateString()]);
            Log::info('identity.separation_effective', ['employee_id' => $employee->id, 'separation_id' => $locked->id, 'user_id' => $user?->id]);

            EmployeeSeparated::dispatch($employee->id, $locked->id, $user?->id, $locked->separation_date->toDateString());

            return true;
        });
    }

    /**
     * Applies the due separation of $user's employee, if any (called by the access gate when it
     * refuses a login whose separation has taken effect but was not processed yet).
     */
    public function applyDueSeparationFor(User $user): bool
    {
        $due = $user->employee_id !== null ? $this->dueSeparations()->where('employee_id', $user->employee_id)->first() : null;

        if ($due === null) {
            return false;
        }

        try {
            return $this->applyEffectiveSeparation($due);
        } catch (LastChroProtectedException) {
            $this->authority->recordProtection($user, 'separation_effective');

            return false;
        }
    }

    /**
     * Every separation that has taken effect but has not been applied yet. Each is applied in its
     * own transaction; one failure never stops the rest.
     *
     * @return array{due: int, applied: int, protected: int, failed: int}
     */
    public function enforceDueSeparations(bool $dryRun = false): array
    {
        $counts = ['due' => $this->dueSeparations()->count(), 'applied' => 0, 'protected' => 0, 'failed' => 0];

        if ($dryRun) {
            return $counts;
        }

        $this->dueSeparations()->with('employee')->chunkById(200, function ($separations) use (&$counts): void {
            foreach ($separations as $separation) {
                try {
                    $counts['applied'] += $this->applyEffectiveSeparation($separation) ? 1 : 0;
                } catch (LastChroProtectedException) {
                    $counts['protected']++;

                    if ($user = $this->userOf($separation->employee()->withTrashed()->first())) {
                        $this->authority->recordProtection($user, 'separation_effective');
                    }
                } catch (Throwable $e) {
                    $counts['failed']++;
                    Log::error('identity.separation_enforcement_failed', ['separation_id' => $separation->id, 'exception' => $e::class]);
                    report($e);
                }
            }
        });

        return $counts;
    }

    /**
     * @return Builder<EmployeeSeparation>
     */
    public function dueSeparations(): Builder
    {
        return EmployeeSeparation::query()
            ->whereNull('cancelled_at')
            ->whereNull('effective_applied_at')
            ->whereDate('separation_date', '<', now()->toDateString());
    }

    public function openSeparation(Employee $employee): ?EmployeeSeparation
    {
        return EmployeeSeparation::query()
            ->where('employee_id', $employee->id)
            ->whereNull('cancelled_at')
            ->whereNull('effective_applied_at')
            ->first();
    }

    private function assertCanManageEmployment(User $actor, Employee $employee): void
    {
        if (! $actor->can('users.manage') || ! $this->hierarchy->canView($actor, $employee)) {
            throw new DomainException('Changing employment needs users.manage for an employee in your hierarchy.');
        }

        if ($actor->employee_id !== null && (int) $actor->employee_id === (int) $employee->id) {
            throw new DomainException('You cannot change your own employment.');
        }
    }

    private function setStatus(Employee $employee, EmployeeStatus $status, string $reason, ?User $actor): void
    {
        $from = $employee->status;

        LifecycleGuard::allow(fn () => $employee->forceFill(['status' => $status])->save());

        AuditLog::record($employee, 'employment_'.$status->value, ['status' => $from?->value], ['status' => $status->value, 'reason' => $reason, 'by_user_id' => $actor?->id]);
        Log::info('identity.employment_transition', ['employee_id' => $employee->id, 'from' => $from?->value, 'to' => $status->value, 'actor_id' => $actor?->id]);

        StaffAccessService::invalidateDecisions();
    }

    private function lockEmployee(Employee $employee): Employee
    {
        return Employee::withTrashed()->whereKey($employee->id)->lockForUpdate()->firstOrFail();
    }

    private function userOf(?Employee $employee): ?User
    {
        return $employee !== null ? User::query()->where('employee_id', $employee->id)->first() : null;
    }

    /**
     * @template T
     *
     * @param  Closure(): T  $operation
     * @return T
     */
    private function protectingChro(?Employee $employee, string $attempt, ?User $actor, Closure $operation): mixed
    {
        return $this->authority->protecting($attempt, $actor, $operation);
    }
}
