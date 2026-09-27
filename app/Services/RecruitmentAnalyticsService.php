<?php

namespace App\Services;

use App\Enums\ApplicationStatus;
use App\Enums\AutomationActionStatus;
use App\Enums\AutomationExecutionStatus;
use App\Enums\CandidateStage;
use App\Enums\CommunicationStatus;
use App\Enums\EscalationStatus;
use App\Enums\InterviewResult;
use App\Enums\InterviewStatus;
use App\Enums\JoiningStatus;
use App\Enums\OfferStatus;
use App\Enums\PreferenceStatus;
use App\Enums\RecruiterActionStatus;
use App\Enums\RequisitionStatus;
use App\Models\AutomationActionExecution;
use App\Models\AutomationEscalation;
use App\Models\AutomationExecution;
use App\Models\AutomationRule;
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\CandidateCommunication;
use App\Models\CandidateCommunicationPreference;
use App\Models\CandidateJoining;
use App\Models\CandidateSource;
use App\Models\CandidateStageHistory;
use App\Models\Employee;
use App\Models\Interview;
use App\Models\JobPosting;
use App\Models\Offer;
use App\Models\RecruiterAction;
use App\Models\RecruitmentCampaign;
use App\Models\RecruitmentCost;
use App\Models\RecruitmentRequisition;
use App\Models\RecruitmentSetting;
use App\Models\User;
use App\Services\Metrics\MetricPeriod;
use App\Services\Metrics\MetricQuery;
use App\Services\Metrics\MetricResult;
use App\Services\Metrics\MetricScope;
use App\Services\Metrics\MetricService;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Section 32 (funnel), 33 (source analytics), 9 (vacancy ageing), and 35 (time to hire).
 * Everything here is read-only reporting over facts already recorded elsewhere — no new state.
 *
 * Phase 8.5: the governed numbers (conversion, acceptance, join, turn-up and time metrics) are read
 * from the metric registry (MetricService) so the dashboard, reports, exports and Copilot show the
 * same figure; this class keeps the breakdown shapes its consumers use. Periods are business-
 * timezone calendar days (MetricPeriod), a stage is "reached" only by a genuine stage entry (DF-9),
 * and deleted applications never count.
 */
class RecruitmentAnalyticsService
{
    public function __construct(
        private readonly HierarchyService $hierarchy,
        private readonly MetricService $metrics,
        private readonly MetricScope $scope,
    ) {}

    /**
     * A governed metric for the same period and viewer an analytics method was called with.
     *
     * @param  array<string, int|null>  $filters
     */
    public function metric(string $key, CarbonInterface $start, CarbonInterface $end, ?User $user = null, array $filters = []): MetricResult
    {
        return $this->metrics->get($key, MetricQuery::make(MetricPeriod::between($start, $end), $user, $filters));
    }

    /**
     * The cohort funnel (pipeline.funnel): of the applications created in the period, how many have
     * reached each canonical stage by now, as a share of the cohort — never above 100%. Joined is
     * the joining record (D34).
     *
     * @return Collection<int, array{stage: CandidateStage, count: int, conversion_from_sourced: float|null}>
     */
    public function funnel(CarbonInterface $start, CarbonInterface $end, ?User $user = null): Collection
    {
        return collect($this->metric('pipeline.funnel', $start, $end, $user)->detail('stages', []))->map(fn (array $row) => [
            'stage' => CandidateStage::from($row['stage']),
            'count' => $row['count'],
            'conversion_from_sourced' => $row['percent_of_cohort'],
        ]);
    }

    /**
     * Pipeline volumes in the period (pipeline.stage_activity): applications created, applications
     * genuinely entering each stage, and hires.
     *
     * @return Collection<int, array{stage: CandidateStage, count: int}>
     */
    public function stageActivity(CarbonInterface $start, CarbonInterface $end, ?User $user = null): Collection
    {
        return collect($this->metric('pipeline.stage_activity', $start, $end, $user)->detail('stages', []))->map(fn (array $row) => [
            'stage' => CandidateStage::from($row['stage']),
            'count' => $row['count'],
        ]);
    }

    /**
     * Per-source funnel and return on spend (Section 33/34) — "which source actually produces joining
     * employees, at what cost."
     *
     * Phase 8.5: the cohort is the applications created in the period (D24: an application unit on
     * both sides, so conversion never exceeds 100%), grouped by the candidate's current source, with
     * an explicit "Not recorded" row (D9). A stage counts when the application reached it or any later
     * stage by now (genuine entries). Spend is the source's recruitment cost in the period over the
     * requisitions the viewer is involved in (D36); costs with no requisition count only for viewers
     * who see the whole organization. One grouped query per figure, not per source.
     *
     * @return Collection<int, array{source: CandidateSource|null, source_name: string, spend: float, sourced: int, connected: int, interested: int, interviewed: int, selected: int, offers: int, joined: int, conversion_percent: float|null, cost_per_interview: float|null, cost_per_selection: float|null, cost_per_join: float|null}>
     */
    public function sourceAnalytics(CarbonInterface $start, CarbonInterface $end, ?User $user = null): Collection
    {
        $period = MetricPeriod::between($start, $end);
        $query = MetricQuery::make($period, $user);

        $cohort = $this->scope->applications(CandidateApplication::query(), $query)
            ->tap(fn (Builder $q) => $period->whereDateColumn($q, 'candidate_applications.application_date'))
            ->join('candidates', 'candidates.id', '=', 'candidate_applications.candidate_id')
            ->select(['candidate_applications.id', 'candidates.source_id']);

        $sourceOf = fn () => DB::query()->fromSub($cohort, 'cohort');
        $count = fn (\Closure $facts) => $sourceOf()->tap($facts)->selectRaw('cohort.source_id, count(distinct cohort.id) as total')->groupBy('cohort.source_id')->pluck('total', 'source_id')
            ->mapWithKeys(fn ($total, $sourceId) => [(int) $sourceId => (int) $total]);
        $reached = fn (CandidateStage $stage) => $count(fn ($q) => $q->whereExists(fn ($e) => $e->from('candidate_stage_histories')
            ->whereColumn('candidate_stage_histories.candidate_application_id', 'cohort.id')
            ->whereIn('candidate_stage_histories.new_stage', $this->stagesFrom($stage))
            ->tap(fn ($m) => CandidateStageHistory::constrainToMilestoneEntries($m))));

        $sourced = $count(fn ($q) => $q);
        $connected = $reached(CandidateStage::Connected);
        $interested = $reached(CandidateStage::Interested);
        $selected = $reached(CandidateStage::Selected);
        $interviewed = $count(fn ($q) => $q->whereExists(fn ($e) => $e->from('interviews')->whereColumn('interviews.candidate_application_id', 'cohort.id')->where('interviews.status', InterviewStatus::Completed->value)));
        $offers = $count(fn ($q) => $q->whereExists(fn ($e) => $e->from('offers')->whereColumn('offers.candidate_application_id', 'cohort.id')));
        $joined = $count(fn ($q) => $q->whereExists(fn ($e) => $e->from('candidate_joinings')->whereColumn('candidate_joinings.candidate_application_id', 'cohort.id')->where('candidate_joinings.status', JoiningStatus::Joined->value)));

        $spend = $this->scope->throughRequisition(RecruitmentCost::query(), $query)
            ->tap(fn (Builder $q) => $period->whereDateColumn($q, 'incurred_on'))
            ->whereNotNull('source_id')
            ->selectRaw('source_id, sum(amount) as total')
            ->groupBy('source_id')
            ->pluck('total', 'source_id')
            ->mapWithKeys(fn ($total, $sourceId) => [(int) $sourceId => (float) $total]);

        $sources = CandidateSource::query()->get()->keyBy('id');
        $rows = $sources->keys()->when($sourced->has(0), fn (Collection $ids) => $ids->push(0));

        return $rows->map(function (int $sourceId) use ($sources, $sourced, $connected, $interested, $interviewed, $selected, $offers, $joined, $spend) {
            $source = $sources->get($sourceId);
            $applications = $sourced->get($sourceId, 0);
            $interviewCount = $interviewed->get($sourceId, 0);
            $selectedCount = $selected->get($sourceId, 0);
            $joinedCount = $joined->get($sourceId, 0);
            $sourceSpend = $spend->get($sourceId, 0.0);

            return [
                'source' => $source,
                'source_name' => $source?->name ?? 'Not recorded',
                'spend' => $sourceSpend,
                'sourced' => $applications,
                'connected' => $connected->get($sourceId, 0),
                'interested' => $interested->get($sourceId, 0),
                'interviewed' => $interviewCount,
                'selected' => $selectedCount,
                'offers' => $offers->get($sourceId, 0),
                'joined' => $joinedCount,
                'conversion_percent' => $applications > 0 ? round($joinedCount / $applications * 100, 1) : null,
                'cost_per_interview' => $interviewCount > 0 ? round($sourceSpend / $interviewCount, 2) : null,
                'cost_per_selection' => $selectedCount > 0 ? round($sourceSpend / $selectedCount, 2) : null,
                'cost_per_join' => $joinedCount > 0 ? round($sourceSpend / $joinedCount, 2) : null,
            ];
        })->values();
    }

