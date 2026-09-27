<?php

use App\Enums\JoiningStatus;
use App\Enums\OfferStatus;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\Department;
use App\Models\Offer;
use App\Models\RecruitmentRequisition;
use App\Models\User;
use App\Services\AI\Tools\OfferTools\AnalyzeOffersTool;
use App\Services\AI\Tools\RecruitmentTools\TimeToHireTool;
use App\Services\RecruitmentAnalyticsService;
use Database\Seeders\RolePermissionSeeder;

/**
 * Phase 8.4 (D12): the three confirmed metric defects — and nothing else about metric definitions.
 */
beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->chro = User::factory()->create()->assignRole('chro');
});

function metricJoin(int $appliedDaysAgo, ?int $departmentId = null, int $joinedDaysAgo = 0): CandidateJoining
{
    $requisition = RecruitmentRequisition::factory()->create($departmentId !== null ? ['department_id' => $departmentId] : []);
    $application = CandidateApplication::factory()->create(['requisition_id' => $requisition->id, 'application_date' => now()->subDays($appliedDaysAgo)]);

    return lifecycleFixture(fn () => CandidateJoining::factory()->create(['candidate_application_id' => $application->id, 'status' => JoiningStatus::Joined, 'actual_doj' => now()->subDays($joinedDaysAgo)]));
}

test('the Copilot offer acceptance rate is calculated from accepted and rejected offers', function (): void {
    foreach ([OfferStatus::Accepted, OfferStatus::Accepted, OfferStatus::Accepted, OfferStatus::Rejected, OfferStatus::Released] as $status) {
        Offer::factory()->create(['status' => $status, 'offer_date' => now()->subDays(3)]);
    }

    $result = app(AnalyzeOffersTool::class)->handle([], $this->chro);

    expect($result->data['acceptance_rate_pct'])->toBe(75.0);
});

test('the Copilot time-to-hire average honours the department filter like its cost and joins', function (): void {
    $engineering = Department::factory()->create();
    metricJoin(10, $engineering->id);
    metricJoin(40);

    $result = app(TimeToHireTool::class)->handle(['department_id' => $engineering->id, 'end_date' => now()->addDay()->toDateString()], $this->chro);

    expect($result->data['average_time_to_hire_days'])->toBe(10.0)
        ->and($result->data['successful_joins'])->toBe(1);
});

test('the time-to-hire average counts whole days and leaves out impossible negative durations', function (): void {
    metricJoin(10);
    metricJoin(20);
    $invalid = metricJoin(0, joinedDaysAgo: 5);
    lifecycleFixture(fn () => $invalid->candidateApplication->forceFill(['application_date' => now()])->save());

    expect(app(RecruitmentAnalyticsService::class)->averageTimeToHireDays(now()->subMonth(), now()->addDay()))->toBe(15.0);
});
