<?php

namespace App\Services\Metrics\Definitions;

use App\Enums\JoiningStatus;
use App\Enums\MetricCategory;
use App\Enums\MetricKind;
use App\Enums\MetricMaterialization;
use App\Enums\MetricScopeModel;
use App\Enums\MetricUnit;
use App\Enums\OfferStatus;
use App\Models\CandidateJoining;
use App\Services\Metrics\Concerns\QueriesOffers;
use App\Services\Metrics\MetricDefinition;
use App\Services\Metrics\MetricQuery;
use App\Services\Metrics\MetricResult;
use App\Services\Metrics\MetricSpec;
use Illuminate\Support\Facades\DB;

/**
 * joining.offer_to_join (D5, DF-4): of the applications whose offer was accepted in the period, how
 * many have joined — linked by application, never by offer id.
 */
class OfferToJoin extends MetricDefinition
{
    use QueriesOffers;

    public function spec(): MetricSpec
    {
        return new MetricSpec(
            key: 'joining.offer_to_join',
            version: 1,
            effectiveFrom: '2026-09-27',
            name: 'Offer Accepted → Joined',
            description: 'Of the applications with an offer accepted in the period, the share that has joined, among those whose joining is decided.',
            category: MetricCategory::Joining,
            kind: MetricKind::BusinessOutcome,
            purpose: 'How reliably an accepted offer turns into a join.',
            population: 'Distinct applications with an offer accepted (accepted_at) in the period.',
            numerator: 'Those applications whose joining record is Joined.',
            denominator: 'Those applications whose joining record is Joined, No-show or Dropout.',
            statistic: 'rate',
            anchor: 'offers.accepted_at',
            endEvent: 'The application\'s joining record status as of now.',
            dateSemantics: 'accepted_at within the period in the business timezone.',
            scope: MetricScopeModel::ApplicationOwner,
            attribution: 'Current owner of the application.',
            filters: ['department_id', 'designation_id', 'location_id', 'requisition_id', 'source_id', 'recruiter_id'],
            exclusions: 'Cancelled joinings are excluded and counted; deleted applications.',
            unknownRule: 'Applications whose joining is still expected or confirmed, or has no joining record, are awaiting — reported and left out of the rate.',
            unobservedRule: 'A recent cohort changes as joinings are decided (as_of = computed_at).',
            invalidRule: 'Not applicable.',
            unit: MetricUnit::Percent,
            source: 'offers, candidate_joinings',
            materialization: MetricMaterialization::Cached,
            reproducibility: 'Reproducible for a given as_of.',
            supersedes: 'joiningAnalytics offer_to_join_percent (per offer row); Copilot analyze_joining_conversion (linked by offer_id)',
        );
    }

    protected function evaluate(MetricQuery $query): MetricResult
    {
        $acceptedApplications = $this->offers($query)
            ->where('offers.status', OfferStatus::Accepted->value)
            ->tap(fn ($q) => $query->requirePeriod()->whereTimestampColumn($q, 'offers.accepted_at'))
            ->select('offers.candidate_application_id')
            ->distinct()
            ->toBase();

        // Phase 8.9 (P89-PERF-027): counted in the database through the subquery — never a list of
        // ids bound as parameters, which MySQL refuses above 65,535. One joining per application, so
        // the status counts equal the per-application statuses the definition reads.
        $accepted = DB::query()->fromSub($acceptedApplications, 'accepted_applications')->count();
        $statuses = CandidateJoining::query()
            ->whereIn('candidate_application_id', $acceptedApplications)
            ->toBase()
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');
        $count = fn (JoiningStatus ...$s): int => (int) collect($s)->sum(fn (JoiningStatus $status): int => (int) ($statuses[$status->value] ?? 0));

        $joined = $count(JoiningStatus::Joined);
        $decided = $count(JoiningStatus::Joined, JoiningStatus::NoShow, JoiningStatus::Dropout);
        $cancelled = $count(JoiningStatus::Cancelled);

        return $this->result($query, $this->rate($joined, $decided), $decided, [
            'accepted_applications' => $accepted,
            'joined' => $joined,
            'not_joined' => $decided - $joined,
            'awaiting' => $accepted - $decided - $cancelled,
            'cancelled' => $cancelled,
        ], unknown: $accepted - $decided - $cancelled, excluded: $cancelled);
    }
}