    /**
     * Open/on-hold requisitions that have crossed the configurable `vacancy_ageing_alert_days`
     * threshold (Section 36), oldest first, with their priority label (ageing per
     * RecruitmentRequisition::ageingInDays() — requisition.ageing). Pass
     * `$includeWithinThreshold: true` to get every open/on-hold requisition instead (positionHealth()
     * needs the full set, since pipeline-based risk applies to young requisitions too).
     *
     * @return Collection<int, array{requisition: RecruitmentRequisition, ageing_days: int, is_overdue: bool, priority: string|null}>
     */
    public function vacancyAgeing(?User $user = null, bool $includeWithinThreshold = false): Collection
    {
        $thresholdDays = (int) RecruitmentSetting::get('vacancy_ageing_alert_days', 30);

        // Phase 8.5: requisition involvement (RecruitmentRequisition::scopeVisibleTo) — the same
        // requisitions the viewer sees everywhere else, hiring and reporting managers included.
        return $this->scope->requisitions(RecruitmentRequisition::query(), MetricQuery::make(null, $user))
            ->whereIn('status', [RequisitionStatus::Open, RequisitionStatus::OnHold])
            ->get()
            ->map(fn (RecruitmentRequisition $requisition) => [
                'requisition' => $requisition,
                'ageing_days' => $requisition->ageingInDays(),
                'is_overdue' => $requisition->ageingInDays() > $thresholdDays,
                'priority' => $requisition->priority?->label(),
            ])
            ->when(! $includeWithinThreshold, fn (Collection $rows) => $rows->where('is_overdue', true))
            ->sortByDesc('ageing_days')
            ->values();
    }

    /**
     * The start of the time-to-hire measurement for one application, per the configured
     * `time_to_hire_start_point` — the single definition shared by analytics and the Outcome Loop.
     */
    public static function timeToHireStart(CandidateApplication $application, string $startPoint): ?CarbonInterface
    {
        return match ($startPoint) {
            'requisition_opened' => $application->requisition->opening_date ?? $application->requisition->created_at,
            'candidate_sourced' => $application->candidate->created_at,
            default => $application->application_date,
        };
    }

    /**
     * Time to hire for the period (hiring.time_to_hire, D1/D1a/D2): the median of whole days from each
     * hire's frozen start point to its actual joining date, with the mean, sample and unknown count.
     */
    public function timeToHire(CarbonInterface $start, CarbonInterface $end, ?User $user = null, ?int $departmentId = null): MetricResult
    {
        return $this->metric('hiring.time_to_hire', $start, $end, $user, ['department_id' => $departmentId]);
    }

    /**
     * Turn-up for a period (interview.turn_up_rate): of the past interviews scheduled in the period
     * whose outcome is recorded, how many were attended. Upcoming interviews are not line-ups yet, and
     * past interviews nobody updated are "not updated", never a no-show.
     *
     * @return array{lineups: int, turnups: int, no_shows: int, cancelled: int, rescheduled: int, not_updated: int, upcoming: int, turnup_percent: float|null}
     */
    public function turnUpAnalysis(CarbonInterface $start, CarbonInterface $end, ?User $user = null): array
    {
        $result = $this->metric('interview.turn_up_rate', $start, $end, $user);

        return [
            'lineups' => (int) $result->detail('decided', 0) + (int) $result->detail('not_updated', 0),
            'turnups' => (int) $result->detail('completed', 0),
            'no_shows' => (int) $result->detail('no_show', 0),
            'cancelled' => (int) $result->detail('cancelled', 0),
            'rescheduled' => (int) $result->detail('rescheduled', 0),
            'not_updated' => (int) $result->detail('not_updated', 0),
            'upcoming' => (int) $result->detail('upcoming', 0),
            'turnup_percent' => $result->isAvailable() ? $result->value : null,
        ];
    }

    /**
     * Day-by-day line-up/turn-up/no-show counts for a trend chart (Sections 4/17's trend and
     * no-show requirements are the same underlying series, so they share this one method).
     *
     * @return Collection<int, array{date: string, lineups: int, turnups: int, no_shows: int, turnup_percent: float|null}>
     */
    public function turnUpTrend(CarbonInterface $start, CarbonInterface $end, ?User $user = null): Collection
    {
        $metricPeriod = MetricPeriod::between($start, $end);
        $interviews = $this->scopedInterviews($start, $end, $user)->get(['id', 'status', 'scheduled_at']);

        $period = collect();
        for ($day = $metricPeriod->from; $day->lte($metricPeriod->to); $day = $day->addDay()) {
            $period->push($day->toDateString());
        }

        // Business-timezone days; a day's line-ups are its interviews with a recorded outcome.
        $byDate = $interviews->groupBy(fn (Interview $i) => MetricPeriod::businessDate($i->scheduled_at));

        return $period->map(function (string $date) use ($byDate) {
            $dayInterviews = $byDate->get($date, collect());
            $lineups = $dayInterviews->whereIn('status', [InterviewStatus::Completed, InterviewStatus::NoShow])->count();
            $turnups = $dayInterviews->where('status', InterviewStatus::Completed)->count();

            return [
                'date' => $date,
                'lineups' => $lineups,
                'turnups' => $turnups,
                'no_shows' => $dayInterviews->where('status', InterviewStatus::NoShow)->count(),
                'turnup_percent' => $lineups > 0 ? round($turnups / $lineups * 100, 1) : null,
            ];
        });
    }

