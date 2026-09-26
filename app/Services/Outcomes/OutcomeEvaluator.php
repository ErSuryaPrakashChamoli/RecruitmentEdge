<?php

namespace App\Services\Outcomes;

use App\Enums\JoiningStatus;
use App\Enums\OfferStatus;
use App\Enums\OutcomeCategory;
use App\Enums\OutcomeType;
use App\Models\CandidateJoining;
use App\Models\HiringOutcomeSnapshot;
use App\Models\Offer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

/**
 * Outcome Loop™ (Phase 8.2): the single evaluation pass behind `outcomes:evaluate`. Events record
 * outcomes as things happen; this pass catches up on anything missed, using indexed "not yet
 * recorded" queries in chunks — it never loads every employee or candidate. Idempotent: a second
 * run records nothing new.
 */
class OutcomeEvaluator
{
    public function __construct(private readonly OutcomeCalculator $calculator) {}

    /**
     * @return array<string, int> what was (or, in a dry run, would be) evaluated
     */
    public function evaluate(bool $dryRun = false): array
    {
        $batch = max(1, (int) config('outcomes.batch_size', 200));
        $counts = [];

        $joinings = $this->pendingJoinings();
        $counts['joining_outcomes'] = $dryRun ? $joinings->count() : $this->each($joinings, $batch, fn (CandidateJoining $joining) => $this->calculator->joining($joining));

        $offers = $this->pendingOffers();
        $counts['offers'] = $dryRun ? $offers->count() : $this->each($offers, $batch, fn (Offer $offer) => $this->calculator->offer($offer));

        $snapshots = $this->snapshotsWithoutProcessOutcomes();
        $counts['process_outcomes'] = $dryRun ? $snapshots->count() : $this->each($snapshots, $batch, fn (HiringOutcomeSnapshot $snapshot) => $this->calculator->process($snapshot));

        $counts['status_observations'] = 0;

        foreach ($this->observationTypes() as $type) {
            $due = $this->dueStatusObservations($type);
            $counts['status_observations'] += $dryRun ? $due->count() : $this->each($due, $batch, fn (HiringOutcomeSnapshot $snapshot) => $this->calculator->statusObservation($snapshot, $type));
        }

        // Separation records can arrive after a status was observed: re-check those employees.
        $separated = HiringOutcomeSnapshot::query()->whereHas('employee.separation');
        $counts['separation_rechecks'] = $dryRun ? $separated->count() : $this->each($separated, $batch, fn (HiringOutcomeSnapshot $snapshot) => $this->observeAll($snapshot));

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
     * @return Builder<CandidateJoining>
     */
    public function pendingJoinings(): Builder
    {
        return CandidateJoining::query()
            ->whereIn('status', [JoiningStatus::Joined, JoiningStatus::NoShow, JoiningStatus::Dropout])
            ->whereNotExists(fn (QueryBuilder $q) => $q->from('hiring_outcomes')
                ->whereColumn('hiring_outcomes.candidate_joining_id', 'candidate_joinings.id')
                ->where('hiring_outcomes.category', OutcomeCategory::Joining->value)
                ->where('hiring_outcomes.is_current', true));
    }

    /**
     * Offers with a status-history entry that has no outcome yet.
     *
     * @return Builder<Offer>
     */
    public function pendingOffers(): Builder
    {
        return Offer::query()->where(function (Builder $query): void {
            foreach ([OfferStatus::Released, OfferStatus::Accepted, OfferStatus::Rejected, OfferStatus::Expired, OfferStatus::Withdrawn] as $status) {
                $query->orWhereExists(fn (QueryBuilder $q) => $q->from('offer_status_histories')
                    ->whereColumn('offer_status_histories.offer_id', 'offers.id')
                    ->where('offer_status_histories.to_status', $status->value)
                    ->whereNotExists(fn (QueryBuilder $o) => $o->from('hiring_outcomes')
                        ->whereColumn('hiring_outcomes.offer_id', 'offers.id')
                        ->where('hiring_outcomes.outcome_type', OutcomeType::forOfferStatus($status)->value)
                        ->where('hiring_outcomes.is_current', true)));
            }
        });
    }

    /**
     * @return Builder<HiringOutcomeSnapshot>
     */
    public function snapshotsWithoutProcessOutcomes(): Builder
    {
        return HiringOutcomeSnapshot::query()->whereNotExists(fn (QueryBuilder $q) => $q->from('hiring_outcomes')
            ->whereColumn('hiring_outcomes.hiring_outcome_snapshot_id', 'hiring_outcome_snapshots.id')
            ->where('hiring_outcomes.outcome_type', OutcomeType::TimeToHire->value)
            ->where('hiring_outcomes.is_current', true));
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<TModel>  $query
     */
    private function each(Builder $query, int $batch, callable $callback): int
    {
        $count = 0;

        $query->chunkById($batch, function ($models) use ($callback, &$count): void {
            foreach ($models as $model) {
                $callback($model);
                $count++;
            }
        });

        return $count;
    }
}
