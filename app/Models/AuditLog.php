<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Context;

/**
 * Section 41's cross-cutting audit trail. Written via record() — by the Auditable trait for
 * ordinary models, and explicitly by the Phase 8.4 identity services for access, role and
 * role-permission changes (role and permission assignments live in pivots that model events don't
 * see). Models that already have their own dedicated immutable history table
 * (candidate_stage_histories, offer_status_histories, recruiter_incentive_approvals,
 * recruitment_requisition_approvals) don't also use Auditable, to avoid two audit trails
 * disagreeing with each other.
 *
 * `user_id` is always a staff User; any other authenticated actor (a candidate on the portal)
 * is recorded in the polymorphic `actor` instead.
 *
 * `old_values` holds the previous values and `changes` the new values of the same keys.
 */
#[Fillable(['user_id', 'actor_type', 'actor_id', 'auditable_type', 'auditable_id', 'action', 'changes', 'old_values', 'ip_address', 'request_id'])]
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
        // The default guard is whichever guard authenticated the request (the candidate portal
        // switches it to `candidate`), so only a staff User may fill user_id.
        $actor = auth()->user();

        return self::query()->create([
            'user_id' => $actor instanceof User ? $actor->getKey() : null,
            'actor_type' => $actor instanceof Model && ! $actor instanceof User ? $actor->getMorphClass() : null,
            'actor_id' => $actor instanceof Model && ! $actor instanceof User ? $actor->getKey() : null,
            'auditable_type' => $subject::class,
            'auditable_id' => $subject->getKey(),
            'action' => $action,
            'changes' => $newValues,
            'old_values' => $oldValues,
            'ip_address' => request()?->ip(),
            // Phase 8.4: the request / job correlation id (AssignRequestId).
            'request_id' => Context::get('request_id'),
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

    /**
     * A non-staff actor (e.g. a CandidatePortalAccount); staff are on user().
     */
    public function actor(): MorphTo
    {
        return $this->morphTo();
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }
}
