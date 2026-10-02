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
#[Fillable(['user_id', 'actor_type', 'actor_id', 'actor_kind', 'on_behalf_of_user_id', 'auditable_type', 'auditable_id', 'action', 'reason', 'changes', 'old_values', 'ip_address', 'request_id'])]
class AuditLog extends Model
{
    public const ?string UPDATED_AT = null;

    /**
     * Phase 8.6 (D8.6-003/024): the reason given for the change currently being made — set by
     * withReason() so the rows the Auditable trait writes for that change carry it too.
     */
    private static ?string $pendingReason = null;

    /**
     * Phase 8.7 (D8.7-015): who is acting when no person is — set by asActor() around automation
     * and AI work: ['kind' => 'automation'|'ai'|…, 'on_behalf_of' => user id or null].
     *
     * @var array{kind: string, on_behalf_of: int|null, actor?: Model}|null
     */
    private static ?array $actorContext = null;

    /**
     * The kind recorded when nothing more specific applies: 'queue' while a job runs, 'scheduler'
     * or 'console' in an artisan command (set by AppServiceProvider's listeners).
     */
    private static ?string $defaultActorKind = null;

    public const array ACTOR_KINDS = ['user', 'candidate', 'automation', 'ai', 'scheduler', 'console', 'queue', 'system'];

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
    public static function record(Model $subject, string $action, ?array $oldValues, ?array $newValues, ?string $reason = null): self
    {
        // The default guard is whichever guard authenticated the request (the candidate portal
        // switches it to `candidate`), so only a staff User may fill user_id. Inside asActor()
        // (automation, AI) the work is not the signed-in person's: no user_id, the principal is
        // recorded as on_behalf_of instead (Phase 8.7, D8.7-015).
        $context = self::$actorContext;
        // Phase 8.8 (D8.8-001): inside asCandidate() the actor is the candidate the server resolved
        // for this action — never whoever else is signed in.
        $actor = $context === null ? auth()->user() : ($context['actor'] ?? null);
        $kind = $context['kind'] ?? match (true) {
            $actor instanceof User => 'user',
            $actor instanceof Model => 'candidate',
            default => self::$defaultActorKind ?? 'system',
        };

        // Phase 8.6 (D8.6-024): an explicit record() is redacted like an Auditable diff — a
        // subject's redacted attributes (e.g. offer compensation) never reach the audit trail.
        [$oldValues, $newValues] = [self::redact($subject, $oldValues), self::redact($subject, $newValues)];

        return self::query()->create([
            'user_id' => $actor instanceof User ? $actor->getKey() : null,
            'actor_type' => $actor instanceof Model && ! $actor instanceof User ? $actor->getMorphClass() : null,
            'actor_id' => $actor instanceof Model && ! $actor instanceof User ? $actor->getKey() : null,
            'actor_kind' => $kind,
            'on_behalf_of_user_id' => $context['on_behalf_of'] ?? null,
            'auditable_type' => $subject::class,
            'auditable_id' => $subject->getKey(),
            'action' => $action,
            'reason' => filled($reason) ? $reason : self::$pendingReason,
            'changes' => $newValues,
            'old_values' => $oldValues,
            'ip_address' => request()?->ip(),
            // Phase 8.4: the request / job correlation id (AssignRequestId).
            'request_id' => Context::get('request_id'),
        ]);
    }

    /**
     * Run a change with a reason: every audit row written inside the callback (the Auditable
     * trait's created/updated/deleted/restored rows included) records it. Nested calls keep the
     * innermost reason for their own duration.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     */
    public static function withReason(?string $reason, callable $callback): mixed
    {
        $previous = self::$pendingReason;
        self::$pendingReason = filled($reason) ? trim((string) $reason) : $previous;

        try {
            return $callback();
        } finally {
            self::$pendingReason = $previous;
        }
    }

    /**
     * Run work as an actor other than the signed-in person — automation acting on its owner's
     * authority, an AI job for its requester. Nested calls keep the innermost context.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     */
    public static function asActor(string $kind, ?int $onBehalfOfUserId, callable $callback): mixed
    {
        $previous = self::$actorContext;
        self::$actorContext = ['kind' => $kind, 'on_behalf_of' => $onBehalfOfUserId];

        try {
            return $callback();
        } finally {
            self::$actorContext = $previous;
        }
    }

    /**
     * Phase 8.8 (D8.8-001): run candidate self-service work as that candidate. The actor is
     * resolved on the server (the signed-in candidate, or the candidate a verified signed link
     * belongs to); rows record kind `candidate` and the candidate in the actor columns, never a
     * staff user_id — even when a staff session exists in the same browser. Nested contexts
     * (automation reacting to the change) keep their own attribution.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     */
    public static function asCandidate(Model $candidateActor, callable $callback): mixed
    {
        if ($candidateActor instanceof User) {
            throw new \InvalidArgumentException('A staff user cannot be recorded as a candidate actor.');
        }

        $previous = self::$actorContext;
        self::$actorContext = ['kind' => 'candidate', 'on_behalf_of' => null, 'actor' => $candidateActor];

        try {
            return $callback();
        } finally {
            self::$actorContext = $previous;
        }
    }

    public static function setDefaultActorKind(?string $kind): void
    {
        self::$defaultActorKind = $kind;
    }

    public static function defaultActorKind(): ?string
    {
        return self::$defaultActorKind;
    }

    /**
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>|null
     */
    private static function redact(Model $subject, ?array $values): ?array
    {
        if ($values === null || ! method_exists($subject, 'auditRedactedAttributes')) {
            return $values;
        }

        $redacted = $subject->auditRedactedAttributes();

        return collect($values)
            ->map(fn (mixed $value, string|int $key) => in_array($key, $redacted, true) && $value !== null && $value !== '[redacted]' ? '[redacted]' : $value)
            ->all();
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
     * @return BelongsTo<User, $this>
     */
    public function onBehalfOf(): BelongsTo
    {
        return $this->belongsTo(User::class, 'on_behalf_of_user_id');
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
