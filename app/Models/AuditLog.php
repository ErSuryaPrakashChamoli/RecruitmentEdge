<?php

namespace App\Models;

use App\Enums\AccessState;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\Context;
use LogicException;

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
 *
 * SaaS-1: two streams in one table. Tenant stream: tenant_id set — every read is limited to the
 * current tenant (TenantScope), so `audit.view` means this tenant's audit only. Platform stream:
 * tenant_id null (a sign-in attempt for an unknown account, platform commands) — no tenant query
 * can reach it. record() attributes each row (tenantsFor()).
 */
#[Fillable(['tenant_id', 'user_id', 'actor_type', 'actor_id', 'actor_kind', 'on_behalf_of_user_id', 'auditable_type', 'auditable_id', 'action', 'reason', 'changes', 'old_values', 'ip_address', 'request_id'])]
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

    public const array ACTOR_KINDS = ['user', 'candidate', 'automation', 'ai', 'scheduler', 'console', 'queue', 'system', 'platform', 'api', 'integration'];

    protected static function booted(): void
    {
        static::addGlobalScope(new TenantScope);

        // An entry written directly (not through record(), which always chooses its stream) belongs
        // to the current tenant, like every tenant-owned row.
        static::creating(function (self $entry): void {
            if (! array_key_exists('tenant_id', $entry->getAttributes())) {
                $entry->setAttribute('tenant_id', TenantContext::current()->id());
            }
        });

        // SaaS-5: append-only. A correction is a new entry, never an edit; nothing deletes history
        // (a tenant purge keeps the audit trail — config('platform.deletion.retain_tables')).
        static::updating(fn () => throw new LogicException('The audit trail is append-only: record a new entry instead.'));
        static::deleting(fn () => throw new LogicException('The audit trail is append-only: entries are never deleted.'));
    }

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

        $attributes = [
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
        ];

        $entries = array_map(
            fn (?int $tenantId): self => self::query()->create(['tenant_id' => $tenantId, ...$attributes]),
            self::tenantsFor($subject),
        );

        return $entries[0];
    }

    /**
     * SaaS-1: which tenant stream(s) an entry belongs to.
     * - A tenant-owned subject: its own tenant, always.
     * - Otherwise the current tenant.
     * - With no tenant (sign-in, MFA, password reset happen before a tenant is chosen), an event
     *   about a staff identity is recorded in every tenant the person is an active member of, so
     *   each tenant keeps its staff's sign-in trail.
     * - Anything else is the platform stream (null).
     *
     * @return non-empty-list<int|null>
     */
    private static function tenantsFor(Model $subject): array
    {
        $subjectTenant = $subject->getAttribute('tenant_id');

        if ($subjectTenant !== null && ! $subject instanceof Tenant) {
            return [(int) $subjectTenant];
        }

        if (($current = TenantContext::current()->id()) !== null) {
            return [$current];
        }

        if ($subject instanceof User) {
            $tenants = TenantMembership::query()
                ->where('user_id', $subject->getKey())
                ->where('status', AccessState::Active)
                ->orderBy('tenant_id')
                ->pluck('tenant_id')
                ->map(fn (mixed $id): int => (int) $id)
                ->all();

            if ($tenants !== []) {
                return $tenants;
            }
        }

        return [null];
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
     * SaaS-6: $actor names the non-person actor (an API credential, an integration connection),
     * recorded as actor_type / actor_id.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     */
    public static function asActor(string $kind, ?int $onBehalfOfUserId, callable $callback, ?Model $actor = null): mixed
    {
        $previous = self::$actorContext;
        self::$actorContext = ['kind' => $kind, 'on_behalf_of' => $onBehalfOfUserId, 'actor' => $actor];

        try {
            return $callback();
        } finally {
            self::$actorContext = $previous;
        }
    }

    /**
     * SaaS-5: run a platform operator's action. Rows record the operator as the user (user_id) with
     * actor kind `platform` — attributable to the person, and distinguishable from the same person
     * acting as a member inside a tenant.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $callback
     * @return TResult
     */
    public static function asPlatformOperator(?User $operator, callable $callback): mixed
    {
        $previous = self::$actorContext;
        self::$actorContext = ['kind' => $operator === null ? 'console' : 'platform', 'on_behalf_of' => null, 'actor' => $operator];

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
    /**
     * The tenant stream an entry belongs to (null: the platform stream). Filament's tenancy scopes
     * the Audit Log resource through it; TenantScope does so for every query.
     *
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

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