    /**
     * Expected vs actual joins per period bucket — distinct from turnUpTrend(), which is about
     * interview line-ups. "Expected" counts joinings whose expected_doj falls in the bucket (any
     * status); "joined" counts Joined records whose actual_doj falls in the bucket. Buckets are days
     * for ranges up to 31 days, ISO weeks up to ~6 months, and calendar months beyond that.
     *
     * @return Collection<int, array{period: string, start: string, end: string, expected: int, joined: int}>
     */
    public function joiningTrend(CarbonInterface $start, CarbonInterface $end, ?User $user = null): Collection
    {
        $metricPeriod = MetricPeriod::between($start, $end);
        $rangeStart = CarbonImmutable::parse($metricPeriod->fromDate());
        $rangeEnd = CarbonImmutable::parse($metricPeriod->toDate());
        $days = $metricPeriod->days();

        $scope = fn (Builder $q) => $this->scope->throughApplication($q, MetricQuery::make($metricPeriod, $user));

        // Expected = joinings due in the bucket (any status); joined = hires (hiring.hires).
        $expectedDates = $metricPeriod->whereDateColumn(CandidateJoining::query(), 'expected_doj')
            ->tap($scope)
            ->pluck('expected_doj')
            ->map(fn ($date) => Carbon::parse($date)->toDateString());

        $joinedDates = $metricPeriod->whereDateColumn(CandidateJoining::query(), 'actual_doj')
            ->where('status', JoiningStatus::Joined)
            ->tap($scope)
            ->pluck('actual_doj')
            ->map(fn ($date) => Carbon::parse($date)->toDateString());

        [$alignStart, $step, $format] = match (true) {
            $days <= 31 => [fn (CarbonImmutable $d) => $d, fn (CarbonImmutable $d) => $d->addDay(), 'd M'],
            $days <= 183 => [fn (CarbonImmutable $d) => $d->startOfWeek(), fn (CarbonImmutable $d) => $d->addWeek(), 'd M'],
            default => [fn (CarbonImmutable $d) => $d->startOfMonth(), fn (CarbonImmutable $d) => $d->addMonthNoOverflow(), 'M Y'],
        };

        $buckets = collect();

        for ($cursor = $alignStart($rangeStart); $cursor->lte($rangeEnd); $cursor = $step($cursor)) {
            $bucketStart = $cursor->max($rangeStart)->toDateString();
            $bucketEnd = $step($cursor)->subDay()->min($rangeEnd)->toDateString();
            $inBucket = fn (string $date): bool => $date >= $bucketStart && $date <= $bucketEnd;

            $buckets->push([
                'period' => $cursor->max($rangeStart)->format($format),
                'start' => $bucketStart,
                'end' => $bucketEnd,
                'expected' => $expectedDates->filter($inBucket)->count(),
                'joined' => $joinedDates->filter($inBucket)->count(),
            ]);
        }

        return $buckets;
    }

    /**
     * Turn-up -> Selection -> Joining ratios grouped by recruiter, requisition, or source (Sections
     * 5/6/14 — the same three-step conversion, sliced along whichever dimension the caller needs).
     *
     * Phase 8.5: the cohort is the applications created in the period; groups are keyed by id (two
     * recruiters with the same name are never merged); a selection is a genuine entry into Selected
     * or later (DF-9); counts come from grouped queries, not one query per group.
     *
     * @param  'recruiter'|'requisition'|'source'  $groupBy
     * @return Collection<int, array{group: string, turnups: int, selections: int, joined: int, selection_ratio: float|null, joining_ratio: float|null}>
     */
    public function conversionBreakdown(string $groupBy, CarbonInterface $start, CarbonInterface $end, ?User $user = null): Collection
    {
        $period = MetricPeriod::between($start, $end);
        $column = match ($groupBy) {
            'requisition' => 'candidate_applications.requisition_id',
            'source' => 'candidates.source_id',
            default => 'candidate_applications.recruiter_id',
        };

        $cohort = $this->scope->applications(CandidateApplication::query(), MetricQuery::make($period, $user))
            ->tap(fn (Builder $q) => $period->whereDateColumn($q, 'candidate_applications.application_date'))
            ->join('candidates', 'candidates.id', '=', 'candidate_applications.candidate_id')
            ->selectRaw("candidate_applications.id, {$column} as group_id");

        $count = fn (\Closure $facts) => DB::query()->fromSub($cohort, 'cohort')->tap($facts)
            ->selectRaw('cohort.group_id, count(distinct cohort.id) as total')->groupBy('cohort.group_id')
            ->pluck('total', 'group_id')->mapWithKeys(fn ($total, $group) => [(string) $group => (int) $total]);

        $groups = DB::query()->fromSub($cohort, 'cohort')->distinct()->pluck('group_id')->map(fn ($id) => (string) $id);
        $turnups = $count(fn ($q) => $q->whereExists(fn ($e) => $e->from('interviews')->whereColumn('interviews.candidate_application_id', 'cohort.id')->where('interviews.status', InterviewStatus::Completed->value)));
        $selections = $count(fn ($q) => $q->whereExists(fn ($e) => $e->from('candidate_stage_histories')->whereColumn('candidate_stage_histories.candidate_application_id', 'cohort.id')
            ->whereIn('candidate_stage_histories.new_stage', $this->stagesFrom(CandidateStage::Selected))
            ->tap(fn ($m) => CandidateStageHistory::constrainToMilestoneEntries($m))));
        $joined = $count(fn ($q) => $q->whereExists(fn ($e) => $e->from('candidate_joinings')->whereColumn('candidate_joinings.candidate_application_id', 'cohort.id')->where('candidate_joinings.status', JoiningStatus::Joined->value)));

        $ids = $groups->filter(fn (string $id) => $id !== '')->map(fn (string $id) => (int) $id);
        $labels = match ($groupBy) {
            'requisition' => RecruitmentRequisition::query()->whereIn('id', $ids)->pluck('code', 'id'),
            'source' => CandidateSource::query()->whereIn('id', $ids)->pluck('name', 'id'),
            default => Employee::withTrashed()->whereIn('id', $ids)->get(['id', 'first_name', 'last_name'])->mapWithKeys(fn (Employee $e) => [$e->id => $e->fullName()]),
        };

        return $groups->map(function (string $group) use ($labels, $groupBy, $turnups, $selections, $joined) {
            $turnupCount = $turnups->get($group, 0);
            $selectionCount = $selections->get($group, 0);
            $joinedCount = $joined->get($group, 0);

            return [
                'group' => $group === '' ? ($groupBy === 'source' ? 'Not recorded' : 'Unassigned') : (string) ($labels[(int) $group] ?? 'Unassigned'),
                'group_id' => $group === '' ? null : (int) $group,
                'turnups' => $turnupCount,
                'selections' => $selectionCount,
                'joined' => $joinedCount,
                'selection_ratio' => $turnupCount > 0 ? round($selectionCount / $turnupCount * 100, 1) : null,
                'joining_ratio' => $selectionCount > 0 ? round($joinedCount / $selectionCount * 100, 1) : null,
            ];
        })->values();
    }

