<?php

namespace App\Models;

use App\Enums\CampaignStatus;
use App\Models\Concerns\Auditable;
use Database\Factories\RecruitmentCampaignFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A recruitment campaign (Phase 5). Links existing requisitions and candidate sources; applications
 * and recruitment costs point back to it via campaign_id. Audited via Auditable; metrics come from
 * RecruitmentAnalyticsService::campaignAnalytics().
 */
#[Fillable(['name', 'code', 'description', 'owner_id', 'starts_on', 'ends_on', 'budget', 'target_hires', 'status', 'created_by'])]
class RecruitmentCampaign extends Model
{
    /** @use HasFactory<RecruitmentCampaignFactory> */
    use Auditable, HasFactory;

    protected function casts(): array
    {
        return [
            'status' => CampaignStatus::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
            'budget' => 'decimal:2',
            'target_hires' => 'integer',
        ];
    }

    /**
     * @return BelongsToMany<RecruitmentRequisition, $this>
     */
    public function requisitions(): BelongsToMany
    {
        return $this->belongsToMany(RecruitmentRequisition::class, 'recruitment_campaign_requisitions', 'recruitment_campaign_id', 'requisition_id')->withTimestamps();
    }

    /**
     * @return BelongsToMany<CandidateSource, $this>
     */
    public function sources(): BelongsToMany
    {
        return $this->belongsToMany(CandidateSource::class, 'recruitment_campaign_sources', 'recruitment_campaign_id', 'source_id')->withTimestamps();
    }

    /**
     * @return HasMany<CandidateApplication, $this>
     */
    public function applications(): HasMany
    {
        return $this->hasMany(CandidateApplication::class, 'campaign_id');
    }

    /**
     * @return HasMany<RecruitmentCost, $this>
     */
    public function costs(): HasMany
    {
        return $this->hasMany(RecruitmentCost::class, 'campaign_id');
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'owner_id');
    }

    public function isRunning(): bool
    {
        return $this->status === CampaignStatus::Active
            && ($this->starts_on === null || $this->starts_on->startOfDay()->isPast())
            && ($this->ends_on === null || $this->ends_on->endOfDay()->isFuture());
    }
}
