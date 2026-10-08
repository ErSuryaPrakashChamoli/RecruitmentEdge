<?php

namespace App\Models;

use App\Enums\CandidateStage;
use App\Enums\StageHistoryEvent;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Contracts\Database\Query\Builder as BuilderContract;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An immutable record of every stage a candidate's application passed through — never updated or
 * deleted. See StageTransitionService, the only writer of this table.
 *
 * Phase 8.5 (DF-9): status and assignment changes also write a row (the stage left as it was), so
 * a metric that means "reached a stage" must use milestoneEntries(), never a bare new_stage filter.
 */
#[Fillable(['candidate_application_id', 'previous_stage', 'new_stage', 'event', 'previous_pipeline_stage_id', 'new_pipeline_stage_id', 'changed_by', 'remarks', 'is_override'])]
class CandidateStageHistory extends Model
{
    use BelongsToTenant;

    public const ?string UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'previous_stage' => CandidateStage::class,
            'new_stage' => CandidateStage::class,
            'event' => StageHistoryEvent::class,
            'is_override' => 'boolean',
        ];
    }

    /**
     * Genuine moves into a canonical stage (milestone): rows recorded as a stage entry that changed
     * the milestone, and legacy rows (no event, before Phase 8.5) whose new stage differs from the
     * previous one. Status changes, requisition moves and same-milestone configured-stage moves are
     * excluded.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function milestoneEntries(Builder $query): void
    {
        self::constrainToMilestoneEntries($query, $query->getModel()->getTable());
    }

    /**
     * The milestone-entry rule for a plain query builder (subqueries, joins).
     */
    public static function constrainToMilestoneEntries(BuilderContract|Builder $query, string $table = 'candidate_stage_histories'): void
    {
        $query->where(fn ($q) => $q->where("{$table}.event", StageHistoryEvent::StageEntered->value)->orWhereNull("{$table}.event"))
            ->where(fn ($q) => $q->whereNull("{$table}.previous_stage")->orWhereColumn("{$table}.previous_stage", '!=', "{$table}.new_stage"));
    }

    /**
     * Moves into a configured pipeline stage (including a same-milestone configured move): stage
     * entries, and legacy rows that changed the configured stage or the milestone.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function pipelineStageEntries(Builder $query): void
    {
        $query->where(function (Builder $q): void {
            $q->where($q->qualifyColumn('event'), StageHistoryEvent::StageEntered->value)
                ->orWhere(fn (Builder $legacy) => $legacy->whereNull($legacy->qualifyColumn('event'))->where(fn (Builder $changed) => $changed
                    ->whereNull($changed->qualifyColumn('previous_stage'))
                    ->orWhereColumn($changed->qualifyColumn('previous_stage'), '!=', $changed->qualifyColumn('new_stage'))
                    ->orWhereColumn($changed->qualifyColumn('previous_pipeline_stage_id'), '!=', $changed->qualifyColumn('new_pipeline_stage_id'))
                    ->orWhere(fn (Builder $n) => $n->whereNull($n->qualifyColumn('previous_pipeline_stage_id'))->whereNotNull($n->qualifyColumn('new_pipeline_stage_id')))));
        });
    }

    /**
     * Whether this row is a genuine move into a new milestone (same rule as milestoneEntries()).
     */
    public function isMilestoneEntry(): bool
    {
        if ($this->event !== null && ! $this->event->isStageEntry()) {
            return false;
        }

        return $this->previous_stage === null || $this->previous_stage !== $this->new_stage;
    }

    /**
     * @return BelongsTo<CandidateApplication, $this>
     */
    public function candidateApplication(): BelongsTo
    {
        return $this->belongsTo(CandidateApplication::class);
    }

    /**
     * @return BelongsTo<RequisitionPipelineStage, $this>
     */
    public function previousPipelineStage(): BelongsTo
    {
        return $this->belongsTo(RequisitionPipelineStage::class, 'previous_pipeline_stage_id');
    }

    /**
     * @return BelongsTo<RequisitionPipelineStage, $this>
     */
    public function newPipelineStage(): BelongsTo
    {
        return $this->belongsTo(RequisitionPipelineStage::class, 'new_pipeline_stage_id');
    }

    /**
     * The configured stage name when the move happened on a configured pipeline, else the
     * canonical milestone label.
     */
    public function newStageLabel(): string
    {
        return $this->newPipelineStage?->name ?? $this->new_stage->label();
    }

    /**
     * @return BelongsTo<Employee, $this>
     */
    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'changed_by');
    }
}
