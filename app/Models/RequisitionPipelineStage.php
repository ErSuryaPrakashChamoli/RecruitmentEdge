<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\DescribesPipelineStage;
use DomainException;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A requisition's own copy of one pipeline stage, taken by PipelineTemplateService when a template
 * is applied. Immutable once written apart from `superseded_at` (set when a newer template is
 * applied) — rows are never deleted, so stage history keeps pointing at the stage the candidate
 * actually passed through.
 *
 * `transitions` is null for the default forward rule, or a list of {code, requires_remarks}
 * entries naming the only stages (by code, within the same snapshot) this one may move to.
 */
#[Fillable([
    'requisition_id',
    'recruitment_stage_id',
    'code',
    'name',
    'stage_type',
    'milestone',
    'icon',
    'color',
    'sort_order',
    'sla_hours',
    'requires_candidate_action',
    'requires_recruiter_action',
    'is_interview_stage',
    'is_offer_stage',
    'is_joining_stage',
    'is_terminal',
    'is_skippable',
    'allows_rejection',
    'allows_dropout',
    'requirements',
    'candidate_visible',
    'candidate_label',
    'transitions',
    'superseded_at',
])]
class RequisitionPipelineStage extends Model
{
    use BelongsToTenant, DescribesPipelineStage;

    protected static function booted(): void
    {
        static::updating(function (RequisitionPipelineStage $stage): void {
            if (array_diff(array_keys($stage->getDirty()), ['superseded_at', 'updated_at']) !== []) {
                throw new DomainException('A requisition pipeline snapshot is immutable; apply a template again to change it.');
            }
        });

        static::deleting(function (): void {
            throw new DomainException('A requisition pipeline snapshot is never deleted; it is superseded instead.');
        });
    }

    protected function casts(): array
    {
        return [
            ...$this->stageSettingCasts(),
            'transitions' => 'array',
            'superseded_at' => 'datetime',
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
     * @return BelongsTo<RecruitmentStage, $this>
     */
    public function libraryStage(): BelongsTo
    {
        return $this->belongsTo(RecruitmentStage::class, 'recruitment_stage_id');
    }

    /**
     * @return HasMany<CandidateApplication, $this>
     */
    public function applications(): HasMany
    {
        return $this->hasMany(CandidateApplication::class, 'pipeline_stage_id');
    }

    /**
     * @param  Builder<RequisitionPipelineStage>  $query
     */
    #[Scope]
    protected function current(Builder $query): void
    {
        $query->whereNull('superseded_at')->orderBy('sort_order')->orderBy('id');
    }

    /**
     * Codes of the stages this one explicitly allows, or null for the default forward rule.
     *
     * @return array<int, string>|null
     */
    public function allowedNextCodes(): ?array
    {
        if ($this->transitions === null) {
            return null;
        }

        return array_values(array_map(fn (array $t) => (string) $t['code'], $this->transitions));
    }

    public function transitionRequiresRemarks(self $target): bool
    {
        return collect($this->transitions ?? [])
            ->contains(fn (array $t) => $t['code'] === $target->code && (bool) ($t['requires_remarks'] ?? false));
    }
}
