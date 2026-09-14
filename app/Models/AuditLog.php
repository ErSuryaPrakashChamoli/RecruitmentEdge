<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Section 41's cross-cutting audit trail. Written via record() — by the Auditable trait for
 * ordinary models, and explicitly by the Roles resource pages for role permission changes (Spatie's
 * Role model can't use the trait, and its permissions live in a pivot that model events don't
 * see). Models that already have their own dedicated immutable history table
 * (candidate_stage_histories, offer_status_histories, recruiter_incentive_approvals,
 * recruitment_requisition_approvals) don't also use Auditable, to avoid two audit trails
 * disagreeing with each other.
 *
 * `old_values` holds the previous values and `changes` the new values of the same keys.
 */
#[Fillable(['user_id', 'auditable_type', 'auditable_id', 'action', 'changes', 'old_values', 'ip_address'])]
class AuditLog extends Model
{
    public const ?string UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'changes' => 'array',
            'old_values' => 'array',
        ];
    }

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public static function record(Model $subject, string $action, ?array $oldValues, ?array $newValues): self
    {
        return self::query()->create([
            'user_id' => auth()->id(),
            'auditable_type' => $subject::class,
            'auditable_id' => $subject->getKey(),
            'action' => $action,
            'changes' => $newValues,
            'old_values' => $oldValues,
            'ip_address' => request()?->ip(),
        ]);
    }

    /**
     * One row per changed field with its old and new value, stringified for display.
     *
     * @return array<int, array{field: string, old: string, new: string}>
     */
    public function diffRows(): array
    {
        // getAttribute(), not $this->changes: Eloquent's own protected $changes property shadows it.
        $old = $this->getAttribute('old_values') ?? [];
        $new = $this->getAttribute('changes') ?? [];

        return collect(array_unique([...array_keys($old), ...array_keys($new)]))
            ->map(fn (string $field) => [
                'field' => $field,
                'old' => self::displayValue($old[$field] ?? null),
                'new' => self::displayValue($new[$field] ?? null),
            ])
            ->values()
            ->all();
    }

    private static function displayValue(mixed $value): string
    {
        return match (true) {
            $value === null => '—',
            is_bool($value) => $value ? 'true' : 'false',
            is_array($value) => $value === [] ? '—' : implode(', ', array_map(fn ($item) => is_scalar($item) ? (string) $item : json_encode($item), $value)),
            default => (string) $value,
        };
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }
}
