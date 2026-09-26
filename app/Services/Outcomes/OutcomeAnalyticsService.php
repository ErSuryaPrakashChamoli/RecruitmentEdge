<?php

namespace App\Services\Outcomes;

use App\Enums\CandidateStage;
use App\Enums\JoiningStatus;
use App\Enums\OutcomeResult;
use App\Enums\OutcomeSampleBand;
use App\Enums\OutcomeState;
use App\Enums\OutcomeType;
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\HiringOutcome;
use App\Models\HiringOutcomeSnapshot;
use App\Models\RecruitmentRequisition;
use App\Models\RecruitmentSetting;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Outcome Loop™ (Phase 8.2): outcome metrics from recorded outcomes and hiring snapshots only.
 *
 * Every metric carries its definition, population, period basis, sample size and sample band,
 * and the count it could not observe. Unknown and not-observed records are reported next to the
 * rate and never counted as failures; a rate is withheld below the insufficient-history threshold.
 * Voided outcomes are excluded. Scope follows requisition visibility (HierarchyService), so a
 * manager only sees aggregates of requisitions they can see.
 */
class OutcomeAnalyticsService
{
    /**
     * @var array<int, string>
     */
    public const array FILTERS = ['from', 'to', 'department_id', 'designation_id', 'location_id', 'source_id', 'requisition_id'];

    /**
     * @param  array<string, mixed>  $filters
     * @return array{period: array{from: string, to: string}, freshness: ?string, metrics: array<string, array<string, mixed>>, unavailable: array<string, string>}
     */
    public function report(User $user, array $filters = []): array
    {
        [$from, $to] = $this->period($filters);

        $latest = $this->outcomes($user, $filters)->max('created_at');

        return [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'freshness' => $latest !== null ? CarbonImmutable::parse($latest)->toDateTimeString() : null,
            'metrics' => [
                'joining' => $this->joining($user, $filters, $from, $to),
                'offers' => $this->offers($user, $filters, $from, $to),
                'time_to_hire' => $this->timeToHire($user, $filters, $from, $to),
                'time_in_stage' => $this->timeInStage($user, $filters, $from, $to),
                'source_to_join' => $this->sourceToJoin($user, $filters, $from, $to),
                'interview_evidence' => $this->interviewEvidence($user, $filters, $from, $to),
                'status_observations' => $this->statusObservations($user, $filters, $from, $to),
            ],
            'unavailable' => OutcomeType::UNAVAILABLE,
        ];
    }

    /**
     * Current, non-void outcomes in the viewer's scope and the requested filters.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<HiringOutcome>
     */
    public function outcomes(User $user, array $filters = []): Builder
    {
        $query = HiringOutcome::query()->current()->where('hiring_outcomes.state', '!=', OutcomeState::Void->value);

        if (($requisitions = $this->requisitionScope($user, $filters)) !== null) {
            $query->whereIn('hiring_outcomes.requisition_id', $requisitions);
        }

        if (filled($filters['source_id'] ?? null)) {
            $query->whereIn('hiring_outcomes.candidate_application_id', CandidateApplication::query()
                ->whereIn('candidate_id', Candidate::query()->where('source_id', $filters['source_id'])->select('id'))
                ->select('id'));
        }

        return $query;
    }

    /**
     * Hiring snapshots (completed joins) in the viewer's scope and the requested filters.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<HiringOutcomeSnapshot>
     */
    public function snapshots(User $user, array $filters = []): Builder
    {
        $query = HiringOutcomeSnapshot::query();

        if (($requisitions = $this->requisitionScope($user, $filters)) !== null) {
            $query->whereIn('requisition_id', $requisitions);
        }

        return $query->when(filled($filters['source_id'] ?? null), fn (Builder $query) => $query->where('source_id', $filters['source_id']));
    }

