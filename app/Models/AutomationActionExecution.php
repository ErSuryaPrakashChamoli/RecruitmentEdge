<?php

namespace App\Models;

use App\Enums\AutomationActionStatus;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * The result of one configured action inside an automation execution; `target` is what the
 * action produced (recruiter action, communication, follow-up...).
 */
#[Fillable(['automation_execution_id', 'position', 'action_type', 'status', 'summary', 'error', 'attempts', 'target_type', 'target_id', 'completed_at'])]
class AutomationActionExecution extends Model
{
    use BelongsToTenant;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'pending',
        'attempts' => 0,
    ];

    protected function casts(): array
    {
        return [
            'status' => AutomationActionStatus::class,
            'attempts' => 'integer',
            'position' => 'integer',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<AutomationExecution, $this>
     */
    public function execution(): BelongsTo
    {
        return $this->belongsTo(AutomationExecution::class, 'automation_execution_id');
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function target(): MorphTo
    {
        return $this->morphTo();
    }
}
