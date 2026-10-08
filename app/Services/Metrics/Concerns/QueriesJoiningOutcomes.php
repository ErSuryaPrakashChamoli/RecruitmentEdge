<?php

namespace App\Services\Metrics\Concerns;

use App\Enums\JoiningStatus;
use App\Models\CandidateJoining;
use App\Services\Metrics\MetricQuery;
use Illuminate\Database\Eloquent\Builder;

/**
 * The shared population of joining outcomes (Phase 8.5 D5 — the Outcome Loop definition): joining
 * records whose final status was set in the period. A join is dated by its actual joining date; a
 * no-show, dropout or cancellation by when the record last changed (the date the Outcome Loop
 * records it; see P85-BACKLOG-003).
 */
trait QueriesJoiningOutcomes
{
    use QueriesHires;

    /**
     * @return array{joined: int, no_show: int, dropout: int, cancelled: int, decided: int, pending: int}
     */
    protected function joiningOutcomeCounts(MetricQuery $query): array
    {
        $period = $query->requirePeriod();
        $joined = $this->hires($query)->count();

        $closed = $this->filteredJoinings(CandidateJoining::query(), $query)
            ->whereIn('candidate_joinings.status', [JoiningStatus::NoShow->value, JoiningStatus::Dropout->value, JoiningStatus::Cancelled->value])
            ->tap(fn (Builder $q) => $period->whereTimestampColumn($q, 'candidate_joinings.updated_at'))
            ->selectRaw('candidate_joinings.status, count(*) as total')
            ->groupBy('candidate_joinings.status')
            ->pluck('total', 'status');

        $noShow = (int) ($closed[JoiningStatus::NoShow->value] ?? 0);
        $dropout = (int) ($closed[JoiningStatus::Dropout->value] ?? 0);

        $pending = $this->filteredJoinings(CandidateJoining::query(), $query)
            ->whereIn('candidate_joinings.status', [JoiningStatus::Expected->value, JoiningStatus::Confirmed->value])
            ->tap(fn (Builder $q) => $q->where('candidate_joinings.expected_doj', '<', $period->afterDate()))
            ->count();

        return [
            'joined' => $joined,
            'no_show' => $noShow,
            'dropout' => $dropout,
            'cancelled' => (int) ($closed[JoiningStatus::Cancelled->value] ?? 0),
            'decided' => $joined + $noShow + $dropout,
            'pending' => $pending,
        ];
    }
}
