<?php

namespace App\Models;

use App\Enums\TalentPoolStatus;
use App\Enums\TalentPoolVisibility;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\ReferencesActiveMasterData;
use App\Services\HierarchyService;
use Database\Factories\TalentPoolFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A reusable group of candidates independent of any requisition (Phase 4). Membership changes go
 * through TalentPoolService; the pool itself is audited via Auditable. Visibility combines the
 * pool's `visibility` with the existing hierarchy (see scopeVisibleTo / TalentPoolPolicy).
 */
#[Fillable(['name', 'slug', 'description', 'status', 'visibility', 'owner_id', 'department_id', 'tags', 'criteria', 'created_by'])]
class TalentPool extends Model
{
    /** @use HasFactory<TalentPoolFactory> */
    use Auditable, BelongsToTenant, HasFactory, ReferencesActiveMasterData;

    protected function casts(): array
    {
        return [
            'status' => TalentPoolStatus::class,
            'visibility' => TalentPoolVisibility::class,
            'tags' => 'array',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * Every membership row, including removed ones (the pool's membership history).
     *
     * @return HasMany<TalentPoolMembership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(TalentPoolMembership::class);
    }

    /**
     * @return HasMany<TalentPoolMembership, $this>
     */
    public function activeMemberships(): HasMany
    {
        return $this->memberships()->whereNull('removed_at');
    }

    /**
     * Current members.
     *
     * @return BelongsToMany<Candidate, $this>
     */
    public function candidates(): BelongsToMany
    {
        return $this->belongsToMany(Candidate::class, 'talent_pool_memberships')
            ->using(TenantPivot::class)
            ->wherePivotNull('removed_at')
            ->withPivot(['source', 'reason', 'notes', 'added_by', 'added_at']);
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'owner_id');
    }

    /**
     * @return BelongsTo<Department, $this>
     */
    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class)->withTrashed();
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }

    public function isActive(): bool
    {
        return $this->status === TalentPoolStatus::Active;
    }

    /**
     * Pools $user may see: all for hierarchy.view-all; otherwise Organization pools, pools owned
     * by anyone in the user's hierarchy (themselves or below), and Team pools owned by someone
     * in the user's management chain.
     *
     * @param  Builder<TalentPool>  $query
     */
    #[Scope]
    protected function visibleTo(Builder $query, User $user): void
    {
        $hierarchy = app(HierarchyService::class);
        $visibleIds = $hierarchy->visibleEmployeeIdsFor($user);

        if ($visibleIds === null) {
            return;
        }

        $ancestorIds = $user->employee_id !== null ? $hierarchy->ancestorIdsOf($user->employee_id) : collect();

        $query->where(function (Builder $q) use ($visibleIds, $ancestorIds): void {
            $q->where('visibility', TalentPoolVisibility::Organization)
                ->orWhereIn('owner_id', $visibleIds)
                ->orWhere(fn (Builder $team) => $team->where('visibility', TalentPoolVisibility::Team)->whereIn('owner_id', $ancestorIds));
        });
    }

    /**
     * @param  Builder<TalentPool>  $query
     */
    #[Scope]
    protected function active(Builder $query): void
    {
        $query->where('status', TalentPoolStatus::Active);
    }

    /**
     * Phase 8.6 (D8.6-005): master data taken up by this record must be in service.
     *
     * @return array<string, class-string<Model>>
     */
    public function activeMasterDataReferences(): array
    {
        return [
            'department_id' => Department::class,
        ];
    }
}