    /**
     * How long currently-active applications have sat in each of a curated set of pipeline
     * checkpoints, bucketed by days since their last recorded activity (Section 12). Phase 8.5: an
     * application with no recorded activity is its own bucket ("no_activity"), never "0–2 days".
     *
     * @return Collection<int, array{stage: CandidateStage, total: int, buckets: array{0_2: int, 3_5: int, 6_10: int, 10_plus: int, no_activity: int}}>
     */
    public function candidateAging(?User $user = null): Collection
    {
        $stages = [
            CandidateStage::Sourced,
            CandidateStage::Shortlisted,
            CandidateStage::InterviewScheduled,
            CandidateStage::Selected,
            CandidateStage::OfferReleased,
        ];
        $now = now();
        $boundary = fn (int $days) => $now->copy()->subDays($days + 1)->format('Y-m-d H:i:s');

        $rows = $this->scope->applications(CandidateApplication::query(), MetricQuery::make(null, $user))
            ->whereIn('current_stage', array_map(fn (CandidateStage $stage) => $stage->value, $stages))
            ->where('status', ApplicationStatus::Active->value)
            ->selectRaw('current_stage,
                sum(case when last_activity_at is null then 1 else 0 end) as no_activity,
                sum(case when last_activity_at > ? then 1 else 0 end) as b0_2,
                sum(case when last_activity_at <= ? and last_activity_at > ? then 1 else 0 end) as b3_5,
                sum(case when last_activity_at <= ? and last_activity_at > ? then 1 else 0 end) as b6_10,
                sum(case when last_activity_at <= ? then 1 else 0 end) as b10_plus,
                count(*) as total', [$boundary(2), $boundary(2), $boundary(5), $boundary(5), $boundary(10), $boundary(10)])
            ->groupBy('current_stage')
            ->get()
            ->keyBy(fn ($row) => $row->current_stage->value);

        return collect($stages)->map(function (CandidateStage $stage) use ($rows) {
            $row = $rows->get($stage->value);

            return [
                'stage' => $stage,
                'total' => (int) ($row->total ?? 0),
                'buckets' => [
                    '0_2' => (int) ($row->b0_2 ?? 0),
                    '3_5' => (int) ($row->b3_5 ?? 0),
                    '6_10' => (int) ($row->b6_10 ?? 0),
                    '10_plus' => (int) ($row->b10_plus ?? 0),
                    'no_activity' => (int) ($row->no_activity ?? 0),
                ],
            ];
        });
    }

    /**
     * Fulfilment, pipeline size, and a configurable risk flag for every open/on-hold requisition
     * (Sections 15/20). Built on top of vacancyAgeing() rather than re-deriving the ageing/overdue
     * calculation. Risk thresholds are configurable via RecruitmentSetting, not hard-coded.
     *
     * Deliberately does NOT treat a low fulfilment_percent on its own as a risk signal: a
     * freshly-opened requisition legitimately has 0% fulfilment on day one, so that would flag
     * every new position as "at risk" regardless of how healthy its pipeline actually is.
     * fulfilment_percent is still returned for display; risk is driven only by the leading
     * indicators (ageing overdue, pipeline thin relative to what's left to fill, or no pipeline at
     * all). Phase 8.5: a requisition with no openings has no fulfilment (null, not 0%), and the
     * counts are preloaded instead of queried per requisition.
     *
     * @return Collection<int, array{requisition: RecruitmentRequisition, required: int, filled: int, remaining: int, fulfilment_percent: float|null, pipeline: int, ageing_days: int, is_overdue: bool, risk: string}>
     */
    public function positionHealth(?User $user = null): Collection
    {
        $minPipelineRatio = (float) RecruitmentSetting::get('position_risk_min_pipeline_ratio', 2.0);
        $maxDaysOpen = (int) RecruitmentSetting::get('position_risk_max_days_open', 45);
        $rows = $this->vacancyAgeing($user, includeWithinThreshold: true);

        $counts = RecruitmentRequisition::query()
            ->whereIn('id', $rows->pluck('requisition.id'))
            ->withFilledOpeningsCount()
            ->withCount(['applications as active_pipeline_count' => fn (Builder $a) => $a->where('status', ApplicationStatus::Active->value)])
            ->get(['id'])
            ->keyBy('id');

        return $rows->map(function (array $row) use ($minPipelineRatio, $maxDaysOpen, $counts) {
            $requisition = $row['requisition'];
            $filled = (int) ($counts->get($requisition->id)?->filled_openings_count ?? 0);
            $remaining = max(0, (int) $requisition->openings - $filled);
            $pipeline = (int) ($counts->get($requisition->id)?->active_pipeline_count ?? 0);
            $fulfilmentPercent = $requisition->openings > 0 ? round($filled / $requisition->openings * 100, 1) : null;

            $isCritical = $remaining > 0 && ($row['ageing_days'] > $maxDaysOpen || $pipeline === 0);
            $isAtRisk = ! $isCritical && $remaining > 0 && (
                $row['is_overdue']
                || $pipeline < $remaining * $minPipelineRatio
            );

            return [
                'requisition' => $requisition,
                'required' => (int) $requisition->openings,
                'filled' => $filled,
                'remaining' => $remaining,
                'fulfilment_percent' => $fulfilmentPercent,
                'pipeline' => $pipeline,
                'ageing_days' => $row['ageing_days'],
                'is_overdue' => $row['is_overdue'],
                'risk' => $isCritical ? 'critical' : ($isAtRisk ? 'at_risk' : 'on_track'),
            ];
        })->values();
    }

    /**
     * Interview completion/no-show/selection rates for a period, plus an interviewer-wise
     * breakdown (Section 16). Recruiter-wise/position-wise breakdowns are already covered by
     * conversionBreakdown('recruiter'|'requisition') — not duplicated here.
     *
     * Phase 8.5: completion and no-show percentages are the governed interview.turn_up_rate and
     * interview.no_show_rate — over interviews whose outcome is recorded (completed or no-show), so
     * upcoming, cancelled and never-updated interviews no longer count as missed.
     *
     * @return array{scheduled: int, completed: int, no_show: int, cancelled: int, rescheduled: int, feedback_pending: int, completion_percent: float|null, no_show_percent: float|null, selection_percent: float|null, by_interviewer: Collection<int, array{interviewer: string, scheduled: int, completed: int, no_show: int, no_show_percent: float|null, selected: int}>, by_round: Collection<int, array{round: int, scheduled: int, completed: int, no_show: int, selected: int}>}
     */
    public function interviewAnalytics(CarbonInterface $start, CarbonInterface $end, ?User $user = null): array
    {
        $interviews = $this->scopedInterviews($start, $end, $user)
            ->with('interviewer:id,first_name,last_name')
            ->get(['id', 'interviewer_id', 'round_number', 'status', 'result', 'scheduled_at']);

        $byRound = $interviews
            ->groupBy(fn (Interview $i) => (int) $i->round_number)
            ->map(fn (Collection $group, int $round) => [
                'round' => $round,
                'scheduled' => $group->count(),
                'completed' => $group->where('status', InterviewStatus::Completed)->count(),
                'no_show' => $group->where('status', InterviewStatus::NoShow)->count(),
                'selected' => $group->where('result', InterviewResult::Selected)->count(),
            ])
            ->sortKeys()
            ->values();

        $completed = $interviews->where('status', InterviewStatus::Completed)->count();
        $selected = $interviews->where('result', InterviewResult::Selected)->count();
        $turnUp = $this->metric('interview.turn_up_rate', $start, $end, $user);
        $noShow = $this->metric('interview.no_show_rate', $start, $end, $user);

        $byInterviewer = $interviews
            ->groupBy(fn (Interview $i) => $i->interviewer_id ?? 0)
            ->map(function (Collection $group) {
                $groupCompleted = $group->where('status', InterviewStatus::Completed)->count();
                $groupNoShow = $group->where('status', InterviewStatus::NoShow)->count();
                $decided = $groupCompleted + $groupNoShow;

                return [
                    'interviewer' => $group->first()->interviewer?->fullName() ?? 'Unassigned',
                    'scheduled' => $group->count(),
                    'completed' => $groupCompleted,
                    'no_show' => $groupNoShow,
                    'no_show_percent' => $decided >= (int) config('metrics.min_sample', 3) ? round($groupNoShow / $decided * 100, 1) : null,
                    'selected' => $group->where('result', InterviewResult::Selected)->count(),
                ];
            })
            ->values();

        return [
            'scheduled' => $interviews->count(),
            'completed' => $completed,
            'no_show' => $interviews->where('status', InterviewStatus::NoShow)->count(),
            'cancelled' => $interviews->where('status', InterviewStatus::Cancelled)->count(),
            'rescheduled' => $interviews->where('status', InterviewStatus::Rescheduled)->count(),
            'feedback_pending' => $interviews->where('status', InterviewStatus::Completed)->whereNull('result')->count(),
            'completion_percent' => $turnUp->isAvailable() ? $turnUp->value : null,
            'no_show_percent' => $noShow->isAvailable() ? $noShow->value : null,
            'selection_percent' => $completed > 0 ? round($selected / $completed * 100, 1) : null,
            'by_interviewer' => $byInterviewer,
            'by_round' => $byRound,
        ];
    }

    /**
     * Offer pipeline for a period (Section 18). Phase 8.5:
     * - `acceptance_percent` is offer.acceptance_rate (D4): accepted ÷ (accepted + rejected + expired)
     *   among offers first released in the period; `decided_acceptance_percent` is
     *   offer.decided_acceptance_rate (accepted ÷ (accepted + rejected)). `released` and the status
     *   counts describe that same released population.
     * - `generated` counts offer rows dated in the period (offer volume, D27).
     * - `average_offered_ctc` is compensation: withheld below config metrics.compensation_min_group
     *   offers (D22), and shown only to viewers allowed to see compensation (the widget's gate).
     * - `average_days_selection_to_offer` runs from the first genuine Selected entry to the offer date.
     *
     * @return array{generated: int, released: int, accepted: int, rejected: int, pending: int, expired: int, withdrawn: int, acceptance_percent: float|null, decided_acceptance_percent: float|null, average_offered_ctc: float|null, average_days_selection_to_offer: float|null}
     */
    public function offerAnalytics(CarbonInterface $start, CarbonInterface $end, ?User $user = null): array
    {
        $period = MetricPeriod::between($start, $end);

        $offers = $this->scope->throughApplication(Offer::query(), MetricQuery::make($period, $user))
            ->tap(fn (Builder $q) => $period->whereDateColumn($q, 'offer_date'))
            ->get(['id', 'candidate_application_id', 'status', 'offered_ctc', 'offer_date']);

        $acceptance = $this->metric('offer.acceptance_rate', $start, $end, $user);
        $decided = $this->metric('offer.decided_acceptance_rate', $start, $end, $user);

        $ctcValues = $offers->pluck('offered_ctc')->filter(fn ($ctc) => $ctc !== null)->map(fn ($ctc) => (float) $ctc);

        $selectedAt = CandidateStageHistory::query()->milestoneEntries()
            ->whereIn('candidate_application_id', $offers->pluck('candidate_application_id')->unique())
            ->where('new_stage', CandidateStage::Selected)
            ->orderBy('created_at')
            ->get(['candidate_application_id', 'created_at'])
            ->unique('candidate_application_id')
            ->mapWithKeys(fn (CandidateStageHistory $h) => [$h->candidate_application_id => $h->created_at]);

        $selectionToOfferDays = $offers
            ->map(function (Offer $offer) use ($selectedAt): ?int {
                $selected = $selectedAt->get($offer->candidate_application_id);

                return $selected === null || $offer->offer_date === null
                    ? null
                    : (int) CarbonImmutable::parse(MetricPeriod::businessDate($selected))->diffInDays(CarbonImmutable::parse($offer->offer_date->toDateString()), false);
            })
            ->filter(fn (?int $days) => $days !== null && $days >= 0);

        return [
            'released' => (int) $acceptance->detail('released', 0),
            'accepted' => (int) $acceptance->detail('accepted', 0),
            'rejected' => (int) $acceptance->detail('rejected', 0),
            'expired' => (int) $acceptance->detail('expired', 0),
            'withdrawn' => (int) $acceptance->detail('withdrawn', 0),
            'pending' => (int) $acceptance->detail('awaiting', 0),
            'acceptance_percent' => $acceptance->isAvailable() ? $acceptance->value : null,
            'decided_acceptance_percent' => $decided->isAvailable() ? $decided->value : null,
            'average_offered_ctc' => $ctcValues->count() >= (int) config('metrics.compensation_min_group', 5) ? round($ctcValues->avg(), 2) : null,
            'average_days_selection_to_offer' => $selectionToOfferDays->isNotEmpty() ? round($selectionToOfferDays->avg(), 1) : null,
            'generated' => $offers->count(),
        ];
    }

    /**
     * Joining pipeline for a period plus the near-term joining schedule (Section 19). Phase 8.5:
     * - `joined` is hiring.hires (joining records marked Joined, dated by the actual joining date).
     * - `join_rate_percent` is joining.join_rate and `no_show` / `dropout` its outcome counts (status
     *   set in the period); the old Selection -> Joining ratio mixed two cohorts and is retired (D5).
     * - `offer_to_join_percent` is joining.offer_to_join (per application, decided joinings only).
     * - `next_7_days` is today and the six days after it (seven business-timezone days).
     *
     * @return array{selected: int, offered: int, accepted: int, accepted_joined: int, joined: int, no_show: int, dropout: int, pending: int, join_rate_percent: float|null, offer_to_join_percent: float|null, today: int, tomorrow: int, next_7_days: int}
     */
    public function joiningAnalytics(CarbonInterface $start, CarbonInterface $end, ?User $user = null): array
    {
        $period = MetricPeriod::between($start, $end);
        $query = MetricQuery::make($period, $user);

        $joinRate = $this->metric('joining.join_rate', $start, $end, $user);
        $offerToJoin = $this->metric('joining.offer_to_join', $start, $end, $user);
        // The stage_activity count for Selected, without computing every other stage.
        $selected = $this->scope->throughApplication(CandidateStageHistory::query()->milestoneEntries(), $query)
            ->where('candidate_stage_histories.new_stage', CandidateStage::Selected->value)
            ->tap(fn (Builder $q) => $period->whereTimestampColumn($q, 'candidate_stage_histories.created_at'))
            ->distinct()
            ->count('candidate_stage_histories.candidate_application_id');

        $offered = $this->scope->throughApplication(Offer::query(), $query)
            ->tap(fn (Builder $q) => $period->whereDateColumn($q, 'offer_date'))
            ->count();

        $today = MetricPeriod::now()->startOfDay();
        $upcoming = fn (string $from, string $to) => $this->scope->throughApplication(CandidateJoining::query(), $query)
            ->whereIn('status', [JoiningStatus::Expected, JoiningStatus::Confirmed])
            ->tap(fn (Builder $q) => MetricPeriod::dates($from, $to)->whereDateColumn($q, 'expected_doj'))
            ->count();

        return [
            'selected' => (int) $selected,
            'offered' => $offered,
            'accepted' => (int) $offerToJoin->detail('accepted_applications', 0),
            'accepted_joined' => (int) $offerToJoin->detail('joined', 0),
            'joined' => (int) $joinRate->detail('joined', 0),
            'no_show' => (int) $joinRate->detail('no_show', 0),
            'dropout' => (int) $joinRate->detail('dropout', 0),
            'pending' => (int) $joinRate->detail('pending', 0),
            'join_rate_percent' => $joinRate->isAvailable() ? $joinRate->value : null,
            'offer_to_join_percent' => $offerToJoin->isAvailable() ? $offerToJoin->value : null,
            'today' => $upcoming($today->toDateString(), $today->toDateString()),
            'tomorrow' => $upcoming($today->addDay()->toDateString(), $today->addDay()->toDateString()),
            'next_7_days' => $upcoming($today->toDateString(), $today->addDays(6)->toDateString()),
        ];
    }

    /**
     * Pending (Expected/Confirmed) joinings flagged yellow/red by CandidateJoining::riskLevel() —
     * never reimplemented here, always delegated to the model (Section 19). Joined, cancelled,
     * no-show and dropout joinings are closed and never "at risk" (DF-8).
     *
     * @return Collection<int, array{joining: CandidateJoining, risk: string}>
     */
    public function joiningRisks(?User $user = null): Collection
    {
        return $this->scope->throughApplication(CandidateJoining::query(), MetricQuery::make(null, $user))
            ->whereIn('status', [JoiningStatus::Expected, JoiningStatus::Confirmed])
            ->with(['candidateApplication.candidate:id,full_name'])
            ->get()
            ->map(fn (CandidateJoining $joining) => ['joining' => $joining, 'risk' => $joining->riskLevel()])
            ->filter(fn (array $row) => in_array($row['risk'], ['yellow', 'red'], true))
            ->values();
    }

    /**
     * @return Builder<Interview>
     */
    private function scopedInterviews(CarbonInterface $start, CarbonInterface $end, ?User $user): Builder
    {
        $period = MetricPeriod::between($start, $end);

        return $this->scope->throughApplication(Interview::query(), MetricQuery::make($period, $user))
            ->tap(fn (Builder $q) => $period->whereTimestampColumn($q, 'interviews.scheduled_at'));
    }

    /**
     * The canonical stages from $stage onwards (reaching a later stage implies passing $stage).
     *
     * @return array<int, string>
     */
    private function stagesFrom(CandidateStage $stage): array
    {
        return collect(CandidateStage::cases())->filter(fn (CandidateStage $s) => $s->order() >= $stage->order())->map->value->values()->all();
    }

    /**
     * Phase 5 communication metrics for messages to candidates the user can see. Only
     * provider-reported facts are counted: delivered/read/opened come solely from provider
     * webhooks, so a metric no provider reported is returned as null ("not reported") rather than
     * 0 — e.g. the `log` mailer never reports delivery.
     *
     * @return array{total: int, by_status: array<string, int>, by_channel: array<string, int>, sent: int, delivered: int|null, read: int|null, opened: int|null, failed: int, blocked: int, delivery_rate: float|null, opted_out: int, interview_confirmations: int}
     */
    public function communicationAnalytics(CarbonInterface $start, CarbonInterface $end, ?User $user = null): array
    {
        $visibleIds = $user !== null ? $this->hierarchy->visibleEmployeeIdsFor($user) : null;

        $scope = fn (Builder $q) => $q->when($visibleIds !== null, fn (Builder $q) => $q->whereHas('candidate', fn (Builder $c) => $c->where(function (Builder $c2) use ($visibleIds, $user): void {
            $c2->whereHas('applications', fn (Builder $a) => $a->whereIn('recruiter_id', $visibleIds));

            if ($user?->employee_id !== null) {
                $c2->orWhere('created_by', $user->employee_id);
            }
        })));

        $period = MetricPeriod::between($start, $end);
        $messages = $period->whereTimestampColumn(CandidateCommunication::query(), 'candidate_communications.created_at')->tap($scope);

        $byStatus = (clone $messages)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn ($n) => (int) $n);
        $byChannel = (clone $messages)->selectRaw('channel, count(*) as total')->groupBy('channel')->pluck('total', 'channel')->map(fn ($n) => (int) $n);
        $count = fn (CommunicationStatus ...$statuses): int => (int) collect($statuses)->sum(fn (CommunicationStatus $s) => $byStatus->get($s->value, 0));

        $handedOver = $count(CommunicationStatus::Sent, CommunicationStatus::Delivered, CommunicationStatus::Read, CommunicationStatus::Bounced);
        $delivered = $count(CommunicationStatus::Delivered, CommunicationStatus::Read);
        $anyDeliveryReports = (clone $messages)->whereNotNull('delivered_at')->exists();
        $opened = (clone $messages)->whereNotNull('opened_at')->count();

        return [
            'total' => (int) $byStatus->sum(),
            'by_status' => $byStatus->all(),
            'by_channel' => $byChannel->all(),
            'sent' => $handedOver,
            'delivered' => $anyDeliveryReports ? $delivered : null,
            'read' => $anyDeliveryReports ? $count(CommunicationStatus::Read) : null,
            'opened' => $opened > 0 ? $opened : null,
            'failed' => $count(CommunicationStatus::Failed, CommunicationStatus::Bounced),
            'blocked' => $count(CommunicationStatus::Blocked),
            'delivery_rate' => $anyDeliveryReports && $handedOver > 0 ? round($delivered / $handedOver * 100, 1) : null,
            'opted_out' => CandidateCommunicationPreference::query()
                ->where('status', PreferenceStatus::OptedOut)
                ->tap(fn (Builder $q) => $period->whereTimestampColumn($q, 'opted_out_at'))
                ->when($visibleIds !== null, fn (Builder $q) => $q->whereHas('candidate.applications', fn (Builder $a) => $a->whereIn('recruiter_id', $visibleIds)))
                ->count(),
            'interview_confirmations' => Interview::query()
                ->where('status', InterviewStatus::Confirmed)
                ->tap(fn (Builder $q) => $period->whereTimestampColumn($q, 'updated_at'))
                ->when($visibleIds !== null, fn (Builder $q) => $q->whereHas('candidateApplication', fn (Builder $a) => $a->whereIn('recruiter_id', $visibleIds)))
                ->count(),
        ];
    }

