<?php

namespace App\Services\Metrics\Definitions;

use App\Enums\MetricCategory;
use App\Enums\MetricKind;
use App\Enums\MetricMaterialization;
use App\Enums\MetricScopeModel;
use App\Enums\MetricUnit;
use App\Services\Metrics\Concerns\QueriesInterviewTurnUp;
use App\Services\Metrics\MetricDefinition;
use App\Services\Metrics\MetricQuery;
use App\Services\Metrics\MetricResult;
use App\Services\Metrics\MetricSpec;

/**
 * interview.no_show_rate (D41, §17): interview attendance over interviews whose outcome is recorded.
 */
class InterviewNoShowRate extends MetricDefinition
{
    use QueriesInterviewTurnUp;

    public function spec(): MetricSpec
    {
        return new MetricSpec(
            key: 'interview.no_show_rate',
            version: 1,
            effectiveFrom: '2026-09-27',
            name: 'Interview No-show Rate',
            description: 'Share of past interviews in the period the candidate missed: no-show ÷ (completed + no-show).',
            category: MetricCategory::Quality,
            kind: MetricKind::Quality,
            purpose: 'How often a scheduled candidate does not attend.',
            population: 'Interviews scheduled in the period, completed or marked no-show.',
            numerator: 'No-show interviews.',
            denominator: 'Completed + no-show interviews.',
            statistic: 'rate',
            anchor: 'interviews.scheduled_at',
            endEvent: 'The interview status (Completed / No-show).',
            dateSemantics: 'scheduled_at within the period in the business timezone.',
            scope: MetricScopeModel::ApplicationOwner,
            attribution: 'Current owner of the application.',
            filters: ['department_id', 'designation_id', 'location_id', 'requisition_id', 'source_id', 'recruiter_id'],
            exclusions: 'Cancelled, rescheduled and on-hold interviews did not take place and are excluded (counted); interviews still ahead are not line-ups yet; deleted applications.',
            unknownRule: 'Past interviews nobody updated (still pending, scheduled or confirmed) are unknown — never counted as a no-show.',
            unobservedRule: 'Upcoming interviews are not yet observed.',
            invalidRule: 'Not applicable.',
            unit: MetricUnit::Percent,
            source: 'interviews',
            materialization: MetricMaterialization::Cached,
            reproducibility: 'Reproducible for a given as_of.',
            supersedes: 'RecruitmentAnalyticsService::turnUpAnalysis turnup_percent (completed ÷ all interviews, incl. future and cancelled)',
        );
    }

    protected function evaluate(MetricQuery $query): MetricResult
    {
        $counts = $this->interviewCounts($query);

        return $this->result($query, $this->rate($counts['no_show'], $counts['decided']), $counts['decided'], $counts, unknown: $counts['not_updated'], excluded: $counts['cancelled'] + $counts['rescheduled'] + $counts['on_hold']);
    }
}
