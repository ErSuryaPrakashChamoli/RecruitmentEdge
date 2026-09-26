<?php

namespace App\Models;

use App\Enums\SeparationReason;
use App\Models\Concerns\Auditable;
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
    use Auditable, HasFactory;

    protected function casts(): array
    {
        return [
            'separation_date' => 'date',
            'separation_reason' => SeparationReason::class,
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
