<?php

namespace App\Services\Metrics\Definitions;

use App\Enums\MetricCategory;
use App\Enums\MetricKind;
use App\Enums\MetricMaterialization;
use App\Enums\MetricScopeModel;
use App\Enums\MetricUnit;
use App\Services\Metrics\Concerns\QueriesOffers;
use App\Services\Metrics\MetricDefinition;
use App\Services\Metrics\MetricQuery;
use App\Services\Metrics\MetricResult;
use App\Services\Metrics\MetricSpec;

/**
 * offer.decided_acceptance_rate (D4).
 */
class OfferDecidedAcceptanceRate extends MetricDefinition
{
    use QueriesOffers;

    public function spec(): MetricSpec
    {
        return new MetricSpec(
            key: 'offer.decided_acceptance_rate',
            version: 1,
            effectiveFrom: '2026-09-27',
            name: 'Offer Acceptance (Accepted vs Declined)',
            description: 'Of the offers first released in the period, accepted ÷ (accepted + rejected). Secondary to offer.acceptance_rate: expiry and withdrawal are left out.',
            category: MetricCategory::Offer,
            kind: MetricKind::BusinessOutcome,
            purpose: 'How often candidates say yes to an offer.',
            population: 'Offers whose first Released status (offer status history) falls in the period.',
            numerator: 'Accepted offers.',
            denominator: 'Accepted + rejected offers.',
            statistic: 'rate',
            anchor: 'First offer_status_histories row to Released.',
            endEvent: 'The offer\'s current status (its decision as of now).',
            dateSemantics: 'First release instant within the period in the business timezone.',
            scope: MetricScopeModel::ApplicationOwner,
            attribution: 'Current owner of the application.',
            filters: ['department_id', 'designation_id', 'location_id', 'requisition_id', 'source_id', 'recruiter_id'],
            exclusions: 'Expired and withdrawn offers are excluded and counted. Deleted applications.',
            unknownRule: 'Released offers still awaiting a decision are reported as awaiting (unknown) and left out of the rate.',
            unobservedRule: 'A recent period changes as pending offers are decided (as_of = computed_at).',
            invalidRule: 'Not applicable.',
            unit: MetricUnit::Percent,
            source: 'offers, offer_status_histories',
            materialization: MetricMaterialization::Cached,
            reproducibility: 'Reproducible for a given as_of from the offer status history.',
            supersedes: 'Copilot analyze_offers acceptance',
        );
    }

    protected function evaluate(MetricQuery $query): MetricResult
    {
        $counts = $this->releasedOfferCounts($query);
        ['accepted' => $accepted, 'rejected' => $rejected] = $counts;
        $decided = $accepted + $rejected;

        return $this->result($query, $this->rate($accepted, $decided), $decided, $counts, unknown: $counts['awaiting'], excluded: $counts['released'] - $decided - $counts['awaiting']);
    }
}
