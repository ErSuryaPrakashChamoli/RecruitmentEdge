<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Database\Factories\RecruitmentSettingChangeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Phase 8.6 (D8.6-012): one change of a governed recruitment setting — append-only history, written
 * only by RecruitmentSettingService.
 */
#[Fillable(['key', 'old_value', 'new_value', 'effective_from', 'changed_by', 'reason', 'request_id'])]
class RecruitmentSettingChange extends Model
{
    /** @use HasFactory<RecruitmentSettingChangeFactory> */
    use BelongsToTenant, HasFactory;

    public const ?string UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Setting history is append-only.');
        });

        static::deleting(function (): void {
            throw new LogicException('Setting history is append-only.');
        });
    }

    protected function casts(): array
    {
        return [
            'effective_from' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
