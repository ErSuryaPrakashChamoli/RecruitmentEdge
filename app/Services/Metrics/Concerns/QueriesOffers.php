<?php

namespace App\Services\Metrics\Concerns;

use App\Enums\OfferStatus;
use App\Models\Offer;
use App\Services\Metrics\MetricQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The shared offer population (Phase 8.5 D4): offers first released in the period, from the
 * immutable offer status history, with their decision as it stands now.
 */
trait QueriesOffers
{
    /**
     * Current status counts of the offers first released in the period.
     *
     * @return array{released: int, accepted: int, rejected: int, expired: int, withdrawn: int, awaiting: int}
     */
    protected function releasedOfferCounts(MetricQuery $query): array
    {
        $period = $query->requirePeriod();

        $firstReleased = DB::table('offer_status_histories')
            ->where('to_status', OfferStatus::Released->value)
            ->groupBy('offer_id')
            ->havingRaw('min(created_at) >= ? and min(created_at) < ?', [$period->startInstant()->format('Y-m-d H:i:s'), $period->endInstant()->format('Y-m-d H:i:s')])
            ->select('offer_id');

        $counts = $this->scope->throughApplication(Offer::query(), $query)
            ->whereIn('offers.id', $firstReleased)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status')
            ->map(fn ($total) => (int) $total);

        $count = fn (OfferStatus $status): int => $counts->get($status->value, 0);

        return [
            'released' => (int) $counts->sum(),
            'accepted' => $count(OfferStatus::Accepted),
            'rejected' => $count(OfferStatus::Rejected),
            'expired' => $count(OfferStatus::Expired),
            'withdrawn' => $count(OfferStatus::Withdrawn),
            'awaiting' => $count(OfferStatus::Released) + $count(OfferStatus::Initiated) + $count(OfferStatus::Draft),
        ];
    }

    /**
     * @return Builder<Offer>
     */
    protected function offers(MetricQuery $query): Builder
    {
        return $this->scope->throughApplication(Offer::query(), $query);
    }
}
