<?php

use App\Models\CandidateApplication;
use App\Models\Employee;
use App\Models\RecruiterPerformanceSnapshot;
use Illuminate\Support\Carbon;

beforeEach(function (): void {
    $this->recruiter = Employee::factory()->create();
    CandidateApplication::factory()->create(['recruiter_id' => $this->recruiter->id]);
});

test('snapshots the current month for every active recruiter by default', function (): void {
    $this->travelTo(Carbon::parse('2026-09-14 10:00'));

    $this->artisan('performance:snapshot')
        ->expectsOutputToContain('for September 2026')
        ->assertSuccessful();

    $snapshot = RecruiterPerformanceSnapshot::query()->sole();

    expect($snapshot->employee_id)->toBe($this->recruiter->id)
        ->and($snapshot->period_start->toDateString())->toBe('2026-09-01')
        ->and($snapshot->period_end->toDateString())->toBe('2026-09-30');
});

test('also finalizes the previous month when run on the first day of a month', function (): void {
    $this->travelTo(Carbon::parse('2026-10-01 00:30'));

    $this->artisan('performance:snapshot')->assertSuccessful();

    expect(RecruiterPerformanceSnapshot::query()->orderBy('period_start')->get()->map(fn (RecruiterPerformanceSnapshot $s) => $s->period_start->toDateString())->all())
        ->toBe(['2026-09-01', '2026-10-01']);
});

test('snapshots a specific month when the month option is given, without duplicating on re-run', function (): void {
    $this->artisan('performance:snapshot', ['--month' => '2026-02'])->assertSuccessful();
    $this->artisan('performance:snapshot', ['--month' => '2026-02'])->assertSuccessful();

    $snapshot = RecruiterPerformanceSnapshot::query()->sole();

    expect($snapshot->period_start->toDateString())->toBe('2026-02-01')
        ->and($snapshot->period_end->toDateString())->toBe('2026-02-28');
});

test('rejects a malformed month option', function (): void {
    $this->artisan('performance:snapshot', ['--month' => '2026-13'])->assertFailed();

    expect(RecruiterPerformanceSnapshot::query()->count())->toBe(0);
});
