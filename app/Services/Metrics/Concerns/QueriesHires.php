<?php

namespace App\Services\Metrics\Concerns;

use App\Enums\JoiningStatus;
use App\Models\CandidateJoining;
use App\Services\Metrics\MetricQuery;
use Illuminate\Database\Eloquent\Builder;

/**
 * The shared definition of a hire (Phase 8.5 D34): a joining record marked Joined, dated by its
 * actual joining date. The hire's source is the source frozen in its Outcome Loop snapshot, or the
 * candidate's current source for a hire captured before snapshots existed (D6).
 */
trait QueriesHires
{
    /**
     * Joined joining records with an actual joining date in the period, scoped through their
     * application (owner scope) and filtered — the source filter uses the hire's frozen source.
     *
     * @return Builder<CandidateJoining>
     */
    protected function hires(MetricQuery $query): Builder
    {
        $hires = CandidateJoining::query()->where('candidate_joinings.status', JoiningStatus::Joined->value);
        $query->requirePeriod()->whereDateColumn($hires, 'candidate_joinings.actual_doj');

        return $this->filteredJoinings($hires, $query);
    }

    /**
     * @param  Builder<CandidateJoining>  $joinings
     * @return Builder<CandidateJoining>
     */
    protected function filteredJoinings(Builder $joinings, MetricQuery $query): Builder
    {
        $this->scope->throughApplication($joinings, $query->without('source_id'));

        if (($sourceId = $query->filter('source_id')) !== null) {
            $joinings->where(fn (Builder $q) => $q
                ->whereHas('outcomeSnapshot', fn (Builder $s) => $s->where('source_id', $sourceId))
                ->orWhere(fn (Builder $live) => $live->whereDoesntHave('outcomeSnapshot')->whereHas('candidateApplication.candidate', fn (Builder $c) => $c->where('source_id', $sourceId))));
        }

        return $joinings;
    }
}
