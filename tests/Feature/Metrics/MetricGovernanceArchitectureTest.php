<?php

use App\Enums\OfferStatus;
use App\Enums\TargetMetric;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\Offer;
use App\Services\Metrics\MetricPeriod;
use App\Services\RecruiterDailyMetricsService;
use Carbon\CarbonImmutable;

/**
 * Phase 8.7 (D8.7-025 a): the guard MetricRegistry has long cited. Headline numbers come from
 * governed metrics (MetricService); a few older raw counters remain, and this file is their
 * inventory. Using one of them from a new place fails here — so it is reviewed, not drifted into.
 * The raw counters' definitions are pinned too: recruiter daily metrics feed performance and
 * incentive actuals, and their meaning changes only by an approved, versioned product decision,
 * never silently (stop conditions 11 and 14).
 */
function ungovernedKpiConsumers(string ...$symbols): array
{
    $root = dirname(__DIR__, 3);
    $found = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/app')) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
            if (is_array($token) && in_array($token[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)
                && collect($symbols)->contains(fn (string $symbol) => str_ends_with($token[1], $symbol))) {
                $found[] = str_replace($root.'/', '', $file->getPathname());
            }
        }
    }

    return collect($found)->unique()->sort()->values()->all();
}

test('recruiter daily metrics (raw counts feeding performance and incentives) are read only by the reviewed consumers', function (): void {
    expect(ungovernedKpiConsumers('RecruiterDailyMetricsService'))->toBe([
        'app/Filament/Pages/Leaderboard.php',
        'app/Filament/Widgets/TodaysRecruitmentPulse.php',
        'app/Services/AI/Tools/RecruiterTools/GetRecruiterPerformanceTool.php',
        'app/Services/Metrics/Definitions/RecruiterActivity.php',
        'app/Services/PerformanceEngine.php',
        'app/Services/RecruiterDailyMetricsService.php',
        'app/Services/RecruiterIncentiveCalculator.php',
        'app/Services/RecruitmentInsightsService.php',
    ]);
});

test('raw vacancy-ageing and SLA-breach sweeps are used only where already inventoried', function (): void {
    expect(ungovernedKpiConsumers('vacancyAgeing'))->toBe([
        'app/Console/Commands/DispatchRecruitmentAlerts.php',
        'app/Filament/Pages/RecruitmentReports.php',
        'app/Services/AI/Tools/JobTools/FindAtRiskRequisitionsTool.php',
        'app/Services/RecruitmentActionCenterService.php',
        'app/Services/RecruitmentAnalyticsService.php',
    ])->and(ungovernedKpiConsumers('openBreaches', 'openPipelineStageBreaches', 'breachFor'))->toBe([
        'app/Console/Commands/DispatchRecruitmentAlerts.php',
        'app/Services/Automation/AutomationFieldRegistry.php',
        'app/Services/RecruitmentSlaService.php',
    ]);
});

test('the incentive "offers" input still counts offers by offer date — changing it needs an approved, versioned decision', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-24 12:00:00', 'Asia/Kolkata'));
    $recruiter = Employee::factory()->create();
    $application = CandidateApplication::factory()->create(['recruiter_id' => $recruiter->id]);
    Offer::factory()->create(['candidate_application_id' => $application->id, 'offer_date' => now(), 'status' => OfferStatus::Draft]);
    $releasedToday = Offer::factory()->create(['candidate_application_id' => CandidateApplication::factory()->create(['recruiter_id' => $recruiter->id])->id, 'offer_date' => now()->subDays(10), 'status' => OfferStatus::Released]);
    $releasedToday->statusHistory()->create(['from_status' => OfferStatus::Initiated, 'to_status' => OfferStatus::Released]);

    $actuals = app(RecruiterDailyMetricsService::class)->actualsFor([$recruiter->id], TargetMetric::Offers, MetricPeriod::day(now()));

    expect($actuals)->toBe([$recruiter->id => 1]);
});
