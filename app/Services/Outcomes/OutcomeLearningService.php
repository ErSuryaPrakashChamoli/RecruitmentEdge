<?php

namespace App\Services\Outcomes;

use App\Enums\OutcomeConfidence;
use App\Enums\OutcomeInsightKind;
use App\Enums\OutcomeInsightStatus;
use App\Enums\OutcomeResult;
use App\Enums\OutcomeSampleBand;
use App\Enums\OutcomeState;
use App\Enums\OutcomeType;
use App\Enums\RequirementLevel;
use App\Enums\RoleDnaCategory;
use App\Models\AuditLog;
use App\Models\CandidateSource;
use App\Models\Designation;
use App\Models\HiringOutcome;
use App\Models\OutcomeInsight;
use App\Models\RecruitmentRequisition;
use App\Models\User;
use App\Services\Intelligence\HiringMemoryService;
use App\Services\Intelligence\RoleDnaService;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Outcome Loop™ (Phase 8.2): turns recorded outcomes into learning insights for a person to review.
 *
 * - Role DNA learning: per designation, skills listed by at least half of the hires observed
 *   active at the learning checkpoint (config outcomes.learning_checkpoint_days), when at least
 *   3 hires were observed there and at least 3 were observed active.
 * - Source pattern: per candidate source, joined ÷ final joining outcomes, from 3 outcomes up.
 *
 * Insights are observational aggregates (counts only — never a person), carry their evidence,
 * sample size and band, period, confidence and limitations, and change nothing until a person
 * accepts one. Accepting a Role DNA suggestion adds a preferred or informational skill (never a
 * required one) through RoleDnaService; accepting a source pattern records an aggregate Hiring
 * Memory pattern. Every decision is audited. Only skills — job-relevant, candidate-stated — are
 * compared; no personal or protected attribute is used.
 */
class OutcomeLearningService
{
    public const string RULE_VERSION = 'outcome-learning/1';

    public const float SKILL_SHARE = 0.5;

    public const int SKILLS_PER_DESIGNATION = 5;

    public function __construct(
        private readonly RoleDnaService $roleDna,
        private readonly HiringMemoryService $memory,
    ) {}

    /**
     * Recalculates every insight. Idempotent: open insights are refreshed in place, decided ones
     * are never touched, and open ones that no longer meet the thresholds expire.
     *
     * @return array{created: int, updated: int, expired: int}
     */
    public function refresh(): array
    {
        $counts = ['created' => 0, 'updated' => 0, 'expired' => 0];
        $seen = [];

        foreach ([...$this->roleDnaInsights(), ...$this->sourceInsights()] as $key => $attributes) {
            $seen[] = $key;
            $result = $this->upsert($key, $attributes);

            if ($result !== null) {
                $counts[$result]++;
            }
        }

        OutcomeInsight::query()
            ->whereIn('status', [OutcomeInsightStatus::Review->value, OutcomeInsightStatus::Deferred->value])
            ->whereNotIn('dedupe_key', $seen)
            ->get()
            ->each(function (OutcomeInsight $insight) use (&$counts): void {
                $insight->update(['status' => OutcomeInsightStatus::Expired, 'last_recalculated_at' => now()]);
                AuditLog::record($insight, 'outcome_insight_expired', ['status' => 'open'], ['status' => OutcomeInsightStatus::Expired->value, 'reason' => 'no_longer_meets_thresholds']);
                $counts['expired']++;
            });

        return $counts;
    }

    public function learningCheckpoint(): OutcomeType
    {
        return OutcomeType::forWindow((int) config('outcomes.learning_checkpoint_days', 90)) ?? OutcomeType::StatusObserved90d;
    }

