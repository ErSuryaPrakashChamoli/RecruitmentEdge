<?php

use App\Enums\RequisitionStatus;
use App\Models\Employee;
use App\Models\RecruitmentRequisition;
use App\Models\User;
use App\Services\RecruitmentAnalyticsService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/**
 * Phase 8.9 (P89-PERF-015, ED-09): the Command Center paints the day's numbers first; every other
 * widget loads afterwards in one bundled request (not one request each), and position health is
 * computed once per request however many widgets read it.
 */
beforeEach(function (): void {
    // Lazy loading must be on: Livewire::withoutLazyLoading() in another test is process-wide.
    Livewire::flushState();
    $this->seed(RolePermissionSeeder::class);

    $this->chro = User::factory()->create(['employee_id' => Employee::factory()->create()->id]);
    $this->chro->assignRole('chro');
});

test('the first paint shows the day\'s numbers and defers every other widget into one bundled load', function (): void {
    $html = actingAs($this->chro)->get('/admin/acme')->assertSuccessful()->getContent();

    preg_match_all('/wire:snapshot="([^"]+)"/', $html, $snapshots);
    $widgets = collect($snapshots[1])
        ->map(fn (string $snapshot): array => json_decode(html_entity_decode($snapshot), true)['memo'])
        ->filter(fn (array $memo): bool => str_starts_with($memo['name'], 'App\\Filament\\Widgets\\'))
        ->mapWithKeys(fn (array $memo): array => [class_basename($memo['name']) => $memo['lazyIsolated'] ?? 'eager']);

    expect($html)->toContain('Open Positions')
        ->not->toContain('Interview Line-up vs Turn-up Trend')
        ->and($widgets->only(['RecruitmentOverviewStats', 'TodaysRecruitmentPulse'])->all())->toBe(['RecruitmentOverviewStats' => 'eager', 'TodaysRecruitmentPulse' => 'eager'])
        ->and($widgets->except(['RecruitmentOverviewStats', 'TodaysRecruitmentPulse'])->unique()->values()->all())->toBe([false])
        ->and($widgets)->toHaveCount(17)
        // Started on page load (x-init), not when scrolled into view (x-intersect): one bundled request.
        ->and(substr_count($html, 'x-init="$wire.__lazyLoad('))->toBe(15);
});

test('position health is computed once per request and afresh in the next one', function (): void {
    RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Open]);
    $analytics = app(RecruitmentAnalyticsService::class);
    $first = $analytics->positionHealth($this->chro);

    DB::enableQueryLog();
    $again = app(RecruitmentAnalyticsService::class)->positionHealth($this->chro);
    $queriesForRepeat = count(DB::getQueryLog());
    DB::disableQueryLog();

    RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Open]);
    app()->forgetScopedInstances();

    expect($queriesForRepeat)->toBe(0)
        ->and($again->pluck('requisition.id')->all())->toBe($first->pluck('requisition.id')->all())
        ->and(app(RecruitmentAnalyticsService::class)->positionHealth($this->chro))->toHaveCount($first->count() + 1);
});
