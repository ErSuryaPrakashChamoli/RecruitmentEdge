<?php

namespace App\Models;

use App\Enums\JobPostingStatus;
use App\Enums\RequisitionStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToTenant;
use Database\Factories\JobPostingFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * The public advert for a requisition (Phase 5). Publishing state changes go through
 * JobDistributionService; content edits are audited via Auditable. Position facts (location,
 * department, experience, salary band) are always read live from the requisition.
 */
#[Fillable(['requisition_id', 'public_slug', 'title', 'summary', 'description', 'show_salary', 'closes_at', 'created_by', 'updated_by'])]
class JobPosting extends Model
{
    /** @use HasFactory<JobPostingFactory> */
    use Auditable, BelongsToTenant, HasFactory;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => 'draft',
    ];

    protected function casts(): array
    {
        return [
            'status' => JobPostingStatus::class,
            'show_salary' => 'boolean',
            'closes_at' => 'date',
            'published_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<RecruitmentRequisition, $this>
     */
    public function requisition(): BelongsTo
    {
        return $this->belongsTo(RecruitmentRequisition::class, 'requisition_id');
    }

    /**
     * @return HasMany<JobDistribution, $this>
     */
    public function distributions(): HasMany
    {
        return $this->hasMany(JobDistribution::class);
    }

    /**
     * @return HasMany<CandidateApplication, $this>
     */
    public function applications(): HasMany
    {
        return $this->hasMany(CandidateApplication::class);
    }

    /**
     * Whether the public may see and apply to this posting right now: published, not past its
     * closing date, and its requisition still Open.
     */
    public function isLive(): bool
    {
        return $this->status === JobPostingStatus::Published
            && ($this->closes_at === null || $this->closes_at->endOfDay()->isFuture())
            && $this->requisition?->status === RequisitionStatus::Open;
    }

    /**
     * @param  Builder<JobPosting>  $query
     */
    #[Scope]
    protected function live(Builder $query): void
    {
        $query->where('status', JobPostingStatus::Published)
            ->where(fn (Builder $q) => $q->whereNull('closes_at')->orWhereDate('closes_at', '>=', today()))
            ->whereHas('requisition', fn (Builder $r) => $r->where('status', RequisitionStatus::Open));
    }
}