    /**
     * Accepts an open insight. A Role DNA suggestion may be applied to one requisition of the same
     * designation the reviewer can see (as a preferred or informational skill); either way it then
     * shows in Role DNA historical patterns for that designation. A source pattern is recorded in
     * Hiring Memory.
     */
    public function accept(OutcomeInsight $insight, User $actor, ?RecruitmentRequisition $requisition = null, RequirementLevel $level = RequirementLevel::Preferred, ?string $reason = null): OutcomeInsight
    {
        return DB::transaction(function () use ($insight, $actor, $requisition, $level, $reason): OutcomeInsight {
            $insight = $this->lockOpen($insight);
            $applied = null;

            if ($insight->kind === OutcomeInsightKind::RoleDnaLearning && $requisition !== null) {
                if ($level === RequirementLevel::Required) {
                    throw new DomainException('Outcome learning can add a preferred or informational skill only — a required skill stays a human decision in Role DNA.');
                }

                if (! $actor->can('intelligence.role-dna.manage')) {
                    throw new DomainException('Applying a suggestion to a requisition needs permission to manage Role DNA.');
                }

                if ($requisition->designation_id !== $insight->designation_id || ! RecruitmentRequisition::query()->visibleTo($actor)->whereKey($requisition->id)->exists()) {
                    throw new DomainException('Choose a requisition you can see for the same designation.');
                }

                $version = $this->roleDna->addAttribute($requisition, [
                    'category' => RoleDnaCategory::Skill->value,
                    'label' => $insight->evidence['skill_label'],
                    'value' => $insight->evidence['skill_label'],
                    'level' => $level->value,
                ], $actor);
                $applied = "role_dna_version:{$version->id}";
            }

            if ($insight->kind === OutcomeInsightKind::SourcePattern) {
                $record = $this->memory->captureOutcomePattern($insight, $actor);
                $applied = $record !== null ? "hiring_memory:{$record->id}" : null;
            }

            return $this->decide($insight, $actor, OutcomeInsightStatus::Accepted, $reason, $applied, $requisition !== null ? ['requisition_id' => $requisition->id, 'level' => $level->value] : []);
        });
    }

    public function reject(OutcomeInsight $insight, User $actor, string $reason): OutcomeInsight
    {
        if (trim($reason) === '') {
            throw new DomainException('Rejecting an insight needs a reason.');
        }

        return DB::transaction(fn () => $this->decide($this->lockOpen($insight), $actor, OutcomeInsightStatus::Rejected, $reason));
    }

