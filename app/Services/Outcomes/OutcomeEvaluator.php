<?php

namespace App\Services\Outcomes;

use App\Enums\JoiningStatus;
use App\Enums\OfferStatus;
use App\Enums\OutcomeCategory;
use App\Enums\OutcomeType;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\HiringOutcomeSnapshot;
use App\Models\Offer;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Outcome Loop™ (Phase 8.2): the single evaluation pass behind `outcomes:evaluate`. Events record
 * outcomes as things happen; this pass catches up on anything missed within catch_up_days of the
 * event (still "observed going forward"), using indexed "not yet recorded" queries in chunks — it
 * never loads every employee or candidate. Older history is only ever reconstructed by
 * `outcomes:backfill`, labelled as backfilled. Idempotent: a second run records nothing new.
 */
class OutcomeEvaluator
{
    /**
     * @var array<int, array{step: string, record: string, exception: string, message: string}>
     */
    private array $failures = [];

    public function __construct(
        private readonly OutcomeCalculator $calculator,
        private readonly HiringSnapshotService $snapshots,
    ) {}

    /**
     * @return array<string, int> what was (or, in a dry run, would be) evaluated
     */
    public function evaluate(bool $dryRun = false): array
    {
        $batch = max(1, (int) config('outcomes.batch_size', 200));
        $counts = [];
        $this->failures = [];

        $since = now()->subDays(max(0, (int) config('outcomes.catch_up_days', 7)))->startOfDay();

        $joinings = $this->pendingJoinings($since);
        $counts['joining_outcomes'] = $dryRun ? $joinings->count() : $this->each('joining_outcomes', $joinings, $batch, fn (CandidateJoining $joining) => $this->calculator->joining($joining));

        $unsnapshotted = $this->joinedWithoutSnapshot($since);
        $counts['snapshots'] = $dryRun ? $unsnapshotted->count() : $this->each('snapshots', $unsnapshotted, $batch, fn (CandidateJoining $joining) => $this->snapshots->captureForJoining($joining));

        $offers = $this->pendingOffers($since);
        $counts['offers'] = $dryRun ? $offers->count() : $this->each('offers', $offers, $batch, fn (Offer $offer) => $this->calculator->offer($offer));

        $snapshots = $this->snapshotsWithoutProcessOutcomes();
        $counts['process_outcomes'] = $dryRun ? $snapshots->count() : $this->each('process_outcomes', $snapshots, $batch, fn (HiringOutcomeSnapshot $snapshot) => $this->calculator->process($snapshot));

        $counts['status_observations'] = 0;

        foreach ($this->observationTypes() as $type) {
            $due = $this->dueStatusObservations($type)->with('employee.separation');
            $counts['status_observations'] += $dryRun ? $due->count() : $this->each('status_observations', $due, $batch, fn (HiringOutcomeSnapshot $snapshot) => $this->calculator->statusObservation($snapshot, $type));
        }

        // Separation records can arrive after a status was observed. Saving one re-evaluates that
        // employee at once; this re-checks recent ones in case that failed — never the whole history.
        $separated = HiringOutcomeSnapshot::query()->whereHas('employee.separation', fn (Builder $query) => $query->where('updated_at', '>=', $since))->with('employee.separation');
        $counts['separation_rechecks'] = $dryRun ? $separated->count() : $this->each('separation_rechecks', $separated, $batch, fn (HiringOutcomeSnapshot $snapshot) => $this->observeAll($snapshot));
        $counts['failed'] = count($this->failures);

        return $counts;
    }

    /**
     * Re-evaluates every due checkpoint for one employee (e.g. right after a separation is saved).
     */
    public function evaluateEmployee(int $employeeId): void
    {
        HiringOutcomeSnapshot::query()->where('employee_id', $employeeId)->get()->each(fn (HiringOutcomeSnapshot $snapshot) => $this->observeAll($snapshot));
    }

    /**
     * @return Builder<HiringOutcomeSnapshot>
     */
    public function dueStatusObservations(OutcomeType $type): Builder
    {
        return HiringOutcomeSnapshot::query()
            ->where('joined_on', '<=', now()->subDays($type->windowDays())->toDateString())
            ->whereNotExists(fn (QueryBuilder $q) => $q->from('hiring_outcomes')
                ->whereColumn('hiring_outcomes.hiring_outcome_snapshot_id', 'hiring_outcome_snapshots.id')
                ->where('hiring_outcomes.outcome_type', $type->value)
                ->where('hiring_outcomes.is_current', true));
    }

    /**
     * @return array<int, OutcomeType>
     */
    private function observationTypes(): array
    {
        return collect(config('outcomes.status_observation_days', []))->map(fn (int $days) => OutcomeType::forWindow($days))->filter()->values()->all();
    }

    private function observeAll(HiringOutcomeSnapshot $snapshot): void
    {
        foreach ($this->observationTypes() as $type) {
            $this->calculator->statusObservation($snapshot, $type);
        }
    }

    /**
     * Joinings with a final status (joined, no-show, dropout) and no joining outcome yet — joined on
     * or changed since $since when given, within the backfill window and requisition otherwise.
     *
     * @return Builder<CandidateJoining>
     */
    public function pendingJoinings(?CarbonInterface $since = null, ?CarbonInterface $until = null, ?int $requisitionId = null): Builder
    {
        return $this->joinings([JoiningStatus::Joined, JoiningStatus::NoShow, JoiningStatus::Dropout], $since, $until, $requisitionId)
            ->whereNotExists(fn (QueryBuilder $q) => $q->from('hiring_outcomes')
                ->whereColumn('hiring_outcomes.candidate_joining_id', 'candidate_joinings.id')
                ->where('hiring_outcomes.category', OutcomeCategory::Joining->value)
                ->where('hiring_outcomes.is_current', true));
    }

