<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Database\Factories\InterviewerFactory;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The administrator-maintained list of employees who may be picked as an interview's interviewer.
 *
 * Phase 8.6 (D8.6-009): an interviewer is deactivated, never deleted (their interviews and
 * feedback stay attributable); every change is audited; a new interview needs an active listed
 * interviewer (InterviewService::schedule).
 */
#[Fillable(['employee_id', 'is_active'])]
class Interviewer extends Model
{
    /** @use HasFactory<InterviewerFactory> */
    use Auditable, HasFactory;

    protected static function booted(): void
    {
        static::deleting(function (): void {
            throw new DomainException('Interviewers are deactivated, never deleted — their interviews and feedback stay attributable.');
        });
    }

    /**
     * Whether the employee is on the active interviewer list.
     */
    public static function isActiveInterviewer(int $employeeId): bool
    {
        return static::query()->where('employee_id', $employeeId)->where('is_active', true)->exists();
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /**
     * Active interviewers keyed by employee id, for the interviewer dropdown.
     *
     * @param  int|null  $alsoIncludeEmployeeId  An interview's current interviewer, kept selectable after being removed from the list.
     * @return array<int, string>
     */
    public static function selectOptions(?int $alsoIncludeEmployeeId = null): array
    {
        return Employee::query()
            ->with('designation')
            ->where(fn (Builder $query) => $query
                ->whereIn('id', static::query()->where('is_active', true)->select('employee_id'))
                ->when($alsoIncludeEmployeeId, fn (Builder $query, int $employeeId) => $query->orWhere('id', $employeeId)))
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get()
            ->mapWithKeys(fn (Employee $employee): array => [$employee->id => static::optionLabel($employee)])
            ->all();
    }

    /**
     * "Full Name (EMP ID) — Designation".
     */
    public static function optionLabel(Employee $employee): string
    {
        return collect(["{$employee->fullName()} ({$employee->employee_code})", $employee->designation?->name])
            ->filter()
            ->implode(' — ');
    }
}