    /**
     * Phase 5 campaign metrics, computed from the existing records attributed to the campaign
     * (candidate_applications.campaign_id, recruitment_costs.campaign_id) with the same stage/
     * interview/offer/joining facts every other funnel here uses. Spend sums all of the campaign's
     * recruitment costs, matching sourceAnalytics()'s convention.
     *
     * @return array{applications: int, screened: int, interviewed: int, offers: int, joined: int, spend: float, budget: float|null, budget_used_percent: float|null, target_hires: int|null, target_progress_percent: float|null, cost_per_hire: float|null, conversion_percent: float|null, by_origin: array<string, int>}
     */
    public function campaignAnalytics(RecruitmentCampaign $campaign, ?User $user = null): array
    {
        $visibleIds = $user !== null ? $this->hierarchy->visibleEmployeeIdsFor($user) : null;

        $applications = CandidateApplication::query()
            ->where('campaign_id', $campaign->id)
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereIn('recruiter_id', $visibleIds));

        $applicationIds = (clone $applications)->pluck('id');
        $reached = fn (CandidateStage $stage): int => CandidateStageHistory::query()->milestoneEntries()
            ->whereIn('candidate_application_id', $applicationIds)
            ->whereIn('new_stage', $this->stagesFrom($stage))
            ->distinct('candidate_application_id')
            ->count('candidate_application_id');

