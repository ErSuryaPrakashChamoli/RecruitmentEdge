<?php

namespace App\Services\Intelligence;

use App\Enums\MemoryType;
use App\Enums\RequirementLevel;
use App\Enums\RoleDnaCategory;
use App\Enums\RoleDnaOrigin;
use App\Enums\StageType;
use App\Models\HiringMemoryRecord;
use App\Models\InterviewFeedback;
use App\Models\OutcomeInsight;
use App\Models\RecruitmentRequisition;
use App\Services\Intelligence\Data\EvidenceItem;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Builds the deterministic part of a requisition's Role DNA™ (Phase 7):
 *
 * - configured attributes straight from the requisition (skills, experience, qualification,
 *   location, work mode, compensation, identity);
 * - inferred attributes (interview dimensions from the rating criteria — culture fit deliberately
 *   excluded as a known bias proxy — and the pipeline's interview rounds);
 * - historical patterns from Hiring Memory for the same designation, only when at least
 *   MIN_HISTORY comparable hires exist — otherwise an explicit "insufficient history" attribute.
 *   History is never invented.
 *
 * Returns attributes plus their evidence keyed by attribute key.
 */
class RoleDnaBuilder
{
    public const string GENERATOR = 'role-dna-builder';

    public const string VERSION = '1';

    public const int MIN_HISTORY = 3;

    /**
     * Interview rating criteria deliberately left out of intelligence.
     *
     * @var array<int, string>
     */
    public const array EXCLUDED_CRITERIA = ['culture_fit'];

    /**
     * @return array{attributes: array<int, array<string, mixed>>, evidence: array<string, array<int, EvidenceItem>>}
     */
    public function build(RecruitmentRequisition $requisition): array
    {
        $requisition->loadMissing(['designation', 'department', 'location', 'pipelineStages']);
        $attributes = [];
        $evidence = [];

        $add = function (string $key, RoleDnaCategory $category, string $label, ?string $value, RequirementLevel $level, RoleDnaOrigin $origin, array $items, array $data = [], ?string $note = null) use (&$attributes, &$evidence): void {
            $attributes[] = [
                'key' => $key,
                'category' => $category->value,
                'label' => $label,
                'value' => $value,
                'level' => $level->value,
                'origin' => $origin->value,
                'active' => true,
                'note' => $note,
                'data' => $data,
            ];
            $evidence[$key] = $items;
        };

        // Configured — the requisition as written.
        if ($requisition->designation !== null) {
            $add('identity:designation', RoleDnaCategory::Identity, 'Designation', $requisition->designation->name, RequirementLevel::Informational, RoleDnaOrigin::Configured,
                [EvidenceItem::configured('identity:designation', 'Requisition designation', $requisition->designation->name, $requisition)]);
        }

        if ($requisition->department !== null) {
            $add('identity:department', RoleDnaCategory::Identity, 'Department', $requisition->department->name, RequirementLevel::Informational, RoleDnaOrigin::Configured,
                [EvidenceItem::configured('identity:department', 'Requisition department', $requisition->department->name, $requisition)]);
        }

        $add('identity:openings', RoleDnaCategory::Identity, 'Openings', (string) $requisition->openings, RequirementLevel::Informational, RoleDnaOrigin::Configured,
            [EvidenceItem::configured('identity:openings', 'Openings on the requisition', (string) $requisition->openings, $requisition)]);

        foreach (collect($requisition->skills ?? [])->map(fn ($skill) => trim((string) $skill))->filter()->unique(fn ($skill) => IntelligenceText::normalize($skill)) as $skill) {
            $key = IntelligenceText::skillKey($skill);
            $add($key, RoleDnaCategory::Skill, $skill, $skill, RequirementLevel::Required, RoleDnaOrigin::Configured,
                [EvidenceItem::configured($key, 'Listed in the requisition skills', $skill, $requisition)]);
        }

        if ($requisition->experience_min !== null || $requisition->experience_max !== null) {
            $range = IntelligenceText::range($requisition->experience_min, $requisition->experience_max, 'years');
            $add('experience:range', RoleDnaCategory::Experience, 'Experience', $range, RequirementLevel::Required, RoleDnaOrigin::Configured,
                [EvidenceItem::configured('experience:range', 'Experience range on the requisition', $range, $requisition)],
                ['min' => $requisition->experience_min !== null ? (float) $requisition->experience_min : null, 'max' => $requisition->experience_max !== null ? (float) $requisition->experience_max : null]);
        }

        if (filled($requisition->qualification)) {
            $add('education:qualification', RoleDnaCategory::Education, 'Qualification', $requisition->qualification, RequirementLevel::Required, RoleDnaOrigin::Configured,
                [EvidenceItem::configured('education:qualification', 'Qualification on the requisition', $requisition->qualification, $requisition)]);
        }

        if ($requisition->location !== null) {
            $add('location:primary', RoleDnaCategory::Location, 'Location', $requisition->location->name, RequirementLevel::Required, RoleDnaOrigin::Configured,
                [EvidenceItem::configured('location:primary', 'Requisition location', $requisition->location->name, $requisition)]);
        }

        if ($requisition->employment_type !== null) {
            $add('work_mode:employment_type', RoleDnaCategory::WorkMode, 'Employment type', $requisition->employment_type->label(), RequirementLevel::Informational, RoleDnaOrigin::Configured,
                [EvidenceItem::configured('work_mode:employment_type', 'Employment type on the requisition', $requisition->employment_type->label(), $requisition)]);
        }

        if (filled($requisition->shift)) {
            $add('work_mode:shift', RoleDnaCategory::WorkMode, 'Shift', (string) $requisition->shift, RequirementLevel::Informational, RoleDnaOrigin::Configured,
                [EvidenceItem::configured('work_mode:shift', 'Shift on the requisition', (string) $requisition->shift, $requisition)]);
        }

        if ($requisition->salary_min !== null || $requisition->salary_max !== null) {
            $range = IntelligenceText::range($requisition->salary_min, $requisition->salary_max);
            $add('compensation:range', RoleDnaCategory::Compensation, 'Budget range', $range, RequirementLevel::Informational, RoleDnaOrigin::Configured,
                [EvidenceItem::configured('compensation:range', 'Salary range on the requisition', $range, $requisition)],
                ['min' => $requisition->salary_min !== null ? (float) $requisition->salary_min : null, 'max' => $requisition->salary_max !== null ? (float) $requisition->salary_max : null]);
        }

        // Inferred — from how this organisation evaluates and runs interviews.
        foreach (InterviewFeedback::RATING_CRITERIA as $criterion => $label) {
            if (in_array($criterion, self::EXCLUDED_CRITERIA, true)) {
                continue;
            }

            $key = "interview:{$criterion}";
            $add($key, RoleDnaCategory::InterviewDimension, $label, 'Rated 1–5 by interviewers', RequirementLevel::Informational, RoleDnaOrigin::Inferred,
                [EvidenceItem::configured($key, 'Standard interview rating criterion', $label, null, 'Every interview feedback form rates this criterion.')]);
        }

        $interviewRounds = $requisition->pipelineStages->filter(fn ($stage) => $stage->superseded_at === null && ($stage->is_interview_stage || $stage->stage_type === StageType::Interview))->count();

        if ($interviewRounds > 0) {
            $add('interview:rounds', RoleDnaCategory::InterviewDimension, 'Interview rounds', (string) $interviewRounds, RequirementLevel::Informational, RoleDnaOrigin::Inferred,
                [EvidenceItem::configured('interview:rounds', 'Interview stages in the requisition pipeline', (string) $interviewRounds, $requisition)]);
        }

        foreach ($this->historicalAttributes($requisition) as [$key, $category, $label, $value, $items, $note]) {
            $add($key, $category, $label, $value, RequirementLevel::Informational, RoleDnaOrigin::Historical, $items, [], $note);
        }

        return ['attributes' => $attributes, 'evidence' => $evidence];
    }

    /**
     * Patterns from past hires of the same designation (Hiring Memory). Emits a single
     * "insufficient history" attribute below MIN_HISTORY — never extrapolates.
     *
     * @return array<int, array{0: string, 1: RoleDnaCategory, 2: string, 3: string, 4: array<int, EvidenceItem>, 5: string|null}>
     */
    private function historicalAttributes(RecruitmentRequisition $requisition): array
    {
        if ($requisition->designation_id === null) {
            return [];
        }

        /** @var Collection<int, HiringMemoryRecord> $memory */
        $memory = HiringMemoryRecord::query()
            ->where('designation_id', $requisition->designation_id)
            ->where('is_current', true)
            ->whereIn('memory_type', [MemoryType::Hire, MemoryType::OfferOutcome, MemoryType::JoiningOutcome])
            ->where(fn ($q) => $q->whereNull('requisition_id')->orWhere('requisition_id', '!=', $requisition->id))
            ->latest('captured_at')
            ->limit(200)
            ->get();

        $hires = $memory->where('memory_type', MemoryType::Hire)->values();
        $count = $hires->count();
        $cite = fn (string $key, Collection $records, string $label) => $records->take(10)->map(fn (HiringMemoryRecord $record) => EvidenceItem::fact($key, $label, $record->summary, $record, $record->captured_at))->all();

        $learning = $this->acceptedOutcomeLearning($requisition->designation_id);

        if ($count < self::MIN_HISTORY) {
            return [...$learning, [
                'history:hires', RoleDnaCategory::HistoricalPattern, 'Past hires for this designation', "{$count} recorded",
                $count > 0 ? $cite('history:hires', $hires, 'Hire recorded in Hiring Memory') : [EvidenceItem::metric('history:hires', 'Hires recorded in Hiring Memory for this designation', '0', 0)],
                'Insufficient history: at least '.self::MIN_HISTORY.' past hires are needed before patterns are shown.',
            ]];
        }

        $attributes = [[
            'history:hires', RoleDnaCategory::HistoricalPattern, 'Past hires for this designation', "{$count} recorded",
            $cite('history:hires', $hires, 'Hire recorded in Hiring Memory'), null,
        ]];

        $skillCounts = $hires->flatMap(fn (HiringMemoryRecord $record) => collect($record->facts['skills'] ?? [])->map(fn ($skill) => IntelligenceText::normalize((string) $skill))->unique())->countBy();

        // Display each skill as it was most often written, not in its normalised form.
        $spellings = $hires->flatMap(fn (HiringMemoryRecord $record) => collect($record->facts['skills'] ?? [])->map(fn ($s) => trim((string) $s)))
            ->filter()
            ->groupBy(fn (string $s) => IntelligenceText::normalize($s))
            ->map(fn ($group) => $group->countBy()->sortDesc()->keys()->first());

        foreach ($skillCounts->filter(fn (int $n) => $n / $count >= 0.5)->sortDesc()->take(8) as $skill => $n) {
            $key = 'history:skill:'.Str::slug($skill);
            $label = $spellings[$skill] ?? $skill;
            $records = $hires->filter(fn (HiringMemoryRecord $record) => collect($record->facts['skills'] ?? [])->map(fn ($s) => IntelligenceText::normalize((string) $s))->contains($skill));
            $attributes[] = [$key, RoleDnaCategory::HistoricalPattern, "Common skill among past hires: {$label}", "{$n} of {$count} hires", $cite($key, $records, 'Hire with this skill'), null];
        }

        $days = $hires->map(fn (HiringMemoryRecord $record) => $record->facts['days_to_hire'] ?? null)->filter(fn ($d) => $d !== null)->sort()->values();

        if ($days->count() >= self::MIN_HISTORY) {
            $attributes[] = ['history:time_to_hire', RoleDnaCategory::HistoricalPattern, 'Median time to hire (past hires)', round((float) $days->median()).' days', $cite('history:time_to_hire', $hires, 'Hire with recorded time to hire'), null];
        }

        $sources = $hires->map(fn (HiringMemoryRecord $record) => $record->facts['source'] ?? null)->filter()->countBy()->sortDesc();

        if ($sources->isNotEmpty()) {
            $top = $sources->keys()->first();
            $attributes[] = ['sourcing:top_source', RoleDnaCategory::Sourcing, 'Source that produced most past hires', "{$top} ({$sources->first()} of {$count})",
                $cite('sourcing:top_source', $hires->filter(fn ($r) => ($r->facts['source'] ?? null) === $top), 'Hire from this source'), null];
        }

        $declines = $memory->where('memory_type', MemoryType::OfferOutcome)->values();
        $noShows = $memory->where('memory_type', MemoryType::JoiningOutcome)->values();

        if ($declines->isNotEmpty() || $noShows->isNotEmpty()) {
            $attributes[] = ['history:unsuccessful', RoleDnaCategory::HistoricalPattern, 'Offers not converted (past)', "{$declines->count()} offer(s) declined/expired, {$noShows->count()} joining(s) failed",
                [...$cite('history:unsuccessful', $declines, 'Offer not converted'), ...$cite('history:unsuccessful', $noShows, 'Joining failed')], null];
        }

        return [...$attributes, ...$learning];
    }

    /**
     * Outcome Loop learning a person accepted for this designation (Phase 8.2) — informational
     * historical patterns only; each insight already rests on at least MIN_HISTORY observed hires.
     *
     * @return array<int, array{0: string, 1: RoleDnaCategory, 2: string, 3: string, 4: array<int, EvidenceItem>, 5: string|null}>
     */
    private function acceptedOutcomeLearning(int $designationId): array
    {
        return OutcomeInsight::query()->acceptedLearningFor($designationId)->get()
            ->map(fn (OutcomeInsight $insight) => [
                'outcome:'.$insight->subject_key,
                RoleDnaCategory::HistoricalPattern,
                "Outcome pattern (accepted): {$insight->evidence['skill_label']}",
                "{$insight->evidence['active_with_skill']} of {$insight->evidence['observed_active']} hires observed active at {$insight->evidence['checkpoint_days']} days",
                [EvidenceItem::metric('outcome:'.$insight->subject_key, 'Outcome Loop insight accepted by a reviewer', $insight->insight, (float) $insight->sample_size, $insight->limitations, $insight)],
                $insight->limitations,
            ])
            ->all();
    }
}
