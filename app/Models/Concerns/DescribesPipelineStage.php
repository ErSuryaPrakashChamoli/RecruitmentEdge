<?php

namespace App\Models\Concerns;

use App\Enums\CandidateStage;
use App\Enums\StageRequirement;
use App\Enums\StageType;

/**
 * Shared behaviour of a library stage (RecruitmentStage) and its per-requisition snapshot
 * (RequisitionPipelineStage) — both carry the same stage settings, so the casts and read helpers
 * live once here.
 */
trait DescribesPipelineStage
{
    /**
     * Stage configuration columns copied verbatim from a library stage into a requisition snapshot.
     *
     * @var array<int, string>
     */
    public const array STAGE_SETTING_ATTRIBUTES = [
        'code',
        'name',
        'stage_type',
        'milestone',
        'icon',
        'color',
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
    ];

    /**
     * @return array<string, string>
     */
    protected function stageSettingCasts(): array
    {
        return [
            'stage_type' => StageType::class,
            'milestone' => CandidateStage::class,
            'sla_hours' => 'integer',
            'requires_candidate_action' => 'boolean',
            'requires_recruiter_action' => 'boolean',
            'is_interview_stage' => 'boolean',
            'is_offer_stage' => 'boolean',
            'is_joining_stage' => 'boolean',
            'is_terminal' => 'boolean',
            'is_skippable' => 'boolean',
            'allows_rejection' => 'boolean',
            'allows_dropout' => 'boolean',
            'requirements' => 'array',
            'candidate_visible' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    /**
     * @return array<int, StageRequirement>
     */
    public function requirementList(): array
    {
        return collect($this->requirements ?? [])
            ->map(fn (string $value) => StageRequirement::tryFrom($value))
            ->filter()
            ->values()
            ->all();
    }

    public function requires(StageRequirement $requirement): bool
    {
        return in_array($requirement, $this->requirementList(), true);
    }

    public function resolvedIcon(): string
    {
        return filled($this->icon) ? $this->icon : $this->stage_type->defaultIcon();
    }

    /**
     * What a candidate sees for this stage in the portal — never an internal-only stage name.
     */
    public function candidateFacingLabel(): string
    {
        return filled($this->candidate_label) ? $this->candidate_label : $this->name;
    }

    /**
     * Structured, AI-consumable description of the stage (Phase 7 Next Best Action / Hiring
     * Health read this rather than scraping labels).
     *
     * @return array{code: string, name: string, type: string, milestone: string, sla_hours: int|null, terminal: bool, skippable: bool, requirements: array<int, string>, associations: array<int, string>}
     */
    public function toStageContext(): array
    {
        return [
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->stage_type->value,
            'milestone' => $this->milestone->value,
            'sla_hours' => $this->sla_hours,
            'terminal' => (bool) $this->is_terminal,
            'skippable' => (bool) $this->is_skippable,
            'requirements' => array_map(fn (StageRequirement $r) => $r->value, $this->requirementList()),
            'associations' => array_keys(array_filter([
                'interview' => $this->is_interview_stage,
                'offer' => $this->is_offer_stage,
                'joining' => $this->is_joining_stage,
                'candidate_action' => $this->requires_candidate_action,
                'recruiter_action' => $this->requires_recruiter_action,
            ])),
        ];
    }
}
