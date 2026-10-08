<?php

namespace App\Services;

use App\Enums\CandidateStage;
use App\Enums\InterviewStatus;
use App\Enums\StageRequirement;
use App\Enums\StageType;
use App\Models\AuditLog;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\RecruitmentPipelineTemplate;
use App\Models\RecruitmentStage;
use App\Models\RequisitionPipelineStage;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The Stage Builder's business rules (Phase 4). Every write to the stage library and its allowed
 * transitions goes through here so the invariants hold for the Filament UI, seeders and any
 * future API alike:
 *
 * - A stage's milestone is a canonical CandidateStage; transitions may never point to an earlier
 *   milestone (analytics stay forward-only).
 * - System stages keep their code and milestone.
 * - Every change is audited (stage rows via Auditable; transition sets explicitly here, since
 *   pivot writes fire no model events).
 */
class StageConfigurationService
{
    /**
     * Seeds (idempotently) one system stage per canonical CandidateStage, so a fresh install and an
     * upgraded install both start with a library that mirrors the pipeline they already had.
     *
     * @return Collection<string, RecruitmentStage> keyed by code
     */
    public function ensureSystemStages(): Collection
    {
        $existing = RecruitmentStage::query()->whereIn('code', array_column(CandidateStage::cases(), 'value'))->get()->keyBy('code');

        foreach (CandidateStage::cases() as $milestone) {
            if ($existing->has($milestone->value)) {
                continue;
            }

            $type = StageType::forMilestone($milestone);

            $stage = new RecruitmentStage([
                'name' => $milestone->label(),
                'code' => $milestone->value,
                'stage_type' => $type,
                'milestone' => $milestone,
                'color' => $milestone->color(),
                'requires_candidate_action' => in_array($milestone, [CandidateStage::OfferAccepted, CandidateStage::JoiningConfirmed, CandidateStage::DocumentsCompleted], true),
                'requires_recruiter_action' => true,
                'is_interview_stage' => $type === StageType::Interview,
                'is_offer_stage' => $type === StageType::Offer,
                'is_joining_stage' => $type === StageType::Joining,
                'is_skippable' => true,
                'candidate_visible' => true,
                'sort_order' => ($milestone->order() + 1) * 10,
                'is_active' => true,
            ]);
            $stage->forceFill(['is_system' => true])->save();

            $existing->put($stage->code, $stage);
        }

        return $existing;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, ?Employee $actor = null): RecruitmentStage
    {
        $milestone = $this->milestoneFrom($data['milestone'] ?? null);
        $type = $data['stage_type'] instanceof StageType ? $data['stage_type'] : StageType::tryFrom((string) ($data['stage_type'] ?? ''));

        if ($type === null) {
            throw new DomainException('A valid stage type is required.');
        }

        $code = Str::slug(filled($data['code'] ?? null) ? $data['code'] : ($data['name'] ?? ''), '_');

        if ($code === '') {
            throw new DomainException('A stage needs a name.');
        }

        if (RecruitmentStage::query()->where('code', $code)->exists()) {
            throw new DomainException("A stage with the code \"{$code}\" already exists.");
        }

        return RecruitmentStage::query()->create([
            ...$this->settingsFrom($data),
            'code' => $code,
            'stage_type' => $type,
            'milestone' => $milestone,
            'sort_order' => $data['sort_order'] ?? ((int) RecruitmentStage::query()->max('sort_order') + 10),
            'is_active' => $data['is_active'] ?? true,
            'created_by' => $actor?->id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(RecruitmentStage $stage, array $data): RecruitmentStage
    {
        $attributes = $this->settingsFrom($data);

        if (array_key_exists('stage_type', $data)) {
            $attributes['stage_type'] = $data['stage_type'] instanceof StageType ? $data['stage_type'] : StageType::from((string) $data['stage_type']);
        }

        if (array_key_exists('milestone', $data)) {
            $milestone = $this->milestoneFrom($data['milestone']);

            if ($stage->is_system && $milestone !== $stage->milestone) {
                throw new DomainException('A system stage\'s milestone cannot be changed.');
            }

            $attributes['milestone'] = $milestone;
        }

        if (array_key_exists('is_active', $data)) {
            $attributes['is_active'] = (bool) $data['is_active'];
        }

        // Phase 8.6 (D8.6-019): a milestone or terminal change must keep every template that uses the
        // stage valid (canonical order, terminal last) — otherwise the change is refused.
        DB::transaction(function () use ($stage, $attributes): void {
            $stage->update($attributes);

            if ($stage->wasChanged(['milestone', 'is_terminal'])) {
                RecruitmentPipelineTemplate::query()
                    ->whereHas('templateStages', fn ($query) => $query->where('recruitment_stage_id', $stage->id))
                    ->get()
                    ->each(fn (RecruitmentPipelineTemplate $template) => app(PipelineTemplateService::class)->assertValidStageOrder($template));
            }
        });

        return $stage;
    }

    public function setActive(RecruitmentStage $stage, bool $active): RecruitmentStage
    {
        $stage->update(['is_active' => $active]);

        return $stage;
    }

    /**
     * Writes 1-based positions through the model (so each moved stage is audited). The positions
     * match what Filament's table reordering writes afterwards, so calling this from the table's
     * beforeReordering() hook keeps the audit trail without fighting the table.
     *
     * @param  array<int, int|string>  $orderedIds
     */
    public function reorder(array $orderedIds): void
    {
        DB::transaction(function () use ($orderedIds): void {
            $stages = RecruitmentStage::query()->whereKey($orderedIds)->get()->keyBy('id');

            foreach (array_values($orderedIds) as $position => $id) {
                $stages->get((int) $id)?->update(['sort_order' => $position + 1]);
            }
        });
    }

    /**
     * Replaces the explicit allowed transitions out of $stage. An empty list restores the default
     * forward rule.
     *
     * @param  array<int, array{to_stage_id: int|string, requires_remarks?: bool|null}>  $transitions
     */
    public function syncTransitions(RecruitmentStage $stage, array $transitions): void
    {
        $targets = RecruitmentStage::query()
            ->whereKey(array_map(fn (array $t) => (int) $t['to_stage_id'], $transitions))
            ->get()
            ->keyBy('id');

        $sync = [];

        foreach ($transitions as $transition) {
            $target = $targets->get((int) $transition['to_stage_id']);

            if ($target === null) {
                throw new DomainException('A transition target stage does not exist.');
            }

            if ($target->is($stage)) {
                throw new DomainException('A stage cannot transition to itself.');
            }

            if ($target->milestone->order() < $stage->milestone->order()) {
                throw new DomainException("\"{$stage->name}\" cannot transition back to the earlier stage \"{$target->name}\".");
            }

            $sync[$target->id] = ['requires_remarks' => (bool) ($transition['requires_remarks'] ?? false)];
        }

        DB::transaction(function () use ($stage, $sync): void {
            $before = $this->transitionSummary($stage);

            $stage->allowedNextStages()->sync($sync);

            $after = $this->transitionSummary($stage->fresh());

            if ($before !== $after) {
                AuditLog::record($stage, 'transitions_updated', ['allowed_next' => $before], ['allowed_next' => $after]);
            }
        });
    }

    /**
     * The requirements of $target that $application does not currently satisfy.
     *
     * @return array<int, StageRequirement>
     */
    public function unmetRequirements(CandidateApplication $application, RequisitionPipelineStage $target, ?string $remarks = null): array
    {
        return array_values(array_filter(
            $target->requirementList(),
            fn (StageRequirement $requirement): bool => ! $this->satisfies($application, $requirement, $remarks),
        ));
    }

    private function satisfies(CandidateApplication $application, StageRequirement $requirement, ?string $remarks): bool
    {
        return match ($requirement) {
            StageRequirement::Remarks => filled($remarks),
            StageRequirement::Resume => filled($application->candidate?->resume_path)
                || $application->candidate?->documents()->where('document_type', 'resume')->exists(),
            StageRequirement::Email => filled($application->candidate?->email),
            StageRequirement::InterviewScheduled => $application->interviews()
                ->whereNotIn('status', [InterviewStatus::Cancelled, InterviewStatus::NoShow])
                ->exists(),
            StageRequirement::InterviewFeedback => $application->interviews()->whereHas('feedback')->exists(),
            StageRequirement::Offer => $application->offers()->exists(),
            StageRequirement::JoiningRecord => $application->joining()->exists(),
        };
    }

    /**
     * @return array<int, string>
     */
    private function transitionSummary(RecruitmentStage $stage): array
    {
        return $stage->allowedNextStages()
            ->orderBy('code')
            ->get()
            ->map(fn (RecruitmentStage $to) => $to->code.($to->pivot->requires_remarks ? ' (remarks required)' : ''))
            ->all();
    }

    private function milestoneFrom(mixed $value): CandidateStage
    {
        $milestone = $value instanceof CandidateStage ? $value : CandidateStage::tryFrom((string) $value);

        if ($milestone === null) {
            throw new DomainException('Every stage must map to a canonical pipeline milestone.');
        }

        return $milestone;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function settingsFrom(array $data): array
    {
        $keys = [
            'name', 'description', 'icon', 'color', 'sla_hours',
            'requires_candidate_action', 'requires_recruiter_action',
            'is_interview_stage', 'is_offer_stage', 'is_joining_stage',
            'is_terminal', 'is_skippable', 'allows_rejection', 'allows_dropout',
            'candidate_visible', 'candidate_label',
        ];

        $attributes = array_intersect_key($data, array_flip($keys));

        if (array_key_exists('requirements', $data)) {
            $attributes['requirements'] = collect($data['requirements'] ?? [])
                ->map(fn (mixed $r) => $r instanceof StageRequirement ? $r->value : (string) $r)
                ->filter(fn (string $r) => StageRequirement::tryFrom($r) !== null)
                ->values()
                ->all();
        }

        if (array_key_exists('sla_hours', $attributes) && blank($attributes['sla_hours'])) {
            $attributes['sla_hours'] = null;
        }

        return $attributes;
    }
}
