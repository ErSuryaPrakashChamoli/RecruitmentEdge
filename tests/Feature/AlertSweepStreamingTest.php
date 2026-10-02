<?php

use App\Enums\CandidateStage;
use App\Models\CandidateApplication;
use App\Models\RecruitmentSetting;
use App\Services\RecruitmentSlaService;
use Illuminate\Support\Facades\DB;

/**
 * Phase 8.9 (P89-PERF-004): the hourly alert sweep streams SLA breaches a page at a time instead of
 * holding every breach of the organisation in memory (585 MB at 100k). Paging must hand over exactly
 * the breaches the full list holds — each once — and never bind an unbounded id list.
 */
function alertSweepBreachingApplications(int $count): array
{
    return collect(range(1, $count))->map(function (): int {
        $application = CandidateApplication::factory()->create(['current_stage' => CandidateStage::Selected]);
        $application->stageHistory()->forceCreate(['previous_stage' => null, 'new_stage' => CandidateStage::Selected, 'created_at' => now()->subDays(9)]);

        return $application->id;
    })->all();
}

test('streamed breaches are exactly the listed breaches, each once, across pages', function (): void {
    RecruitmentSetting::put('sla_days_selection_to_offer', '3', 'int');
    $ids = alertSweepBreachingApplications(7);
    $service = app(RecruitmentSlaService::class);

    $streamed = [];
    $handed = $service->eachOpenBreach(function (array $breach) use (&$streamed): void {
        $streamed[] = $breach['application']->id.'|'.$breach['leg_label'];
    }, chunk: 2);

    $listed = $service->openBreaches()->map(fn (array $breach) => $breach['application']->id.'|'.$breach['leg_label'])->all();

    expect($handed)->toBe(count($streamed))
        ->and($streamed)->toHaveCount(count(array_unique($streamed)))
        ->and(collect($streamed)->sort()->values()->all())->toBe(collect($listed)->sort()->values()->all())
        ->and(collect($streamed)->map(fn (string $key) => (int) explode('|', $key)[0])->unique()->sort()->values()->all())->toBe($ids);
});

test('the alert sweep never binds more than one page of ids', function (): void {
    RecruitmentSetting::put('sla_days_selection_to_offer', '3', 'int');
    alertSweepBreachingApplications(4);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $this->artisan('notifications:dispatch-alerts')->assertSuccessful();
    $maxBindings = collect(DB::getQueryLog())->max(fn (array $q): int => count($q['bindings']));
    DB::disableQueryLog();

    expect($maxBindings)->toBeLessThanOrEqual(500);
});
