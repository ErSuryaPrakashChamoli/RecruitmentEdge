<?php

use App\Enums\HiringRiskStatus;
use App\Enums\HiringRiskType;
use App\Enums\RequisitionStatus;
use App\Models\HiringRisk;
use App\Models\RecruitmentRequisition;
use App\Services\Intelligence\HiringRiskRadar;
use Illuminate\Support\Facades\Cache;

/**
 * Phase 8.7 (D8.7-026, DQ-87-01): the Risk Radar evaluates every open requisition and resolves a
 * risk only when a complete scan did not detect it. Before 8.7 it scanned the first 200 open
 * requisitions and then resolved every risk it had not seen — closing live risks every hour.
 */
function radarOpenRequisitions(int $count): void
{
    foreach (range(1, $count) as $ignored) {
        lifecycleFixture(fn () => RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Open, 'openings' => 1, 'opening_date' => now()->subDays(5)]));
    }
}

test('with more than 200 open requisitions every risk stays open across hourly scans', function (): void {
    radarOpenRequisitions(205);
    $radar = app(HiringRiskRadar::class);

    $first = $radar->scan();
    $this->travel(7)->hours();
    $second = $radar->scan();

    $lastRequisition = RecruitmentRequisition::query()->max('id');

    expect($first['opened'])->toBe(205)
        ->and($second['resolved'])->toBe(0)
        ->and(HiringRisk::query()->where('type', HiringRiskType::ThinPipeline)->where('status', HiringRiskStatus::Open)->count())->toBe(205)
        ->and(HiringRisk::query()->where('requisition_id', $lastRequisition)->sole()->status)->toBe(HiringRiskStatus::Open);
});

test('a full scan never runs twice at once, and a skipped scan resolves nothing', function (): void {
    radarOpenRequisitions(1);
    $radar = app(HiringRiskRadar::class);
    $radar->scan();
    $this->travel(7)->hours();

    $lock = Cache::lock('hiring-risk-radar:full-scan', 60);
    $lock->get();
    $skipped = $radar->scan();
    $lock->release();

    expect($skipped)->toMatchArray(['skipped' => true, 'resolved' => 0])
        ->and(HiringRisk::query()->where('status', HiringRiskStatus::Open)->count())->toBe(1);
});

test('the refresh command covers every open requisition, not the first 200', function (): void {
    radarOpenRequisitions(203);

    $this->artisan('intelligence:refresh')->expectsOutputToContain('203 requisition(s)')->assertSuccessful();

    expect(HiringRisk::query()->where('status', HiringRiskStatus::Open)->distinct()->count('requisition_id'))->toBe(203);
});