    /**
     * Joined joinings with no hiring snapshot yet.
     *
     * @return Builder<CandidateJoining>
     */
    public function joinedWithoutSnapshot(?CarbonInterface $since = null, ?CarbonInterface $until = null, ?int $requisitionId = null): Builder
    {
        return $this->joinings([JoiningStatus::Joined], $since, $until, $requisitionId)
            ->whereNotExists(fn (QueryBuilder $q) => $q->from('hiring_outcome_snapshots')->whereColumn('hiring_outcome_snapshots.candidate_joining_id', 'candidate_joinings.id'));
    }

    /**
     * Offers with a status-history entry (in the window, when given) that has no outcome yet.
     *
     * @return Builder<Offer>
     */
    public function pendingOffers(?CarbonInterface $since = null, ?CarbonInterface $until = null, ?int $requisitionId = null): Builder
    {
        return Offer::query()
            ->when($requisitionId !== null, fn (Builder $query) => $query->whereIn('candidate_application_id', CandidateApplication::query()->where('requisition_id', $requisitionId)->select('id')))
            ->where(function (Builder $query) use ($since, $until): void {
                foreach ([OfferStatus::Released, OfferStatus::Accepted, OfferStatus::Rejected, OfferStatus::Expired, OfferStatus::Withdrawn] as $status) {
                    $query->orWhereExists(fn (QueryBuilder $q) => $q->from('offer_status_histories')
                        ->whereColumn('offer_status_histories.offer_id', 'offers.id')
                        ->where('offer_status_histories.to_status', $status->value)
                        ->when($since !== null, fn (QueryBuilder $q) => $q->where('offer_status_histories.created_at', '>=', $since))
                        ->when($until !== null, fn (QueryBuilder $q) => $q->where('offer_status_histories.created_at', '<=', $until))
                        ->whereNotExists(fn (QueryBuilder $o) => $o->from('hiring_outcomes')
                            ->whereColumn('hiring_outcomes.offer_id', 'offers.id')
                            ->where('hiring_outcomes.outcome_type', OutcomeType::forOfferStatus($status)->value)
                            ->where('hiring_outcomes.is_current', true)));
                }
            });
    }

    /**
     * A joined record is dated by its actual joining date; a no-show or dropout by its last change.
     *
     * @param  array<int, JoiningStatus>  $statuses
     * @return Builder<CandidateJoining>
     */
    private function joinings(array $statuses, ?CarbonInterface $since, ?CarbonInterface $until, ?int $requisitionId): Builder
    {
        $dated = fn (Builder $query, string $operator, CarbonInterface $date) => $query->where(fn (Builder $query) => $query
            ->where(fn (Builder $query) => $query->where('status', JoiningStatus::Joined->value)->whereNotNull('actual_doj')->whereDate('actual_doj', $operator, $date->toDateString()))
            ->orWhere(fn (Builder $query) => $query->where(fn (Builder $query) => $query->where('status', '!=', JoiningStatus::Joined->value)->orWhereNull('actual_doj'))->where('updated_at', $operator, $date)));

        return CandidateJoining::query()
            ->whereIn('status', $statuses)
            ->when($since !== null, fn (Builder $query) => $dated($query, '>=', $since))
            ->when($until !== null, fn (Builder $query) => $dated($query, '<=', $until))
            ->when($requisitionId !== null, fn (Builder $query) => $query->whereIn('candidate_application_id', CandidateApplication::query()->where('requisition_id', $requisitionId)->select('id')));
    }

    /**
     * @return Builder<HiringOutcomeSnapshot>
     */
    public function snapshotsWithoutProcessOutcomes(?int $requisitionId = null): Builder
    {
        return HiringOutcomeSnapshot::query()->when($requisitionId !== null, fn (Builder $query) => $query->where('requisition_id', $requisitionId))->whereNotExists(fn (QueryBuilder $q) => $q->from('hiring_outcomes')
            ->whereColumn('hiring_outcomes.hiring_outcome_snapshot_id', 'hiring_outcome_snapshots.id')
            ->where('hiring_outcomes.outcome_type', OutcomeType::TimeToHire->value)
            ->where('hiring_outcomes.is_current', true));
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     */
    /**
     * The records that failed in the last evaluate() pass — each left unrecorded, so the next pass
     * retries it.
     *
     * @return array<int, array{step: string, record: string, exception: string, message: string}>
     */
    public function failures(): array
    {
        return $this->failures;
    }

    /**
     * Phase 8.3: one transaction per chunk (not per record — far fewer durable commits), with each
     * record in its own savepoint: a failing record is rolled back alone, logged with its id and
     * counted, and the pass carries on. Nothing is marked evaluated for it, so it is retried.
     */
    private function each(string $step, Builder $query, int $batch, callable $callback): int
    {
        $count = 0;

        $query->chunkById($batch, function ($models) use ($step, $callback, &$count): void {
            DB::transaction(function () use ($models, $step, $callback, &$count): void {
                foreach ($models as $model) {
                    try {
                        DB::transaction(fn () => $callback($model));
                        $count++;
                    } catch (Throwable $e) {
                        $failure = ['step' => $step, 'record' => class_basename($model).' #'.$model->getKey(), 'exception' => $e::class, 'message' => mb_substr($e->getMessage(), 0, 300)];
                        $this->failures[] = $failure;
                        Log::warning('Outcome evaluation failed for a record; it will be retried on the next pass.', $failure);
                    }
                }
            });
        });

        return $count;
    }
}
