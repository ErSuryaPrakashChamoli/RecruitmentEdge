<?php

namespace App\Services\Metrics\Concerns;

use App\Enums\InterviewStatus;
use App\Models\Interview;
use App\Services\Metrics\MetricPeriod;
use App\Services\Metrics\MetricQuery;
use Illuminate\Database\Eloquent\Builder;

/**
 * The shared interview line-up population (Phase 8.5 §17 zero/unknown fix): interviews scheduled
 * in the period that are already in the past. A future interview is not a line-up yet, and a past
 * one nobody updated is unknown — never "did not turn up".
 */
trait QueriesInterviewTurnUp
{
    /**
     * @return array{completed: int, no_show: int, decided: int, cancelled: int, on_hold: int, rescheduled: int, not_updated: int, upcoming: int}
     */
    protected function interviewCounts(MetricQuery $query): array
    {
        $period = $query->requirePeriod();
        $now = MetricPeriod::now()->setTimezone((string) config('app.timezone'))->format('Y-m-d H:i:s');

        $counts = $this->scope->throughApplication(Interview::query(), $query)
            ->tap(fn (Builder $q) => $period->whereTimestampColumn($q, 'interviews.scheduled_at'))
            ->selectRaw('status, case when scheduled_at <= ? then 1 else 0 end as is_past, count(*) as total', [$now])
            ->groupBy('status', 'is_past')
            ->get();

        $count = fn (bool $past, InterviewStatus ...$statuses): int => (int) $counts
            ->filter(fn ($row) => (bool) $row->is_past === $past && in_array($row->status, $statuses, true))
            ->sum('total');

        $completed = $count(true, InterviewStatus::Completed) + $count(false, InterviewStatus::Completed);
        $noShow = $count(true, InterviewStatus::NoShow) + $count(false, InterviewStatus::NoShow);

        return [
            'completed' => $completed,
            'no_show' => $noShow,
            'decided' => $completed + $noShow,
            'cancelled' => $count(true, InterviewStatus::Cancelled) + $count(false, InterviewStatus::Cancelled),
            'on_hold' => $count(true, InterviewStatus::Hold) + $count(false, InterviewStatus::Hold),
            'rescheduled' => $count(true, InterviewStatus::Rescheduled) + $count(false, InterviewStatus::Rescheduled),
            'not_updated' => $count(true, InterviewStatus::Pending, InterviewStatus::Scheduled, InterviewStatus::Confirmed),
            'upcoming' => $count(false, InterviewStatus::Pending, InterviewStatus::Scheduled, InterviewStatus::Confirmed),
        ];
    }
}
