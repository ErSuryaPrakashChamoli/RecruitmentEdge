<?php

use App\Services\Metrics\MetricDefinition;
use App\Services\Metrics\MetricRegistry;

/**
 * Phase 8.5 architecture guards for governed metrics:
 * - every definition class is registered (an unregistered definition is an ungoverned number);
 * - metric code never compares a period with a string BETWEEN (the SQLite last-day defect);
 * - metric code reads stage history only through its entry scopes (DF-9);
 * - recruiter activity is created only by RecruitmentActivityService (SEC-4).
 *
 * @return array<string, string> relative path => source
 */
function metricArchitectureSources(string ...$globs): array
{
    $root = dirname(__DIR__, 3);

    return collect($globs)
        ->flatMap(fn (string $glob) => glob($root.'/'.$glob) ?: [])
        ->mapWithKeys(fn (string $file) => [str_replace($root.'/', '', $file) => (string) file_get_contents($file)])
        ->all();
}

/**
 * @return array<string, string>
 */
function governedMetricSources(): array
{
    return metricArchitectureSources(
        'app/Services/Metrics/*.php',
        'app/Services/Metrics/*/*.php',
        'app/Services/RecruitmentAnalyticsService.php',
        'app/Services/RecruitmentSlaService.php',
        'app/Services/RecruiterDailyMetricsService.php',
        'app/Services/CostPerHireService.php',
        'app/Services/Outcomes/OutcomeAnalyticsService.php',
    );
}

test('every metric definition class is registered', function (): void {
    $classes = collect(metricArchitectureSources('app/Services/Metrics/Definitions/*.php'))
        ->keys()
        ->map(fn (string $path) => 'App\\Services\\Metrics\\Definitions\\'.basename($path, '.php'))
        ->filter(fn (string $class) => ! (new ReflectionClass($class))->isAbstract() && is_subclass_of($class, MetricDefinition::class))
        ->values();

    expect($classes->diff(MetricRegistry::DEFINITIONS)->all())->toBe([]);
});

test('governed metric code never filters a period with whereBetween', function (): void {
    foreach (governedMetricSources() as $path => $source) {
        expect(str_contains($source, 'whereBetween('))->toBeFalse("{$path} uses whereBetween — use MetricPeriod::whereDateColumn()/whereTimestampColumn()");
    }
});

test('governed metric code reads stage history only through its stage-entry scopes', function (): void {
    foreach (governedMetricSources() as $path => $source) {
        preg_match_all('/CandidateStageHistory::query\(\)(?!->(milestoneEntries|pipelineStageEntries)\(\))/', $source, $matches);

        expect($matches[0])->toBe([], "{$path} reads candidate_stage_histories without milestoneEntries()/pipelineStageEntries()");
    }
});

test('only RecruitmentActivityService creates recruiter activity', function (): void {
    $sources = metricArchitectureSources('app/*.php', 'app/*/*.php', 'app/*/*/*.php', 'app/*/*/*/*.php', 'app/*/*/*/*/*.php');

    foreach ($sources as $path => $source) {
        if ($path === 'app/Services/RecruitmentActivityService.php') {
            continue;
        }

        expect(preg_match('/RecruitmentDailyActivity::(query\(\)->)?(create|forceCreate|insert|updateOrCreate|firstOrCreate)\(|dailyActivities\(\)->create\(/', $source, $match))
            ->toBe(0, "{$path} creates recruiter activity outside RecruitmentActivityService: ".($match[0] ?? ''));
    }
});
