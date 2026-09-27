<?php

namespace App\Services;

use App\Enums\CandidateStage;
use App\Enums\RequisitionStatus;
use App\Enums\StageHistoryEvent;
use App\Models\AuditLog;
use App\Models\CandidateApplication;
use App\Models\CandidateStageHistory;
use App\Models\Employee;
use App\Models\RecruitmentPipelineTemplate;
use App\Models\RecruitmentPipelineTemplateStage;
use App\Models\RecruitmentPipelineTemplateVersion;
use App\Models\RecruitmentRequisition;
use App\Models\RecruitmentStage;
use App\Models\RequisitionPipelineStage;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pipeline templates and their application to requisitions (Phase 4).
 *
 * Versioning/snapshot strategy: a template's stage list is versioned (`version` bumps on every
 * structural change), and applying a template copies every stage setting — plus the allowed
 * transitions restricted to the template's own stages — into `requisition_pipeline_stages` rows
 * owned by the requisition. Later template or stage-library edits therefore never alter an
 * existing requisition. Re-applying a template supersedes (never deletes) the old snapshot and
 * remaps each application to the equivalent stage, so stage history stays resolvable.
 */
class PipelineTemplateService
{
    public const string DEFAULT_TEMPLATE_SLUG = 'standard-corporate-hiring';

    public function __construct(private readonly StageConfigurationService $stages) {}

