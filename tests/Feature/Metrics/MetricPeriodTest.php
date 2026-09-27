<?php

use App\Enums\JoiningStatus;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\CandidateStageHistory;
use App\Services\AI\Tools\Concerns\ResolvesMetricPeriod;
use App\Services\Metrics\MetricPeriod;
use Carbon\CarbonImmutable;

/**
 * Phase 8.5 (D16, D17, DF-5): one period primitive — business-timezone calendar days, inclusive,
 * applied the same way on every database driver.
 */
beforeEach(function (): void {
    config(['metrics.business_timezone' => 'Asia/Kolkata']);
});

test('a date column includes the last day of the period on every driver', function (): void {
    // Date casts are stored as "Y-m-d 00:00:00" on SQLite; a string BETWEEN used to drop the last day.
    $joining = lifecycleFixture(fn () => CandidateJoining::factory()->create(['status' => JoiningStatus::Joined, 'actual_doj' => '2026-09-30']));

    $inside = MetricPeriod::dates('2026-09-01', '2026-09-30')->whereDateColumn(CandidateJoining::query(), 'actual_doj')->pluck('id');
    $after = MetricPeriod::dates('2026-10-01', '2026-10-31')->whereDateColumn(CandidateJoining::query(), 'actual_doj')->pluck('id');

    expect($inside->all())->toBe([$joining->id])
        ->and($after->all())->toBe([]);
});

test('a timestamp belongs to the business-timezone day it happened on', function (): void {
    $application = CandidateApplication::factory()->create();
    // 20:00 UTC on 30 September is 01:30 on 1 October in India.
    $row = $application->stageHistory()->forceCreate(['previous_stage' => null, 'new_stage' => 'screened', 'created_at' => '2026-09-30 20:00:00']);

    $september = MetricPeriod::dates('2026-09-01', '2026-09-30')->whereTimestampColumn(CandidateStageHistory::query(), 'created_at')->pluck('id');
    $october = MetricPeriod::dates('2026-10-01', '2026-10-31')->whereTimestampColumn(CandidateStageHistory::query(), 'created_at')->pluck('id');

    expect($september->all())->toBe([])
        ->and($october->all())->toBe([$row->id]);
});

test('calendar-day boundaries name their own date and other instants take the business date', function (): void {
    $month = MetricPeriod::between(CarbonImmutable::parse('2026-09-01 00:00:00', 'UTC'), CarbonImmutable::parse('2026-09-30 23:59:59', 'UTC'));
    $midnightEnd = MetricPeriod::between(CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-10'));
    $lateEvening = MetricPeriod::between(CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-10 20:00:00', 'UTC'));

    expect([$month->fromDate(), $month->toDate()])->toBe(['2026-09-01', '2026-09-30'])
        ->and($midnightEnd->toDate())->toBe('2026-09-10')
        ->and($lateEvening->toDate())->toBe('2026-09-11');
});

test('a Copilot end date covers that whole day (DF-5)', function (): void {
    $tool = new class
    {
        use ResolvesMetricPeriod;

        public function period(array $arguments): MetricPeriod
        {
            return $this->metricPeriod($arguments, 90);
        }
    };

    $period = $tool->period(['start_date' => '2026-09-01', 'end_date' => '2026-09-10']);

    expect($period->toDate())->toBe('2026-09-10')
        ->and($period->endInstant()->toDateTimeString())->toBe('2026-09-10 18:30:00')
        ->and($tool->period(['start_date' => '2026-09-10', 'end_date' => '2026-09-01'])->fromDate())->toBe('2026-09-01');
});

test('weeks start on Monday and quarters and years are calendar periods', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-24 10:00:00', 'Asia/Kolkata'));

    expect(MetricPeriod::preset('this_week')->fromDate())->toBe('2026-09-21')
        ->and(MetricPeriod::preset('this_week')->toDate())->toBe('2026-09-27')
        ->and([MetricPeriod::preset('this_quarter')->fromDate(), MetricPeriod::preset('this_quarter')->toDate()])->toBe(['2026-07-01', '2026-09-30'])
        ->and(MetricPeriod::preset('last_quarter')->fromDate())->toBe('2026-04-01')
        ->and(MetricPeriod::preset('this_year')->toDate())->toBe('2026-12-31')
        ->and(MetricPeriod::lastDays(7)->fromDate())->toBe('2026-09-18')
        ->and(MetricPeriod::lastDays(7)->previous()->toDate())->toBe('2026-09-17');
});

test('today is the business-timezone date, not the UTC date', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-24 20:00:00', 'UTC'));

    expect(MetricPeriod::day()->fromDate())->toBe('2026-09-25');
});
