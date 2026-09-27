<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Phase 8.4: the handoff of a departed person's current work (OwnershipHandoffService). Counts
 * only — the records themselves keep their historical owner until someone reassigns them.
 */
#[Fillable(['dedupe_key', 'user_id', 'employee_id', 'trigger', 'assignee_employee_id', 'status', 'summary', 'resolved_by', 'resolved_at'])]
class OwnershipHandoff extends Model
{
    public const string OPEN = 'open';

    public const string COMPLETED = 'completed';

    protected function casts(): array
    {
        return [
            'summary' => 'array',
            'resolved_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assignee_employee_id');
    }
}