        $joined = CandidateJoining::query()->whereIn('candidate_application_id', $applicationIds)->where('status', JoiningStatus::Joined)->count();
        // Phase 8.5 (D36): spend over the requisitions the viewer is involved in.
        $spend = (float) $this->scope->throughRequisition(RecruitmentCost::query(), MetricQuery::make(null, $user))->where('campaign_id', $campaign->id)->sum('amount');
        $budget = $campaign->budget !== null ? (float) $campaign->budget : null;

        return [
            'applications' => $applicationIds->count(),
            'screened' => $reached(CandidateStage::Screened),
            'interviewed' => Interview::query()->whereIn('candidate_application_id', $applicationIds)->where('status', InterviewStatus::Completed)->distinct('candidate_application_id')->count('candidate_application_id'),
            'offers' => Offer::query()->whereIn('candidate_application_id', $applicationIds)->distinct('candidate_application_id')->count('candidate_application_id'),
            'joined' => $joined,
            'spend' => $spend,
            'budget' => $budget,
            'budget_used_percent' => $budget !== null && $budget > 0 ? round($spend / $budget * 100, 1) : null,
            'target_hires' => $campaign->target_hires,
            'target_progress_percent' => $campaign->target_hires ? round($joined / $campaign->target_hires * 100, 1) : null,
            'cost_per_hire' => $joined > 0 ? round($spend / $joined, 2) : null,
            'conversion_percent' => $applicationIds->count() > 0 ? round($joined / $applicationIds->count() * 100, 1) : null,
            'by_origin' => (clone $applications)->selectRaw("coalesce(origin_channel, 'recruiter') as origin, count(*) as total")->groupBy('origin')->pluck('total', 'origin')->map(fn ($n) => (int) $n)->all(),
        ];
    }

    /**
     * Phase 5 distribution metrics: postings published/live, and online applications per channel
     * with how far they progressed (interviews, offers, joins) — the same outcome facts as the
     * funnel, grouped by origin_channel.
     *
     * @return array{published_postings: int, live_postings: int, channels: Collection<int, array{channel: string, applications: int, interviewed: int, offers: int, joined: int}>}
     */
    public function distributionAnalytics(CarbonInterface $start, CarbonInterface $end, ?User $user = null): array
    {
        $visibleIds = $user !== null ? $this->hierarchy->visibleEmployeeIdsFor($user) : null;

        $period = MetricPeriod::between($start, $end);
        $applications = $period->whereTimestampColumn(CandidateApplication::query(), 'created_at')
            ->whereNotNull('origin_channel')
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereIn('recruiter_id', $visibleIds))
            ->get(['id', 'origin_channel']);

        $ids = $applications->pluck('id');
        $interviewed = Interview::query()->whereIn('candidate_application_id', $ids)->where('status', InterviewStatus::Completed)->distinct()->pluck('candidate_application_id')->flip();
        $offered = Offer::query()->whereIn('candidate_application_id', $ids)->distinct()->pluck('candidate_application_id')->flip();
        $joined = CandidateJoining::query()->whereIn('candidate_application_id', $ids)->where('status', JoiningStatus::Joined)->pluck('candidate_application_id')->flip();

        return [
            'published_postings' => $period->whereTimestampColumn(JobPosting::query(), 'published_at')->count(),
            'live_postings' => JobPosting::query()->live()->count(),
            'channels' => $applications->groupBy('origin_channel')->map(fn (Collection $group, string $channel) => [
                'channel' => $channel,
                'applications' => $group->count(),
                'interviewed' => $group->filter(fn ($a) => $interviewed->has($a->id))->count(),
                'offers' => $group->filter(fn ($a) => $offered->has($a->id))->count(),
                'joined' => $group->filter(fn ($a) => $joined->has($a->id))->count(),
            ])->sortByDesc('applications')->values(),
        ];
    }

    /**
     * Automation activity for a period (Phase 6), hierarchy-scoped by the executions' recruiter
     * (requisition-level runs with no recruiter are only counted for users who see everything).
     * Reports what happened — runs, actions, escalations, how long automation-created actions took
     * to resolve — and deliberately makes no claim that automation *caused* an outcome.
     * Averages are null when there is nothing to average.
     *
     * @return array{active_rules: int, executions: int, completed: int, partially_completed: int, failed: int, skipped: int, cancelled: int, retried: int, actions_created: int, actions_completed: int, avg_resolution_hours: float|null, escalations_sent: int, escalations_resolved_first: int, communication_actions: int, by_rule: Collection<int, array{rule_id: int, name: string, executions: int, failed: int, failure_rate: float|null}>}
     */
    public function automationAnalytics(CarbonInterface $start, CarbonInterface $end, ?User $user = null): array
    {
        $visibleIds = $user !== null ? $this->hierarchy->visibleEmployeeIdsFor($user) : null;

        $period = MetricPeriod::between($start, $end);
        $executions = $period->whereTimestampColumn(AutomationExecution::query(), 'automation_executions.created_at')
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereIn('recruiter_id', $visibleIds));

        $byStatus = (clone $executions)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn ($n) => (int) $n);
        $count = fn (AutomationExecutionStatus $status): int => $byStatus->get($status->value, 0);

        $actions = $period->whereTimestampColumn(RecruiterAction::query(), 'recruiter_actions.created_at')
            ->whereNotNull('automation_rule_id')
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereIn('owner_id', $visibleIds));

        $resolutionHours = (clone $actions)
            ->where('status', RecruiterActionStatus::Completed)
            ->get(['created_at', 'completed_at'])
            ->map(fn (RecruiterAction $action) => $action->created_at->diffInMinutes($action->completed_at) / 60);

        $escalations = $period->whereTimestampColumn(AutomationEscalation::query(), 'automation_escalations.created_at')
            ->when($visibleIds !== null, fn (Builder $q) => $q->whereHas('execution', fn (Builder $e) => $e->whereIn('recruiter_id', $visibleIds)));

        $byRule = (clone $executions)
            ->whereIn('status', [AutomationExecutionStatus::Completed, AutomationExecutionStatus::PartiallyCompleted, AutomationExecutionStatus::Failed])
            ->selectRaw('automation_rule_id, count(*) as total, sum(case when status = ? then 1 else 0 end) as failed', [AutomationExecutionStatus::Failed->value])
            ->groupBy('automation_rule_id')
            ->get();

        $ruleNames = AutomationRule::query()->whereIn('id', $byRule->pluck('automation_rule_id'))->pluck('name', 'id');

        return [
            'active_rules' => AutomationRule::query()->active()->count(),
            'executions' => (int) $byStatus->sum(),
            'completed' => $count(AutomationExecutionStatus::Completed),
            'partially_completed' => $count(AutomationExecutionStatus::PartiallyCompleted),
            'failed' => $count(AutomationExecutionStatus::Failed),
            'skipped' => $count(AutomationExecutionStatus::Skipped),
            'cancelled' => $count(AutomationExecutionStatus::Cancelled),
            'retried' => (clone $executions)->where('retry_count', '>', 0)->count(),
            'actions_created' => (clone $actions)->count(),
            'actions_completed' => (clone $actions)->where('status', RecruiterActionStatus::Completed)->count(),
            'avg_resolution_hours' => $resolutionHours->isNotEmpty() ? round($resolutionHours->avg(), 1) : null,
            'escalations_sent' => (clone $escalations)->where('status', EscalationStatus::Sent)->count(),
            'escalations_resolved_first' => (clone $escalations)->where('status', EscalationStatus::Stopped)->count(),
            'communication_actions' => AutomationActionExecution::query()
                ->where('action_type', 'send_communication')
                ->where('status', AutomationActionStatus::Completed)
                ->whereHas('execution', fn (Builder $e) => $period->whereTimestampColumn($e, 'automation_executions.created_at')->when($visibleIds !== null, fn (Builder $q) => $q->whereIn('recruiter_id', $visibleIds)))
                ->count(),
            'by_rule' => $byRule->map(fn ($row) => [
                'rule_id' => (int) $row->automation_rule_id,
                'name' => (string) ($ruleNames[$row->automation_rule_id] ?? 'Deleted rule'),
                'executions' => (int) $row->total,
                'failed' => (int) $row->failed,
                'failure_rate' => (int) $row->total > 0 ? round((int) $row->failed / (int) $row->total * 100, 1) : null,
            ])->sortByDesc('executions')->values(),
        ];
    }

    /**
     * Raw per-requisition facts for Hiring Health™ (Phase 7) — counts and dates only, no thresholds
     * or judgements (HiringHealthService applies those).
     *
     * Phase 8.5 (DF-12): the whole requisition is measured — no cap on active applications or SLA
     * checks — with grouped queries; stage facts use genuine stage entries only (DF-9).
     *
     * @return array<string, mixed>
     */
    public function requisitionMetrics(RecruitmentRequisition $requisition): array
    {
        $applicationIds = $requisition->applications()->pluck('id');
        $active = $requisition->applications()->where('status', ApplicationStatus::Active)->with(['candidate.source', 'pipelineStage'])->get();
        $stallDays = (int) RecruitmentSetting::get('candidate_stall_days', 7);

        $firstEntry = fn (CandidateStage $stage) => CandidateStageHistory::query()->milestoneEntries()
            ->whereIn('candidate_application_id', $applicationIds)
            ->where('new_stage', $stage)
            ->orderBy('created_at')
            ->get(['candidate_application_id', 'created_at'])
            ->unique('candidate_application_id')
            ->mapWithKeys(fn (CandidateStageHistory $entry) => [$entry->candidate_application_id => $entry->created_at]);

        $shortlisted = $firstEntry(CandidateStage::Shortlisted);
        $lined = $firstEntry(CandidateStage::InterviewScheduled);
        $lineupDays = $lined->map(fn ($at, $id) => isset($shortlisted[$id]) ? $shortlisted[$id]->diffInHours($at) / 24 : null)->filter(fn ($d) => $d !== null && $d >= 0);

        $offers = Offer::query()->whereIn('candidate_application_id', $applicationIds)->get(['id', 'status', 'candidate_application_id']);
        $joinings = CandidateJoining::query()->whereIn('candidate_application_id', $applicationIds)->whereIn('status', [JoiningStatus::Expected, JoiningStatus::Confirmed])->get();
        $breaches = app(RecruitmentSlaService::class)->breachesForApplications($active);

        $statusCounts = $requisition->applications()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status')->map(fn ($n) => (int) $n);

        return [
            'days_open' => $requisition->ageingInDays(),
            'openings' => (int) $requisition->openings,
            'filled' => $requisition->filledOpeningsCount(),
            'remaining' => $requisition->remainingOpenings(),
            'total_applications' => $applicationIds->count(),
            'active_applications' => $active->count(),
            'status_counts' => $statusCounts->all(),
            'new_applications_14d' => $requisition->applications()->where('created_at', '>=', now()->subDays(14))->count(),
            'last_shortlisted_at' => $shortlisted->max(),
            'shortlist_to_lineup_days' => $lineupDays->isNotEmpty() ? round($lineupDays->avg(), 1) : null,
            'feedback_pending' => Interview::query()
                ->whereIn('candidate_application_id', $applicationIds)
                ->where('scheduled_at', '<', now())
                ->whereNotIn('status', [InterviewStatus::Completed, InterviewStatus::Cancelled, InterviewStatus::NoShow, InterviewStatus::Hold])
                ->count(),
            'offers_released' => $offers->whereIn('status', [OfferStatus::Released, OfferStatus::Accepted, OfferStatus::Rejected, OfferStatus::Expired])->count(),
            'offers_accepted' => $offers->where('status', OfferStatus::Accepted)->count(),
            'offers_declined' => $offers->whereIn('status', [OfferStatus::Rejected, OfferStatus::Expired])->count(),
            'joining_red' => $joinings->filter(fn (CandidateJoining $j) => $j->riskLevel() === 'red')->values(),
            'stalled' => $active->filter(fn (CandidateApplication $a) => ($a->last_activity_at ?? $a->created_at)->lt(now()->subDays($stallDays)))->values(),
            'stall_days' => $stallDays,
            'sla_breaches' => $breaches,
            'source_counts' => $active->groupBy(fn (CandidateApplication $a) => $a->candidate?->source?->name ?? 'Unknown source')->map->count()->sortDesc()->all(),
            'automation_failures_7d' => AutomationExecution::query()
                ->whereIn('candidate_application_id', $applicationIds)
                ->where('status', AutomationExecutionStatus::Failed)
                ->where('created_at', '>=', now()->subDays(7))
                ->count(),
            'completeness' => [
                'Skills' => ! empty($requisition->skills),
                'Experience range' => $requisition->experience_min !== null || $requisition->experience_max !== null,
                'Qualification' => filled($requisition->qualification),
                'Location' => $requisition->location_id !== null,
                'Salary range' => $requisition->salary_min !== null || $requisition->salary_max !== null,
                'Target joining date' => $requisition->target_joining_date !== null,
                'Designation' => $requisition->designation_id !== null,
            ],
        ];
    }
}
