<?php

use App\Enums\JoiningStatus;
use App\Models\CandidateJoining;
use App\Models\RecruitmentCost;
use App\Services\CostPerHireService;

beforeEach(function (): void {
    $this->service = app(CostPerHireService::class);
    $this->start = now()->startOfMonth();
    $this->end = now()->endOfMonth();
});

test('totalCost sums costs incurred within the period', function (): void {
    RecruitmentCost::factory()->create(['amount' => 10000, 'incurred_on' => now()]);
    RecruitmentCost::factory()->create(['amount' => 5000, 'incurred_on' => now()]);
    RecruitmentCost::factory()->create(['amount' => 99999, 'incurred_on' => now()->subMonths(2)]);

    expect($this->service->totalCost($this->start, $this->end))->toBe(15000.0);
});

test('successfulJoins counts only Joined records within the period', function (): void {
    CandidateJoining::factory()->create(['status' => JoiningStatus::Joined, 'actual_doj' => now()]);
    CandidateJoining::factory()->create(['status' => JoiningStatus::Joined, 'actual_doj' => now()]);
    CandidateJoining::factory()->create(['status' => JoiningStatus::Expected]);
    CandidateJoining::factory()->create(['status' => JoiningStatus::Joined, 'actual_doj' => now()->subMonths(3)]);

    expect($this->service->successfulJoins($this->start, $this->end))->toBe(2);
});

test('costPerHire is null when there were no successful joins', function (): void {
    RecruitmentCost::factory()->create(['amount' => 10000, 'incurred_on' => now()]);

    expect($this->service->costPerHire($this->start, $this->end))->toBeNull();
});

test('costPerHire divides total cost by successful joins', function (): void {
    RecruitmentCost::factory()->create(['amount' => 20000, 'incurred_on' => now()]);
    CandidateJoining::factory()->create(['status' => JoiningStatus::Joined, 'actual_doj' => now()]);
    CandidateJoining::factory()->create(['status' => JoiningStatus::Joined, 'actual_doj' => now()]);

    expect($this->service->costPerHire($this->start, $this->end))->toBe(10000.0);
});