    /**
     * The requested period; the last 365 days by default.
     *
     * @param  array<string, mixed>  $filters
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function period(array $filters): array
    {
        $to = filled($filters['to'] ?? null) ? CarbonImmutable::parse($filters['to'])->endOfDay() : CarbonImmutable::today()->endOfDay();
        $from = filled($filters['from'] ?? null) ? CarbonImmutable::parse($filters['from'])->startOfDay() : $to->subDays(364)->startOfDay();

        return $from->gt($to) ? [$to->startOfDay(), $from->endOfDay()] : [$from, $to];
    }

    /**
     * Requisition ids the viewer may see, narrowed by the requisition filters. Null means no
     * restriction (organisation-wide viewer, no requisition filter) — which also keeps outcomes
     * without a requisition.
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<RecruitmentRequisition>|null
     */
    private function requisitionScope(User $user, array $filters): ?Builder
    {
        $viewAll = $user->can('hierarchy.view-all');
        $narrowed = collect(['department_id', 'designation_id', 'location_id', 'requisition_id'])->contains(fn (string $key) => filled($filters[$key] ?? null));

        if ($viewAll && ! $narrowed) {
            return null;
        }

        return RecruitmentRequisition::query()
            ->when(! $viewAll, fn (Builder $query) => $query->visibleTo($user))
            ->when(filled($filters['department_id'] ?? null), fn (Builder $query) => $query->where('department_id', $filters['department_id']))
            ->when(filled($filters['designation_id'] ?? null), fn (Builder $query) => $query->where('designation_id', $filters['designation_id']))
            ->when(filled($filters['location_id'] ?? null), fn (Builder $query) => $query->where('location_id', $filters['location_id']))
            ->when(filled($filters['requisition_id'] ?? null), fn (Builder $query) => $query->whereKey($filters['requisition_id']))
            ->select('id');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function joining(User $user, array $filters, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $counts = $this->outcomes($user, $filters)
            ->whereIn('outcome_type', [OutcomeType::Joined->value, OutcomeType::NoShow->value, OutcomeType::Dropout->value])
            ->whereBetween('observed_at', [$from, $to])
            ->selectRaw('outcome_type, count(*) as total')
            ->groupBy('outcome_type')
            ->pluck('total', 'outcome_type');

        $joined = (int) ($counts[OutcomeType::Joined->value] ?? 0);
        $noShow = (int) ($counts[OutcomeType::NoShow->value] ?? 0);
        $dropout = (int) ($counts[OutcomeType::Dropout->value] ?? 0);
        $decided = $joined + $noShow + $dropout;

        $pending = CandidateJoining::query()
            ->whereIn('status', [JoiningStatus::Expected->value, JoiningStatus::Confirmed->value])
            ->whereIn('candidate_application_id', $this->applicationScope($user, $filters))
            ->count();

        return $this->metric(
            label: 'Joining outcomes',
            definition: 'Joined ÷ (joined + no-show + dropout), from the joining record (the pipeline stage alone never counts). Each outcome is dated when it was recorded; a join by its actual joining date.',
            population: 'Joining records that reached a final status in the period.',
            sampleSize: $decided,
            value: $decided > 0 ? round($joined / $decided * 100, 1) : null,
            unit: '%',
            unknown: $pending,
            unknownHandling: 'Joinings still expected or confirmed (today, any date) are not final — they are shown as pending and left out of the rate, not counted as failures.',
            extra: ['counts' => ['joined' => $joined, 'no_show' => $noShow, 'dropout' => $dropout, 'pending' => $pending], 'completed_hires' => $joined],
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function offers(User $user, array $filters, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $released = $this->outcomes($user, $filters)
            ->where('outcome_type', OutcomeType::OfferReleased->value)
            ->whereBetween('observed_at', [$from, $to])
            ->select('offer_id');

        $reached = HiringOutcome::query()->current()
            ->where('state', '!=', OutcomeState::Void->value)
            ->whereIn('offer_id', $released)
            ->whereIn('outcome_type', [OutcomeType::OfferAccepted->value, OutcomeType::OfferRejected->value, OutcomeType::OfferExpired->value, OutcomeType::OfferWithdrawn->value])
            ->get(['offer_id', 'outcome_type']);

        $releasedCount = (clone $released)->distinct()->count('offer_id');
        $accepted = $reached->where('outcome_type', OutcomeType::OfferAccepted)->pluck('offer_id')->unique()->count();
        $decided = $reached->pluck('offer_id')->unique()->count();
        $byStatus = collect([OutcomeType::OfferAccepted, OutcomeType::OfferRejected, OutcomeType::OfferExpired, OutcomeType::OfferWithdrawn])
            ->mapWithKeys(fn (OutcomeType $type) => [$type->value => $reached->where('outcome_type', $type)->pluck('offer_id')->unique()->count()])
            ->all();

        return $this->metric(
            label: 'Offer outcomes',
            definition: 'Accepted ÷ offers with a decision (accepted, rejected, expired or withdrawn), among offers released in the period, from the offer status history. Release never implies acceptance.',
            population: 'Offers released in the period.',
            sampleSize: $decided,
            value: $decided > 0 ? round($accepted / $decided * 100, 1) : null,
            unit: '%',
            unknown: max(0, $releasedCount - $decided),
            unknownHandling: 'Released offers still awaiting a decision are shown separately and left out of the rate.',
            extra: ['counts' => ['released' => $releasedCount, ...$byStatus, 'awaiting_decision' => max(0, $releasedCount - $decided)]],
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function timeToHire(User $user, array $filters, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = $this->outcomes($user, $filters)
            ->where('outcome_type', OutcomeType::TimeToHire->value)
            ->whereBetween('observed_at', [$from, $to])
            ->get(['result', 'value']);

        $values = $rows->where('result', OutcomeResult::Measured)->pluck('value')->map(fn ($value) => (float) $value)->sort()->values();
        $startPoint = (string) RecruitmentSetting::get('time_to_hire_start_point', 'candidate_applied');

        return $this->metric(
            label: 'Time to hire',
            definition: "Days from the configured start point (currently: {$this->startPointLabel($startPoint)}) to the actual joining date, recorded per completed join. Each join keeps the start point in force when it was captured.",
            population: 'Completed joins with an actual joining date in the period.',
            sampleSize: $values->count(),
            value: $values->isNotEmpty() ? round($values->median(), 1) : null,
            unit: 'days (median)',
            unknown: $rows->where('result', OutcomeResult::Unknown)->count(),
            unknownHandling: 'Joins without a start date are counted as unknown and left out of the median and average.',
            extra: ['average' => $values->isNotEmpty() ? round($values->avg(), 1) : null, 'min' => $values->first(), 'max' => $values->last()],
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function timeInStage(User $user, array $filters, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $durations = [];
        $hires = 0;
        $withoutHistory = 0;

        $this->snapshots($user, $filters)
            ->whereBetween('joined_on', [$from->toDateString(), $to->toDateString()])
            ->select(['id', 'facts'])
            ->lazyById(500)
            ->each(function (HiringOutcomeSnapshot $snapshot) use (&$durations, &$hires, &$withoutHistory): void {
                $hires++;
                $stages = $snapshot->facts['stage_days'] ?? [];

                if ($stages === []) {
                    $withoutHistory++;
                }

                foreach ($stages as $stage => $days) {
                    $durations[$stage][] = (float) $days;
                }
            });

        $stages = collect($durations)
            ->map(fn (array $days, string $stage) => [
                'stage' => $stage,
                'label' => CandidateStage::tryFrom($stage)?->label() ?? str_replace('_', ' ', ucfirst($stage)),
                'average_days' => round(array_sum($days) / count($days), 1),
                'sample_size' => count($days),
                'band' => OutcomeSampleBand::forSize(count($days)),
            ])
            ->sortBy(fn (array $row) => CandidateStage::tryFrom($row['stage'])?->order() ?? 99)
            ->values()
            ->all();

        return $this->metric(
            label: 'Time in stage',
            definition: 'Average days spent in each pipeline stage before joining, from the immutable stage history captured at the join.',
            population: 'Completed joins with an actual joining date in the period.',
            sampleSize: $hires - $withoutHistory,
            value: null,
            unit: 'days',
            unknown: $withoutHistory,
            unknownHandling: 'Joins with no recorded stage history are counted as unknown and left out of the averages.',
            extra: ['stages' => $stages],
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function sourceToJoin(User $user, array $filters, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $rows = $this->outcomes($user, $filters)
            ->whereIn('hiring_outcomes.outcome_type', [OutcomeType::Joined->value, OutcomeType::NoShow->value, OutcomeType::Dropout->value])
            ->whereBetween('hiring_outcomes.observed_at', [$from, $to])
            ->join('candidate_applications', 'candidate_applications.id', '=', 'hiring_outcomes.candidate_application_id')
            ->join('candidates', 'candidates.id', '=', 'candidate_applications.candidate_id')
            ->leftJoin('candidate_sources', 'candidate_sources.id', '=', 'candidates.source_id')
            ->selectRaw('candidate_sources.name as source_name, hiring_outcomes.outcome_type as type, count(*) as total')
            ->groupBy('candidate_sources.name', 'hiring_outcomes.outcome_type')
            ->get();

        $sources = $rows->groupBy(fn ($row) => $row->source_name ?? 'Source not recorded')
            ->map(function (Collection $group, string $source) {
                $joined = (int) $group->firstWhere('type', OutcomeType::Joined->value)?->total;
                $decided = (int) $group->sum('total');
                $band = OutcomeSampleBand::forSize($decided);

                return [
                    'source' => $source,
                    'joined' => $joined,
                    'no_show' => (int) $group->firstWhere('type', OutcomeType::NoShow->value)?->total,
                    'dropout' => (int) $group->firstWhere('type', OutcomeType::Dropout->value)?->total,
                    'sample_size' => $decided,
                    'band' => $band,
                    'rate' => $band->isSufficient() ? round($joined / $decided * 100, 1) : null,
                ];
            })
            ->sortByDesc('sample_size')
            ->values()
            ->all();

        return $this->metric(
            label: 'Source to join',
            definition: 'Per candidate source: joined ÷ (joined + no-show + dropout). Observational — a source is one factor among many, never a cause.',
            population: 'Joining records that reached a final status in the period, grouped by the candidate source.',
            sampleSize: (int) $rows->sum('total'),
            value: null,
            unit: '%',
            unknown: 0,
            unknownHandling: 'A source with fewer than '.config('outcomes.sample.insufficient_below', 3).' final outcomes shows its counts only — no rate. Candidates without a source are grouped as "Source not recorded".',
            extra: ['sources' => $sources],
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function interviewEvidence(User $user, array $filters, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $hires = 0;
        $withFeedback = 0;
        $rounds = [];
        $scores = [];
        $recommendations = [];

        $this->snapshots($user, $filters)
            ->whereBetween('joined_on', [$from->toDateString(), $to->toDateString()])
            ->select(['id', 'facts'])
            ->lazyById(500)
            ->each(function (HiringOutcomeSnapshot $snapshot) use (&$hires, &$withFeedback, &$rounds, &$scores, &$recommendations): void {
                $interviews = $snapshot->facts['interviews'] ?? null;
                $hires++;

                if ($interviews === null) {
                    return;
                }

                $rounds[] = (int) ($interviews['rounds'] ?? 0);

                if ((int) ($interviews['feedback_count'] ?? 0) > 0) {
                    $withFeedback++;
                }

                if (($interviews['average_score'] ?? null) !== null) {
                    $scores[] = (float) $interviews['average_score'];
                }

                foreach ($interviews['recommendations'] ?? [] as $recommendation => $count) {
                    $recommendations[$recommendation] = ($recommendations[$recommendation] ?? 0) + (int) $count;
                }
            });

        return $this->metric(
            label: 'Interview evidence of completed hires',
            definition: 'Interview rounds, recorded recommendations and average feedback score of the people who joined, captured at the join. Descriptive only — who interviewed is never included.',
            population: 'Completed joins with an actual joining date in the period.',
            sampleSize: $withFeedback,
            value: $scores !== [] ? round(array_sum($scores) / count($scores), 1) : null,
            unit: 'average score',
            unknown: $hires - $withFeedback,
            unknownHandling: 'Hires with no recorded feedback are counted separately. Missing feedback is not a negative signal.',
            extra: ['hires' => $hires, 'average_rounds' => $rounds !== [] ? round(array_sum($rounds) / count($rounds), 1) : null, 'recommendations' => $recommendations],
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function statusObservations(User $user, array $filters, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $cohort = fn () => $this->snapshots($user, $filters)->whereBetween('joined_on', [$from->toDateString(), $to->toDateString()]);
        $hires = $cohort()->count();

        $checkpoints = collect(OutcomeType::statusObservations())->map(function (OutcomeType $type) use ($cohort, $hires) {
            $window = (int) $type->windowDays();
            $counts = HiringOutcome::query()->current()
                ->where('state', '!=', OutcomeState::Void->value)
                ->where('outcome_type', $type->value)
                ->whereIn('hiring_outcome_snapshot_id', $cohort()->select('id'))
                ->selectRaw('result, count(*) as total')
                ->groupBy('result')
                ->pluck('total', 'result');

            $active = (int) ($counts[OutcomeResult::Active->value] ?? 0);
            $inactive = (int) ($counts[OutcomeResult::Inactive->value] ?? 0);
            $separated = (int) ($counts[OutcomeResult::SeparatedBeforeCheckpoint->value] ?? 0);
            $notObserved = (int) ($counts[OutcomeResult::NotObserved->value] ?? 0) + (int) ($counts[OutcomeResult::Unknown->value] ?? 0);
            $notDue = $cohort()->whereDate('joined_on', '>', CarbonImmutable::today()->subDays($window))->count();
            $observed = $active + $inactive + $separated;
            $band = OutcomeSampleBand::forSize($observed);

            return [
                'type' => $type,
                'label' => $type->label(),
                'active' => $active,
                'inactive' => $inactive,
                'separated' => $separated,
                'not_observed' => $notObserved,
                'not_yet_due' => $notDue,
                'awaiting_evaluation' => max(0, $hires - $observed - $notObserved - $notDue),
                'sample_size' => $observed,
                'band' => $band,
                'active_rate' => $band->isSufficient() ? round($active / $observed * 100, 1) : null,
            ];
        })->all();

        return $this->metric(
            label: 'Status observations after joining',
            definition: 'The employee status seen on the day each checkpoint (30 / 90 / 180 days after joining) was checked (medium confidence), or a separation recorded before it (high confidence). "Observed active" is not confirmed retention, and "observed inactive" is not confirmed as an exit.',
            population: 'Completed joins with an actual joining date in the period, observed going forward from Phase 8.2.',
            sampleSize: $hires,
            value: null,
            unit: '%',
            unknown: (int) collect($checkpoints)->sum('not_observed'),
            unknownHandling: 'Hires never observed at a checkpoint (joined before observation started, no employee record, or separated at an earlier checkpoint) are "not observed" — left out of the rate, never counted as leaving. Retention before Phase 8.2 is not reconstructed.',
            extra: ['checkpoints' => $checkpoints],
        );
    }

    /**
     * Application ids in scope, for records outside hiring_outcomes (pending joinings).
     *
     * @param  array<string, mixed>  $filters
     * @return Builder<CandidateApplication>
     */
    private function applicationScope(User $user, array $filters): Builder
    {
        return CandidateApplication::query()
            ->when($this->requisitionScope($user, $filters), fn (Builder $query, Builder $requisitions) => $query->whereIn('requisition_id', $requisitions))
            ->when(filled($filters['source_id'] ?? null), fn (Builder $query) => $query->whereIn('candidate_id', Candidate::query()->where('source_id', $filters['source_id'])->select('id')))
            ->select('id');
    }

    private function startPointLabel(string $startPoint): string
    {
        return match ($startPoint) {
            'candidate_sourced' => 'candidate sourced',
            'requisition_opened' => 'requisition opened',
            default => 'candidate applied',
        };
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function metric(string $label, string $definition, string $population, int $sampleSize, float|int|null $value, string $unit, int $unknown, string $unknownHandling, array $extra = []): array
    {
        $band = OutcomeSampleBand::forSize($sampleSize);

        return [
            'label' => $label,
            'definition' => $definition,
            'population' => $population,
            'sample_size' => $sampleSize,
            'band' => $band,
            // A headline value is withheld below the insufficient-history threshold — the counts still show.
            'value' => $band->isSufficient() ? $value : null,
            'unit' => $unit,
            'unknown' => $unknown,
            'unknown_handling' => $unknownHandling,
            ...$extra,
        ];
    }
}
