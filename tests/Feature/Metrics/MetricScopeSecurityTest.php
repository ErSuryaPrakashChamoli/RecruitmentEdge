<?php

use App\Enums\JoiningStatus;
use App\Enums\RequisitionStatus;
use App\Models\Candidate;
use App\Models\CandidateApplication;
use App\Models\CandidateJoining;
use App\Models\CandidateSource;
use App\Models\Employee;
use App\Models\RecruitmentCampaign;
use App\Models\RecruitmentCost;
use App\Models\RecruitmentRequisition;
use App\Models\User;
use App\Services\Metrics\MetricPeriod;
use App\Services\Metrics\MetricQuery;
use App\Services\Metrics\MetricService;
use App\Services\RecruitmentAnalyticsService;
use Database\Seeders\RolePermissionSeeder;

/**
 * Phase 8.5 scope rules (D35, D36, "Scope" decision): one hierarchy model per metric, no filter that
 * reaches outside it, deleted applications never counted, spend scoped like the counts it is set
 * against (SEC-3).
 */
beforeEach(function (): void {
    $this->seed(RolePermissionSeeder::class);
    $this->manager = Employee::factory()->create();
    $this->teamRecruiter = Employee::factory()->reportingTo($this->manager)->create();
    $this->outsider = Employee::factory()->create();
    $this->viewer = User::factory()->create(['employee_id' => $this->manager->id])->assignRole('manager');
});

function scopeSecurityHire(Employee $recruiter): CandidateJoining
{
    return lifecycleFixture(fn () => CandidateJoining::factory()->create([
        'candidate_application_id' => CandidateApplication::factory()->create(['recruiter_id' => $recruiter->id])->id,
        'status' => JoiningStatus::Joined,
        'actual_doj' => now()->toDateString(),
    ]));
}

test('the recruiter filter can never reach a recruiter outside the viewer\'s hierarchy', function (): void {
    scopeSecurityHire($this->outsider);
    scopeSecurityHire($this->teamRecruiter);

    $metrics = app(MetricService::class);
    $period = MetricPeriod::lastDays(7);

    expect($metrics->get('hiring.hires', MetricQuery::make($period, $this->viewer, ['recruiter_id' => $this->outsider->id]))->value)->toBe(0.0)
        ->and($metrics->get('recruiter.outcomes', MetricQuery::make($period, $this->viewer, ['recruiter_id' => $this->outsider->id]))->value)->toBe(0.0)
        ->and($metrics->get('recruiter.activity', MetricQuery::make($period, $this->viewer, ['recruiter_id' => $this->outsider->id]))->detail('people'))->toBe(0)
        ->and($metrics->get('hiring.hires', MetricQuery::make($period, $this->viewer, ['recruiter_id' => $this->teamRecruiter->id]))->value)->toBe(1.0);
});

test('a metric refuses a filter it does not declare instead of silently ignoring it', function (): void {
    app(MetricService::class)->get('requisition.ageing', MetricQuery::make(null, null, ['source_id' => 1]));
})->throws(InvalidArgumentException::class);

test('a deleted application never counts, for a viewer who sees everything too (D35)', function (): void {
    $deleted = scopeSecurityHire($this->teamRecruiter);
    scopeSecurityHire($this->teamRecruiter);
    $deleted->candidateApplication->delete();

    $chro = User::factory()->create()->assignRole('chro');
    $period = MetricPeriod::lastDays(7);

    expect(app(MetricService::class)->get('hiring.hires', MetricQuery::make($period, $chro))->value)->toBe(1.0)
        ->and(app(MetricService::class)->get('hiring.hires', MetricQuery::make($period, $this->viewer))->value)->toBe(1.0);
});

test('a hiring or reporting manager sees the ageing of their requisitions (one requisition scope)', function (): void {
    $asHiringManager = RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Open, 'hiring_manager_id' => $this->manager->id, 'opening_date' => now()->subDays(40)]);
    $asReportingManager = RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Open, 'reporting_manager_id' => $this->manager->id, 'opening_date' => now()->subDays(50)]);
    RecruitmentRequisition::factory()->create(['status' => RequisitionStatus::Open, 'opening_date' => now()->subDays(60)]);

    $ageing = app(RecruitmentAnalyticsService::class)->vacancyAgeing($this->viewer, includeWithinThreshold: true)->pluck('requisition.id');

    expect($ageing->sort()->values()->all())->toBe(collect([$asHiringManager->id, $asReportingManager->id])->sort()->values()->all());
});

test('source spend follows the viewer\'s requisitions; unattached spend only reaches viewers who see everything (SEC-3, D36)', function (): void {
    $source = CandidateSource::factory()->create();
    $team = RecruitmentRequisition::factory()->create(['manager_id' => $this->manager->id]);

    RecruitmentCost::factory()->create(['source_id' => $source->id, 'amount' => 1000, 'incurred_on' => now(), 'requisition_id' => $team->id]);
    RecruitmentCost::factory()->create(['source_id' => $source->id, 'amount' => 70000, 'incurred_on' => now(), 'requisition_id' => RecruitmentRequisition::factory()->create()->id]);
    RecruitmentCost::factory()->create(['source_id' => $source->id, 'amount' => 30000, 'incurred_on' => now()]);

    $scoped = app(RecruitmentAnalyticsService::class)->sourceAnalytics(now()->startOfMonth(), now()->endOfMonth(), $this->viewer)->firstWhere('source.id', $source->id);
    $all = app(RecruitmentAnalyticsService::class)->sourceAnalytics(now()->startOfMonth(), now()->endOfMonth(), User::factory()->create()->assignRole('chro'))->firstWhere('source.id', $source->id);

    expect($scoped['spend'])->toBe(1000.0)
        ->and($all['spend'])->toBe(101000.0);
});

test('campaign spend follows the viewer\'s requisitions (SEC-3, D36)', function (): void {
    $campaign = RecruitmentCampaign::factory()->create();
    $team = RecruitmentRequisition::factory()->create(['manager_id' => $this->manager->id]);

    RecruitmentCost::factory()->create(['campaign_id' => $campaign->id, 'amount' => 2000, 'incurred_on' => now(), 'requisition_id' => $team->id]);
    RecruitmentCost::factory()->create(['campaign_id' => $campaign->id, 'amount' => 40000, 'incurred_on' => now(), 'requisition_id' => RecruitmentRequisition::factory()->create()->id]);

    expect(app(RecruitmentAnalyticsService::class)->campaignAnalytics($campaign, $this->viewer)['spend'])->toBe(2000.0);
});

test('source conversion counts applications on both sides, so it can never exceed 100% (D24)', function (): void {
    $source = CandidateSource::factory()->create();
    $candidate = Candidate::factory()->create(['source_id' => $source->id]);

    // One person, two applications in the period, one join: 1 of 2 applications, not 1 of 1 candidate.
    foreach ([JoiningStatus::Joined, JoiningStatus::Expected] as $status) {
        lifecycleFixture(fn () => CandidateJoining::factory()->create([
            'candidate_application_id' => CandidateApplication::factory()->create(['candidate_id' => $candidate->id, 'application_date' => now()])->id,
            'status' => $status,
            'actual_doj' => $status === JoiningStatus::Joined ? now() : null,
        ]));
    }

    $row = app(RecruitmentAnalyticsService::class)->sourceAnalytics(now()->startOfMonth(), now()->endOfMonth())->firstWhere('source.id', $source->id);

    expect($row['sourced'])->toBe(2)
        ->and($row['joined'])->toBe(1)
        ->and($row['conversion_percent'])->toBe(50.0);
});
