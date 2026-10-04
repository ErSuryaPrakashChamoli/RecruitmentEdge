<?php

namespace App\Models;

use App\Enums\SeparationReason;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\GuardsLifecycleAttributes;
use App\Services\Outcomes\OutcomeEvaluator;
use Database\Factories\EmployeeSeparationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 8.2: the minimal separation record — when an employee left and a structured reason.
 * The only reliable source for attrition in the Outcome Loop. Audited on every change.
 */
#[Fillable(['employee_id', 'separation_date', 'separation_reason', 'notes', 'created_by', 'updated_by'])]
class EmployeeSeparation extends Model
{
    /** @use HasFactory<EmployeeSeparationFactory> */
    use Auditable, BelongsToTenant, GuardsLifecycleAttributes, HasFactory;

    /**
     * Notes can hold personal detail: they stay out of serialization and therefore out of the
     * audit log and any AI payload. They are shown only on the separation screen.
     *
     * @var list<string>
     */
    protected $hidden = ['notes'];

    protected static function booted(): void
    {
        static::creating(function (self $separation): void {
            $separation->created_by ??= auth()->id();
            $separation->updated_by ??= auth()->id();
        });

        static::updating(function (self $separation): void {
            $separation->updated_by = auth()->id() ?? $separation->updated_by;
        });

        // A separation is the authoritative source for attrition: re-check this employee's
        // status-observation checkpoints straight away (the daily evaluation would also catch it).
        // Phase 8.4: only when the evidence itself changed, not when the lifecycle applied it.
        static::saved(function (self $separation): void {
            if ($separation->wasRecentlyCreated || $separation->wasChanged(['separation_date', 'cancelled_at'])) {
                app(OutcomeEvaluator::class)->evaluateEmployee($separation->employee_id);
            }
        });
    }

    /**
     * Phase 8.4: the date and the lifecycle of a separation change only through
     * EmployeeLifecycleService (the reason and notes stay correctable there too).
     *
     * @return array<int, string>
     */
    public function lifecycleAttributes(): array
    {
        return ['employee_id', 'separation_date', 'effective_applied_at', 'cancelled_at', 'cancelled_by', 'cancellation_reason'];
    }

    public function lifecycleOwner(): string
    {
        return 'EmployeeLifecycleService';
    }

    protected function casts(): array
    {
        return [
            'separation_date' => 'date',
            'separation_reason' => SeparationReason::class,
            'effective_applied_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
