<?php

use App\Enums\TargetMetric;
use App\Models\AuditLog;
use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\RecruiterPerformanceRule;
use App\Models\RecruiterPerformanceSnapshot;
use App\Services\PerformanceEngine;
use Carbon\CarbonImmutable;

/**
 * Phase 8.5 (D48): a finalised month's performance snapshots are frozen; later changes never
 * rewrite them, and only an audited, forced recompute can.
 */
beforeEach(function (): void {
    $this->recruiter = Employee::factory()->create();
    CandidateApplication::factory()->create(['recruiter_id' => $this->recruiter->id]);
    RecruiterPerformanceRule::factory()->create(['metric' => TargetMetric::Calls, 'weightage' => 100, 'effective_from' => now()->subYear()]);
});

test('the first-of-month run finalises and freezes the previous month', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-01 00:30:00', 'Asia/Kolkata'));

    $this->artisan('performance:snapshot')->assertSuccessful();

    $september = RecruiterPerformanceSnapshot::query()->where('employee_id', $this->recruiter->id)->whereDate('period_start', '2026-09-01')->sole();

    expect($september->frozen_at)->not->toBeNull()
        ->and($september->period_end->toDateString())->toBe('2026-09-30');
});

test('a frozen snapshot is never recomputed by a normal run', function (): void {
    $engine = app(PerformanceEngine::class);
    $start = CarbonImmutable::parse('2026-09-01');
    $end = CarbonImmutable::parse('2026-09-30');
    $snapshot = $engine->snapshotFor($this->recruiter, $start, $end);
    $engine->freezePeriod($start, $end);
    $computedAt = $snapshot->fresh()->computed_at;

    $this->travel(1)->hours();
    $engine->snapshotFor($this->recruiter, $start, $end);

    expect($snapshot->fresh()->computed_at->eq($computedAt))->toBeTrue();
});

test('recomputing a frozen month needs --force with a reason, and is audited', function (): void {
    $engine = app(PerformanceEngine::class);
    $engine->snapshotFor($this->recruiter, CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'));
    $engine->freezePeriod(CarbonImmutable::parse('2026-09-01'), CarbonImmutable::parse('2026-09-30'));

    $this->artisan('performance:snapshot', ['--month' => '2026-09', '--force' => true])->assertFailed();

    $this->artisan('performance:snapshot', ['--month' => '2026-09', '--force' => true, '--reason' => 'Target corrected'])->assertSuccessful();

    expect(AuditLog::query()->where('action', 'performance_snapshot_recomputed')->sole()->changes['reason'])->toBe('Target corrected');
});
