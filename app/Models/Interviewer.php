<?php

namespace App\Models;

use Database\Factories\InterviewerFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The administrator-maintained list of employees who may be picked as an interview's interviewer.
 */
#[Fillable(['employee_id', 'is_active'])]
class Interviewer extends Model
{
    /** @use HasFactory<InterviewerFactory> */
    use HasFactory;

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