    public function defer(OutcomeInsight $insight, User $actor, ?string $reason = null): OutcomeInsight
    {
        return DB::transaction(fn () => $this->decide($this->lockOpen($insight), $actor, OutcomeInsightStatus::Deferred, $reason));
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function roleDnaInsights(): array
    {
        $checkpoint = $this->learningCheckpoint();
        $minimum = (int) config('outcomes.sample.insufficient_below', 3);

        // Phase 8.9 (P89-PERF-014, P87-BACKLOG-010): the checkpoint outcomes are streamed and folded
        // into per-designation counters — the figures the insights state, in the same order — instead
        // of every hire of the history held in memory. Same sample, same counts, same wording.
        $groups = [];

        HiringOutcome::query()->current()
            ->where('hiring_outcomes.outcome_type', $checkpoint->value)
            ->where('hiring_outcomes.state', '!=', OutcomeState::Void->value)
            ->whereIn('hiring_outcomes.result', [OutcomeResult::Active->value, OutcomeResult::Inactive->value, OutcomeResult::SeparatedBeforeCheckpoint->value])
            ->join('hiring_outcome_snapshots', 'hiring_outcome_snapshots.id', '=', 'hiring_outcomes.hiring_outcome_snapshot_id')
            ->whereNotNull('hiring_outcome_snapshots.designation_id')
            ->select(['hiring_outcomes.id', 'hiring_outcomes.result', 'hiring_outcome_snapshots.id as snapshot_id', 'hiring_outcome_snapshots.designation_id', 'hiring_outcome_snapshots.joined_on', 'hiring_outcome_snapshots.facts'])
            ->lazyById(500, 'hiring_outcomes.id', 'id')
            ->each(function (HiringOutcome $row) use (&$groups): void {
                $facts = json_decode((string) $row->getRawOriginal('facts'), true) ?: [];
                $skills = collect($facts['skills'] ?? [])->filter(fn ($skill) => is_string($skill) && str_starts_with($skill, 'skill:'))->unique()->values()->all();
                $active = $row->result === OutcomeResult::Active;
                $designationId = (int) $row->getAttribute('designation_id');
                $joinedOn = (string) $row->getAttribute('joined_on');
                $group = &$groups[$designationId];
                $group ??= ['observed' => 0, 'active' => 0, 'active_skills' => [], 'with' => [], 'with_active' => [], 'first_joined' => $joinedOn, 'last_joined' => $joinedOn, 'outcome_ids' => [], 'snapshot_ids' => []];

                $group['observed']++;
                $group['active'] += $active ? 1 : 0;
                $group['first_joined'] = min($group['first_joined'], $joinedOn);
                $group['last_joined'] = max($group['last_joined'], $joinedOn);

                if (count($group['outcome_ids']) < 50) {
                    $group['outcome_ids'][] = $row->id;
                    $group['snapshot_ids'][] = (int) $row->getAttribute('snapshot_id');
                }

                foreach ($skills as $skill) {
                    $group['with'][$skill] = ($group['with'][$skill] ?? 0) + 1;

                    if ($active) {
                        $group['active_skills'][$skill] = ($group['active_skills'][$skill] ?? 0) + 1;
                        $group['with_active'][$skill] = ($group['with_active'][$skill] ?? 0) + 1;
                    }
                }

                unset($group);
            });

        $designations = Designation::query()->whereIn('id', array_keys($groups))->pluck('name', 'id');
        $insights = [];

        foreach ($groups as $designationId => $group) {
            $observed = $group['observed'];
            $activeCount = $group['active'];

            if ($observed < $minimum || $activeCount < $minimum) {
                continue;
            }

            $skillCounts = collect($group['active_skills'])
                ->filter(fn (int $count) => $count / $activeCount >= self::SKILL_SHARE)
                ->sortDesc()
                ->take(self::SKILLS_PER_DESIGNATION);

            foreach ($skillCounts as $skill => $activeWith) {
                $withCount = $group['with'][$skill] ?? 0;
                $withActive = $group['with_active'][$skill] ?? 0;
                $withoutCount = $observed - $withCount;
                $withoutActive = $activeCount - $withActive;
                $label = Str::of(Str::after($skill, 'skill:'))->replace('-', ' ')->title()->toString();
                $designation = $designations[$designationId] ?? 'this designation';
                $band = OutcomeSampleBand::forSize($observed);
                $days = (int) $checkpoint->windowDays();

                $insights["role_dna:{$designationId}:{$skill}:{$checkpoint->value}"] = [
                    'kind' => OutcomeInsightKind::RoleDnaLearning,
                    'designation_id' => $designationId,
                    'subject_key' => $skill,
                    'insight' => "Among {$observed} completed hires for {$designation} with an observed {$days}-day status, {$activeWith} of the {$activeCount} observed active listed {$label}. "
                        ."Of hires listing it, {$withActive} of {$withCount} were observed active; of hires not listing it, {$withoutActive} of {$withoutCount}.",
                    'suggested_change' => "Consider {$label} as a preferred skill in Role DNA for {$designation} requisitions.",
                    'evidence' => [
                        'skill' => $skill,
                        'skill_label' => $label,
                        'checkpoint_days' => $days,
                        'observed' => $observed,
                        'observed_active' => $activeCount,
                        'active_with_skill' => $activeWith,
                        'with_skill' => ['observed' => $withCount, 'active' => $withActive],
                        'without_skill' => ['observed' => $withoutCount, 'active' => $withoutActive],
                    ],
                    'sample_size' => $observed,
                    'sample_band' => $band,
                    'period_start' => $group['first_joined'],
                    'period_end' => $group['last_joined'],
                    'confidence' => $band === OutcomeSampleBand::Stronger ? OutcomeConfidence::Medium : OutcomeConfidence::Low,
                    'limitations' => "Observational association in a {$band->label()} sample — not a cause, and not a reason to exclude anyone. "
                        .'Status is observed on the checkpoint day (medium confidence); observed inactive is not confirmed as an exit. Hires never observed at the checkpoint are left out. '
                        .'Only skills recorded on the candidate profile at joining are compared.',
                    'source_refs' => ['outcomes' => $group['outcome_ids'], 'snapshots' => $group['snapshot_ids']],
                ];
            }
        }

        return $insights;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function sourceInsights(): array
    {
        $rows = HiringOutcome::query()->current()
            ->where('hiring_outcomes.state', '!=', OutcomeState::Void->value)
            ->whereIn('hiring_outcomes.outcome_type', [OutcomeType::Joined->value, OutcomeType::NoShow->value, OutcomeType::Dropout->value])
            ->join('candidate_applications', 'candidate_applications.id', '=', 'hiring_outcomes.candidate_application_id')
            ->join('candidates', 'candidates.id', '=', 'candidate_applications.candidate_id')
            ->whereNotNull('candidates.source_id')
            ->selectRaw('candidates.source_id as source_id, hiring_outcomes.outcome_type as type, count(*) as total, min(hiring_outcomes.observed_at) as first_seen, max(hiring_outcomes.observed_at) as last_seen')
            ->groupBy('candidates.source_id', 'hiring_outcomes.outcome_type')
            ->get();

        $minimum = (int) config('outcomes.sample.insufficient_below', 3);
        $overallDecided = (int) $rows->sum('total');
        $overallJoined = (int) $rows->where('type', OutcomeType::Joined->value)->sum('total');
        $names = CandidateSource::query()->whereIn('id', $rows->pluck('source_id')->unique())->pluck('name', 'id');
        $insights = [];

        foreach ($rows->groupBy('source_id') as $sourceId => $group) {
            $decided = (int) $group->sum('total');

            if ($decided < $minimum) {
                continue;
            }

            $joined = (int) $group->where('type', OutcomeType::Joined->value)->sum('total');
            $band = OutcomeSampleBand::forSize($decided);
            $source = $names[$sourceId] ?? 'Unknown source';
            $rate = round($joined / $decided * 100, 1);
            $overallRate = $overallDecided > 0 ? round($overallJoined / $overallDecided * 100, 1) : null;

            $insights["source:{$sourceId}"] = [
                'kind' => OutcomeInsightKind::SourcePattern,
                'designation_id' => null,
                'subject_key' => "source:{$sourceId}",
                'insight' => "Of {$decided} final joining outcomes for candidates from {$source}, {$joined} joined ({$rate}%). Across all recorded sources: {$overallJoined} of {$overallDecided} ({$overallRate}%).",
                'suggested_change' => "Record this as a source pattern in Hiring Memory for {$source}.",
                'evidence' => [
                    'source' => $source,
                    'joined' => $joined,
                    'no_show' => (int) $group->where('type', OutcomeType::NoShow->value)->sum('total'),
                    'dropout' => (int) $group->where('type', OutcomeType::Dropout->value)->sum('total'),
                    'decided' => $decided,
                    'overall' => ['joined' => $overallJoined, 'decided' => $overallDecided],
                ],
                'sample_size' => $decided,
                'sample_band' => $band,
                'period_start' => substr((string) $group->min('first_seen'), 0, 10),
                'period_end' => substr((string) $group->max('last_seen'), 0, 10),
                'confidence' => $band === OutcomeSampleBand::Stronger ? OutcomeConfidence::High : OutcomeConfidence::Medium,
                'limitations' => "Observational: joining depends on the role, the offer and the candidate's circumstances, not only the source. {$band->label()} sample. Joinings still pending are not included.",
                'source_refs' => ['source_id' => (int) $sourceId],
            ];
        }

        return $insights;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return 'created'|'updated'|null
     */
    private function upsert(string $key, array $attributes): ?string
    {
        $existing = OutcomeInsight::query()->where('dedupe_key', $key)->first();
        $attributes = [...$attributes, 'rule_version' => self::RULE_VERSION, 'last_recalculated_at' => now()];

        if ($existing === null) {
            OutcomeInsight::query()->create([...$attributes, 'status' => OutcomeInsightStatus::Review, 'dedupe_key' => $key]);

            return 'created';
        }

        // A decision stays as it was made; an expired insight that qualifies again is reopened.
        if (in_array($existing->status, [OutcomeInsightStatus::Accepted, OutcomeInsightStatus::Rejected], true)) {
            return null;
        }

        $existing->update([...$attributes, 'status' => $existing->status === OutcomeInsightStatus::Expired ? OutcomeInsightStatus::Review : $existing->status]);

        return 'updated';
    }

    private function lockOpen(OutcomeInsight $insight): OutcomeInsight
    {
        $current = OutcomeInsight::query()->whereKey($insight->id)->lockForUpdate()->firstOrFail();

        if (! $current->status->isOpen()) {
            throw new DomainException("This insight is already {$current->status->label()}.");
        }

        return $current;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function decide(OutcomeInsight $insight, User $actor, OutcomeInsightStatus $status, ?string $reason, ?string $applied = null, array $context = []): OutcomeInsight
    {
        $previous = $insight->status;
        $reason = filled($reason) ? mb_substr(trim($reason), 0, 255) : null;

        $insight->update(['status' => $status, 'reviewed_by' => $actor->id, 'reviewed_at' => now(), 'review_reason' => $reason, 'applied_ref' => $applied]);

        AuditLog::record($insight, 'outcome_insight_'.$status->value, ['status' => $previous->value], [
            'status' => $status->value, 'kind' => $insight->kind->value, 'subject' => $insight->subject_key,
            'sample_size' => $insight->sample_size, 'reason' => $reason, 'applied' => $applied, 'by_user_id' => $actor->id, ...$context,
        ]);

        return $insight;
    }
}