    /**
     * Idempotently guarantees the system stages and a default template (all canonical stages, in
     * canonical order) exist — the safe pipeline every legacy requisition is given.
     */
    public function ensureDefaultTemplate(): RecruitmentPipelineTemplate
    {
        $default = RecruitmentPipelineTemplate::query()->where('is_default', true)->first();

        if ($default !== null) {
            return $default;
        }

        $systemStages = $this->stages->ensureSystemStages();

        $template = RecruitmentPipelineTemplate::query()->where('slug', self::DEFAULT_TEMPLATE_SLUG)->first()
            ?? $this->create(
                [
                    'name' => 'Standard Corporate Hiring',
                    'slug' => self::DEFAULT_TEMPLATE_SLUG,
                    'description' => 'The full canonical pipeline — every requisition without a chosen template uses this.',
                ],
                collect(CandidateStage::cases())
                    ->map(fn (CandidateStage $stage) => ['recruitment_stage_id' => $systemStages->get($stage->value)->id])
                    ->all(),
            );

        return $this->setDefault($template);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<int, array{recruitment_stage_id: int|string, sla_hours?: int|string|null, is_skippable?: bool|null}>  $stages
     */
    public function create(array $data, array $stages, ?Employee $actor = null): RecruitmentPipelineTemplate
    {
        $rows = $this->validatedStageRows($stages, []);

        return DB::transaction(function () use ($data, $rows, $actor): RecruitmentPipelineTemplate {
            $template = RecruitmentPipelineTemplate::query()->create([
                'name' => $data['name'],
                'slug' => $this->uniqueSlug($data['slug'] ?? $data['name']),
                'description' => $data['description'] ?? null,
                'is_active' => $data['is_active'] ?? true,
                'is_default' => false,
                'cloned_from_id' => $data['cloned_from_id'] ?? null,
                'created_by' => $actor?->id,
            ]);

            $this->writeStageRows($template, $rows);
            $this->recordVersion($template, $actor);

            return $template;
        });
    }

    /**
     * Updates details and, when $stages is given, replaces the stage list — bumping the version
     * and auditing the before/after stage codes only when the list actually changed.
     *
     * @param  array<string, mixed>  $data
     * @param  array<int, array{recruitment_stage_id: int|string, sla_hours?: int|string|null, is_skippable?: bool|null}>|null  $stages
     */
    public function update(RecruitmentPipelineTemplate $template, array $data, ?array $stages = null): RecruitmentPipelineTemplate
    {
        return DB::transaction(function () use ($template, $data, $stages): RecruitmentPipelineTemplate {
            $details = array_intersect_key($data, array_flip(['name', 'description']));

            if ($details !== []) {
                $template->update($details);
            }

            if ($stages === null) {
                return $template;
            }

            $existingStageIds = $template->templateStages()->pluck('recruitment_stage_id')->all();
            $rows = $this->validatedStageRows($stages, $existingStageIds);
            $before = $this->stageSummary($template);
            // Phase 8.6 (D8.6-018): a template defined before versions were stored keeps its current
            // definition as the version it is now, before it changes.
            $this->recordVersion($template);

            $template->templateStages()->delete();
            $this->writeStageRows($template, $rows);

            $after = $this->stageSummary($template);

            if ($before !== $after) {
                $template->forceFill(['version' => $template->version + 1])->save();
                $this->recordVersion($template);

                AuditLog::record($template, 'stages_updated', ['stages' => $before], ['stages' => $after]);
            }

            return $template;
        });
    }

    public function clone(RecruitmentPipelineTemplate $template, string $name, ?Employee $actor = null): RecruitmentPipelineTemplate
    {
        $stages = $template->templateStages()->get()->map(fn (RecruitmentPipelineTemplateStage $row) => [
            'recruitment_stage_id' => $row->recruitment_stage_id,
            'sla_hours' => $row->sla_hours,
            'is_skippable' => $row->is_skippable,
        ])->all();

        return $this->create([
            'name' => $name,
            'description' => $template->description,
            'cloned_from_id' => $template->id,
        ], $stages, $actor);
    }

    public function setDefault(RecruitmentPipelineTemplate $template): RecruitmentPipelineTemplate
    {
        if (! $template->is_active) {
            throw new DomainException('Only an active template can be the default.');
        }

        return DB::transaction(function () use ($template): RecruitmentPipelineTemplate {
            RecruitmentPipelineTemplate::query()
                ->where('is_default', true)
                ->whereKeyNot($template->id)
                ->get()
                ->each(fn (RecruitmentPipelineTemplate $other) => $other->update(['is_default' => false]));

            $template->update(['is_default' => true]);

            return $template;
        });
    }

    public function setActive(RecruitmentPipelineTemplate $template, bool $active): RecruitmentPipelineTemplate
    {
        if (! $active && $template->is_default) {
            throw new DomainException('The default template cannot be deactivated. Make another template the default first.');
        }

        $template->update(['is_active' => $active]);

        return $template;
    }

    /**
     * Snapshots $template onto $requisition (Template → Pipeline configuration → Requisition).
     * Any previous snapshot is superseded and every application is remapped to the equivalent
     * stage of the new one (same code if present, else by milestone).
     *
     * Phase 8.6 (D8.6-019): the first application needs only the requisition itself (callers check
     * requisitions.update). Re-applying a template to a requisition that already has a pipeline is
     * a governed change: the actor needs pipeline.configure, the requisition must not be closed or
     * cancelled, and a reason is required. Each application whose configured stage changes gets a
     * `pipeline_remapped` stage-history row (never a stage entry — its milestone is unchanged), and
     * the re-application is audited with the reason and the number of applications moved.
     *
     * @return Collection<int, RequisitionPipelineStage>
     */
    public function applyToRequisition(RecruitmentRequisition $requisition, RecruitmentPipelineTemplate $template, ?Employee $actor = null, ?string $reason = null): Collection
    {
        if (! $template->is_active) {
            throw new DomainException("The template \"{$template->name}\" is inactive and cannot be applied.");
        }

        $templateStages = $template->templateStages()->with('stage.allowedNextStages')->get();

        if ($templateStages->isEmpty()) {
            throw new DomainException("The template \"{$template->name}\" has no stages.");
        }

        $reapply = $requisition->pipeline_applied_at !== null;
        $reason = filled($reason) ? trim((string) $reason) : null;

        if ($reapply) {
            $this->guardReapply($requisition, $actor, $reason);
        }

        return DB::transaction(function () use ($requisition, $template, $templateStages, $actor, $reapply, $reason): Collection {
            $this->recordVersion($template, $actor);

            $previousTemplateId = $requisition->pipeline_template_id;
            $previousVersion = $requisition->pipeline_template_version;
            $previousStages = RequisitionPipelineStage::query()->where('requisition_id', $requisition->id)->current()->get()->keyBy('id');

            $previousStages->each(fn (RequisitionPipelineStage $stage) => $stage->update(['superseded_at' => now()]));

            $codesInTemplate = $templateStages->map(fn (RecruitmentPipelineTemplateStage $row) => $row->stage->code)->all();

            $snapshot = $templateStages->values()->map(function (RecruitmentPipelineTemplateStage $row, int $position) use ($requisition, $codesInTemplate): RequisitionPipelineStage {
                $stage = $row->stage;

                $explicit = $stage->allowedNextStages
                    ->filter(fn (RecruitmentStage $to) => in_array($to->code, $codesInTemplate, true))
                    ->map(fn (RecruitmentStage $to) => ['code' => $to->code, 'requires_remarks' => (bool) $to->pivot->requires_remarks])
                    ->values()
                    ->all();

                return RequisitionPipelineStage::query()->create([
                    ...collect(RecruitmentStage::STAGE_SETTING_ATTRIBUTES)->mapWithKeys(fn (string $key) => [$key => $stage->getAttribute($key)])->all(),
                    'requisition_id' => $requisition->id,
                    'recruitment_stage_id' => $stage->id,
                    'sort_order' => ($position + 1) * 10,
                    'sla_hours' => $row->sla_hours ?? $stage->sla_hours,
                    'is_skippable' => $row->is_skippable ?? $stage->is_skippable,
                    'transitions' => $explicit === [] ? null : $explicit,
                ]);
            });

            $requisition->forceFill([
                'pipeline_template_id' => $template->id,
                'pipeline_template_version' => $template->version,
                'pipeline_applied_at' => now(),
                'pipeline_applied_by' => $actor?->id,
            ])->save();

            $moved = $this->remapApplications($requisition, $previousStages, $snapshot, $reapply ? $actor : null, $reason);

            AuditLog::record(
                $requisition,
                $reapply ? 'pipeline_reapplied' : 'pipeline_applied',
                ['pipeline_template_id' => $previousTemplateId, 'pipeline_template_version' => $previousVersion],
                ['pipeline_template_id' => $template->id, 'pipeline_template_version' => $template->version, 'stages' => $codesInTemplate, 'applications_remapped' => $moved],
                $reason,
            );

            return $snapshot;
        });
    }

    /**
     * The snapshot stage an application at canonical $milestone belongs in: the first stage mapped
     * to exactly that milestone at or after $from, else the furthest stage whose milestone does
     * not exceed it. Null when the pipeline has no such stage.
     *
     * @param  Collection<int, RequisitionPipelineStage>  $pipeline  ordered current snapshot
     */
    public function stageForMilestone(Collection $pipeline, CandidateStage $milestone, ?RequisitionPipelineStage $from = null): ?RequisitionPipelineStage
    {
        $candidates = $from === null
            ? $pipeline
            : $pipeline->filter(fn (RequisitionPipelineStage $stage) => $stage->sort_order >= $from->sort_order);

        return $candidates->first(fn (RequisitionPipelineStage $stage) => $stage->milestone === $milestone)
            ?? $candidates->filter(fn (RequisitionPipelineStage $stage) => $stage->milestone->order() <= $milestone->order())->last()
            ?? $from;
    }

    /**
     * Where a brand-new application on $requisitionId starts, or null when the requisition has no
     * configured pipeline.
     */
    public function initialStageFor(int $requisitionId, CandidateStage $milestone): ?RequisitionPipelineStage
    {
        $pipeline = RequisitionPipelineStage::query()->where('requisition_id', $requisitionId)->current()->get();

        return $pipeline->isEmpty() ? null : $this->stageForMilestone($pipeline, $milestone);
    }

    /**
     * Backfill for legacy data (see the recruitment:assign-default-pipelines command): gives every
     * requisition without a pipeline the default template, and every application still without a
     * pipeline stage the stage matching its current milestone. Idempotent; never touches history.
     *
     * @return array{requisitions: int, applications: int}
     */
    public function assignDefaultPipelines(?Employee $actor = null): array
    {
        $default = $this->ensureDefaultTemplate();
        $requisitions = 0;

        RecruitmentRequisition::query()
            ->whereNull('pipeline_applied_at')
            ->chunkById(100, function (Collection $chunk) use ($default, $actor, &$requisitions): void {
                foreach ($chunk as $requisition) {
                    $this->applyToRequisition($requisition, $default, $actor);
                    $requisitions++;
                }
            });

        $applications = 0;

        CandidateApplication::query()
            ->whereNull('pipeline_stage_id')
            ->whereHas('requisition', fn ($q) => $q->whereNotNull('pipeline_applied_at'))
            ->chunkById(200, function (Collection $chunk) use (&$applications): void {
                $pipelines = RequisitionPipelineStage::query()
                    ->whereIn('requisition_id', $chunk->pluck('requisition_id')->unique())
                    ->current()
                    ->get()
                    ->groupBy('requisition_id');

                foreach ($chunk as $application) {
                    $stage = $this->stageForMilestone($pipelines->get($application->requisition_id, collect()), $application->current_stage);

                    if ($stage !== null) {
                        $application->forceFill(['pipeline_stage_id' => $stage->id])->saveQuietly();
                        $applications++;
                    }
                }
            });

        return ['requisitions' => $requisitions, 'applications' => $applications];
    }

    /**
     * Moves every application to the equivalent stage of the new snapshot. On a re-application,
     * each application whose configured stage changes gets a `pipeline_remapped` history row
     * (same milestone, previous → new configured stage), so the move is on record. Returns how
     * many applications changed configured stage.
     *
     * @param  Collection<int, RequisitionPipelineStage>  $previousStages  keyed by id
     * @param  Collection<int, RequisitionPipelineStage>  $snapshot
     */
    private function remapApplications(RecruitmentRequisition $requisition, Collection $previousStages, Collection $snapshot, ?Employee $actor, ?string $reason): int
    {
        $byCode = $snapshot->keyBy('code');
        $moved = 0;
        $record = $previousStages->isNotEmpty();

        CandidateApplication::query()
            ->where('requisition_id', $requisition->id)
            ->select(['id', 'requisition_id', 'current_stage', 'pipeline_stage_id'])
            ->chunkById(200, function (Collection $applications) use ($previousStages, $byCode, $snapshot, $actor, $reason, $record, &$moved): void {
                $history = [];

                foreach ($applications as $application) {
                    $previousCode = $previousStages->get($application->pipeline_stage_id)?->code;

                    $target = ($previousCode !== null ? $byCode->get($previousCode) : null)
                        ?? $this->stageForMilestone($snapshot, $application->current_stage);

                    if ($application->pipeline_stage_id === $target?->id) {
                        continue;
                    }

                    if ($record) {
                        $history[] = [
                            'candidate_application_id' => $application->id,
                            'previous_stage' => $application->current_stage->value,
                            'new_stage' => $application->current_stage->value,
                            'event' => StageHistoryEvent::PipelineRemapped->value,
                            'previous_pipeline_stage_id' => $application->pipeline_stage_id,
                            'new_pipeline_stage_id' => $target?->id,
                            'changed_by' => $actor?->id,
                            'remarks' => $reason !== null ? mb_substr("Pipeline re-applied: {$reason}", 0, 1000) : 'Pipeline re-applied',
                            'created_at' => now(),
                        ];
                    }

                    $application->forceFill(['pipeline_stage_id' => $target?->id])->saveQuietly();
                    $moved++;
                }

                if ($history !== []) {
                    CandidateStageHistory::query()->insert($history);
                }
            });

        return $moved;
    }

    private function guardReapply(RecruitmentRequisition $requisition, ?Employee $actor, ?string $reason): void
    {
        if (! (bool) $actor?->user?->can('pipeline.configure')) {
            throw new DomainException('Re-applying a pipeline to a requisition that already has one needs the pipeline.configure permission.');
        }

        if (in_array($requisition->status, [RequisitionStatus::Closed, RequisitionStatus::Cancelled], true)) {
            throw new DomainException("The requisition is {$requisition->status->label()}; its pipeline can no longer be changed.");
        }

        if ($reason === null) {
            throw new DomainException('A reason is required to re-apply a pipeline template.');
        }
    }

    /**
     * Phase 8.6 (D8.6-018): stores the template's current stage list as its current version, once.
     */
    private function recordVersion(RecruitmentPipelineTemplate $template, ?Employee $actor = null): void
    {
        if (RecruitmentPipelineTemplateVersion::query()->where('pipeline_template_id', $template->id)->where('version', $template->version)->exists()) {
            return;
        }

        RecruitmentPipelineTemplateVersion::query()->create([
            'pipeline_template_id' => $template->id,
            'version' => $template->version,
            'stages' => $template->templateStages()->with('stage')->get()->map(fn (RecruitmentPipelineTemplateStage $row) => [
                'recruitment_stage_id' => $row->recruitment_stage_id,
                'code' => $row->stage->code,
                'name' => $row->stage->name,
                'milestone' => $row->stage->milestone->value,
                'is_terminal' => (bool) $row->stage->is_terminal,
                'sort_order' => $row->sort_order,
                'sla_hours' => $row->sla_hours ?? $row->stage->sla_hours,
                'is_skippable' => $row->is_skippable ?? $row->stage->is_skippable,
            ])->all(),
            'created_by' => $actor?->id,
        ]);
    }

    /**
     * Phase 8.6 (D8.6-019): re-checks a saved template against the current stage library (after a
     * library stage's milestone or terminal flag changed). Throws DomainException naming the
     * template when its order no longer holds.
     */
    public function assertValidStageOrder(RecruitmentPipelineTemplate $template): void
    {
        $ids = $template->templateStages()->orderBy('sort_order')->pluck('recruitment_stage_id')->all();

        try {
            $this->validatedStageRows(array_map(fn (int $id) => ['recruitment_stage_id' => $id], $ids), $ids);
        } catch (DomainException $e) {
            throw new DomainException("This change would break the pipeline template \"{$template->name}\": {$e->getMessage()}");
        }
    }

    /**
     * Validates a stage list: non-empty, no repeats, newly added stages active, milestones never
     * going backwards (so forward-only analytics hold), and terminal stages only at the end.
     *
     * @param  array<int, array{recruitment_stage_id: int|string, sla_hours?: int|string|null, is_skippable?: bool|null}>  $stages
     * @param  array<int, int>  $existingStageIds  stages already on the template (may be kept even if since deactivated)
     * @return array<int, array{recruitment_stage_id: int, sla_hours: int|null, is_skippable: bool|null}>
     */
    private function validatedStageRows(array $stages, array $existingStageIds): array
    {
        $rows = array_values(array_map(fn (array $row) => [
            'recruitment_stage_id' => (int) $row['recruitment_stage_id'],
            'sla_hours' => filled($row['sla_hours'] ?? null) ? (int) $row['sla_hours'] : null,
            'is_skippable' => isset($row['is_skippable']) ? (bool) $row['is_skippable'] : null,
        ], $stages));

        if ($rows === []) {
            throw new DomainException('A pipeline template needs at least one stage.');
        }

        $ids = array_column($rows, 'recruitment_stage_id');

        if (count($ids) !== count(array_unique($ids))) {
            throw new DomainException('A stage can appear only once in a pipeline template.');
        }

        $library = RecruitmentStage::query()->whereKey($ids)->get()->keyBy('id');
        $previous = null;
        $terminalSeen = null;

        foreach ($ids as $id) {
            $stage = $library->get($id) ?? throw new DomainException('A selected stage no longer exists.');

            if (! $stage->is_active && ! in_array($id, $existingStageIds, true)) {
                throw new DomainException("The stage \"{$stage->name}\" is inactive and cannot be added.");
            }

            if ($previous !== null && $stage->milestone->order() < $previous->milestone->order()) {
                throw new DomainException("\"{$stage->name}\" ({$stage->milestone->label()}) cannot come after \"{$previous->name}\" ({$previous->milestone->label()}) — stages must follow the canonical pipeline order.");
            }

            if ($terminalSeen !== null && ! $stage->is_terminal) {
                throw new DomainException("\"{$stage->name}\" cannot come after the terminal stage \"{$terminalSeen->name}\".");
            }

            if ($stage->is_terminal) {
                $terminalSeen = $stage;
            }

            $previous = $stage;
        }

        return $rows;
    }

    /**
     * @param  array<int, array{recruitment_stage_id: int, sla_hours: int|null, is_skippable: bool|null}>  $rows
     */
    private function writeStageRows(RecruitmentPipelineTemplate $template, array $rows): void
    {
        foreach ($rows as $position => $row) {
            $template->templateStages()->create([...$row, 'sort_order' => ($position + 1) * 10]);
        }
    }

    /**
     * @return array<int, string>
     */
    private function stageSummary(RecruitmentPipelineTemplate $template): array
    {
        return $template->templateStages()->with('stage:id,code')->get()
            ->map(fn (RecruitmentPipelineTemplateStage $row) => $row->stage->code
                .($row->sla_hours !== null ? " [sla {$row->sla_hours}h]" : '')
                .($row->is_skippable !== null ? ($row->is_skippable ? ' [skippable]' : ' [required]') : ''))
            ->all();
    }

    private function uniqueSlug(string $source): string
    {
        $base = Str::slug($source) ?: 'pipeline';
        $slug = $base;
        $suffix = 2;

        while (RecruitmentPipelineTemplate::query()->where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
