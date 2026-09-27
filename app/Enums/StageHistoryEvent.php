<?php

namespace App\Enums;

/**
 * What a candidate_stage_histories row records (Phase 8.5 DF-9, D31/D43). Only StageEntered is a
 * move into a stage; the other events are status or assignment changes that leave the stage as it
 * was, and never count as reaching a stage in any metric.
 *
 * "Stage exited" is not stored: a stage is exited by the next StageEntered row.
 *
 * Rows written before Phase 8.5 have no event. They are classified when read (see
 * CandidateStageHistory::scopeMilestoneEntries): a row whose new stage differs from its previous
 * stage is an entry; a same-stage row is not. Historical rows are never rewritten.
 */
enum StageHistoryEvent: string
{
    case StageEntered = 'stage_entered';
    case Rejected = 'rejected';
    case Dropped = 'dropped';
    case Held = 'held';
    case Reactivated = 'reactivated';
    case MovedRequisition = 'moved_requisition';
    case StageCorrected = 'stage_corrected';
    // Phase 8.6 (D8.6-019): a re-applied pipeline template moved the configured stage; the milestone is unchanged.
    case PipelineRemapped = 'pipeline_remapped';

    public function label(): string
    {
        return match ($this) {
            self::StageEntered => 'Stage entered',
            self::Rejected => 'Rejected',
            self::Dropped => 'Dropped out',
            self::Held => 'Put on hold',
            self::Reactivated => 'Reactivated',
            self::MovedRequisition => 'Moved to another requisition',
            self::StageCorrected => 'Stage corrected',
            self::PipelineRemapped => 'Pipeline re-applied',
        };
    }

    public function isStageEntry(): bool
    {
        return $this === self::StageEntered;
    }

    public static function forStatus(ApplicationStatus $status): self
    {
        return match ($status) {
            ApplicationStatus::Rejected => self::Rejected,
            ApplicationStatus::Dropout => self::Dropped,
            ApplicationStatus::OnHold => self::Held,
            ApplicationStatus::Active => self::Reactivated,
        };
    }
}
